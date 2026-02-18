"""
POST /api/ai/canvas/submit — Canvas form submission with sync or streaming mode.

Handles form submissions from SurveyJS canvas forms, processes via LLM,
and saves to prompt_history.
"""

import json
import logging
import uuid
from datetime import datetime, timezone

from fastapi import APIRouter, Depends, Request
from fastapi.responses import StreamingResponse
from slowapi import Limiter
from slowapi.util import get_remote_address

from ..config import settings
from ..database import get_pool
from ..dependencies import verify_internal_key
from ..models import CanvasSubmitRequest, CanvasSubmitResponse, TokenUsage
from ..services.llm import generate, stream

logger = logging.getLogger(__name__)
router = APIRouter()
limiter = Limiter(key_func=get_remote_address)


async def _build_prompt(
    vertical: str,
    template_id: int,
    form_data: dict,
) -> tuple[str, str]:
    """Build system and user prompts from template + form data.

    Returns: (system_prompt, user_prompt)
    """
    pool = await get_pool()

    try:
        async with pool.acquire() as conn:
            # Get template configuration
            template = await conn.fetchrow(
                """
                SELECT system_prompt, form_config
                FROM canvas_templates
                WHERE id = $1 AND vertical = $2
                """,
                template_id,
                vertical,
            )

            if not template:
                raise ValueError(f"Template {template_id} not found for vertical {vertical}")

            # Extract system prompt (4-level hierarchy handled by PHP, we use what's provided)
            system_prompt = template["system_prompt"] or "You are a helpful AI assistant."

            # Extract form config (SurveyJS JSON)
            form_config = template["form_config"]
            if isinstance(form_config, str):
                form_config = json.loads(form_config)

            # Build user prompt from form data
            # Simple approach: list all Q&A pairs
            prompt_parts = ["Please generate a response based on the following information:\n"]

            for key, value in form_data.items():
                if key.startswith("aj"):  # Skip SurveyJS internal fields
                    continue
                if value:  # Only include non-empty values
                    # Clean key for display
                    clean_key = key.replace("_", " ").title()
                    prompt_parts.append(f"**{clean_key}:** {value}")

            user_prompt = "\n".join(prompt_parts)

            return system_prompt, user_prompt

    except Exception as e:
        logger.exception("Error building prompt from template")
        raise ValueError(f"Failed to build prompt: {str(e)}")


async def _save_to_history(
    user_id: int,
    vertical: str,
    template_id: int,
    form_data: dict,
    system_prompt: str,
    generated_prompt: str,
    response: str,
    model: str,
    input_tokens: int,
    output_tokens: int,
    cost_usd: float,
    response_time_ms: int,
) -> int:
    """Save submission to prompt_history table.

    Returns: history_id
    """
    pool = await get_pool()

    try:
        async with pool.acquire() as conn:
            result = await conn.fetchrow(
                """
                INSERT INTO prompt_history (
                    user_id, vertical, tool_name, input_data, generated_prompt,
                    claude_response, claude_model, tokens_input, tokens_output,
                    tokens_total, cost_usd, response_time_ms, system_prompt_sent,
                    status, created_at
                )
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)
                RETURNING id
                """,
                user_id,
                vertical,
                f"canvas_template_{template_id}",  # tool_name
                form_data,  # input_data (JSONB)
                generated_prompt,  # generated_prompt (TEXT)
                response,  # claude_response
                model,  # claude_model
                input_tokens,  # tokens_input
                output_tokens,  # tokens_output
                input_tokens + output_tokens,  # tokens_total
                cost_usd,
                response_time_ms,
                system_prompt,  # system_prompt_sent
                "success",  # status
                datetime.now(timezone.utc),
            )

            return result["id"]

    except Exception as e:
        logger.exception("Error saving to prompt_history")
        # Non-fatal: return 0 if save fails (don't block user response)
        return 0


@router.post("/canvas/submit", response_model=CanvasSubmitResponse)
@limiter.limit("10/minute")  # Canvas-specific rate limit
async def canvas_submit(
    request: Request,
    req: CanvasSubmitRequest,
    _key: str = Depends(verify_internal_key),
):
    """Submit canvas form for processing.

    Modes:
      - stream=false (default): Returns complete response as JSON
      - stream=true: Returns stream_url for SSE streaming
    """

    try:
        # Build prompts from template + form data
        system_prompt, user_prompt = await _build_prompt(
            req.vertical,
            req.template_id,
            req.data,
        )

        # Determine model (use override or template default or service default)
        model = req.model or "claude-sonnet-4-5"  # TODO: Get from template config

        # === STREAMING MODE ===
        if req.stream:
            # Generate a stream session ID
            stream_id = str(uuid.uuid4())

            # Store context in Redis/cache (TODO: implement caching)
            # For now, return a stream_url that embeds the parameters

            logger.info(
                "Canvas stream submission: vertical=%s, template=%d, user=%d",
                req.vertical,
                req.template_id,
                req.user_id,
            )

            # Return stream_url for client to connect
            return CanvasSubmitResponse(
                success=True,
                stream_url=f"/api/ai/canvas/stream?session_id={stream_id}",
                error="Streaming not yet implemented - use stream=false for now",
            )

        # === SYNC MODE ===
        logger.info(
            "Canvas sync submission: vertical=%s, template=%d, user=%d",
            req.vertical,
            req.template_id,
            req.user_id,
        )

        # Generate response via LLM
        result = await generate(
            model=model,
            system=system_prompt,
            prompt=user_prompt,
            max_tokens=req.max_tokens,
            temperature=req.temperature,
            top_p=req.top_p,
        )

        # Save to prompt_history
        history_id = await _save_to_history(
            user_id=req.user_id,
            vertical=req.vertical,
            template_id=req.template_id,
            form_data=req.data,  # Original form data as JSONB
            system_prompt=system_prompt,  # System prompt used
            generated_prompt=user_prompt,  # Generated user prompt
            response=result["response"],
            model=result["model"],
            input_tokens=result["usage"]["input_tokens"],
            output_tokens=result["usage"]["output_tokens"],
            cost_usd=result["cost_usd"],
            response_time_ms=result["response_time_ms"],
        )

        # Return response
        return CanvasSubmitResponse(
            success=True,
            response=result["response"],
            model=result["model"],
            usage=TokenUsage(**result["usage"]),
            cost_usd=result["cost_usd"],
            response_time_ms=result["response_time_ms"],
            history_id=history_id if history_id > 0 else None,
        )

    except ValueError as e:
        logger.warning("Canvas validation error: %s", str(e))
        return CanvasSubmitResponse(
            success=False,
            error=str(e),
        )

    except Exception as e:
        logger.exception("Canvas submission failed")
        return CanvasSubmitResponse(
            success=False,
            error="Ocorreu um erro inesperado. Tente novamente.",
        )


@router.get("/canvas/stream")
async def canvas_stream(session_id: str, _key: str = Depends(verify_internal_key)):
    """SSE stream endpoint for canvas submissions.

    TODO: Implement streaming mode
    - Retrieve context from Redis/cache using session_id
    - Stream LLM response via SSE
    - Save to prompt_history when done
    """

    async def event_generator():
        yield 'data: {"type":"error","error":"Streaming not yet implemented"}\n\n'

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )
