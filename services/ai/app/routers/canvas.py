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
from ..services import redis_cache

logger = logging.getLogger(__name__)
router = APIRouter()
limiter = Limiter(key_func=get_remote_address)


async def _build_prompt(
    vertical: str,
    template_id: int,
    form_data: dict,
) -> tuple[str, str, dict, str]:
    """Build system and user prompts from template + form data.

    Returns: (system_prompt, user_prompt, api_params_override, template_slug)
    """
    pool = await get_pool()

    try:
        async with pool.acquire() as conn:
            # Get template configuration (Phase 3.5: use junction table)
            template = await conn.fetchrow(
                """
                SELECT ct.slug, ct.system_prompt, ct.form_config, ct.api_params_override
                FROM canvas_templates ct
                INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
                WHERE ct.id = $1 AND cva.vertical_slug = $2 AND ct.is_active = TRUE
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

            # Get API params override (if configured)
            api_params_override = template["api_params_override"]
            if isinstance(api_params_override, str):
                api_params_override = json.loads(api_params_override)
            if not api_params_override:
                api_params_override = {}

            # Get template slug for tool_name
            template_slug = template["slug"]

            return system_prompt, user_prompt, api_params_override, template_slug

    except Exception as e:
        logger.exception("Error building prompt from template")
        raise ValueError(f"Failed to build prompt: {str(e)}")


async def _save_to_history(
    user_id: int,
    vertical: str,
    template_slug: str,
    form_data: dict,
    system_prompt: str,
    generated_prompt: str,
    response: str,
    model: str,
    input_tokens: int,
    output_tokens: int,
    cost_usd: float,
    response_time_ms: int,
    max_tokens: int = None,
    temperature: float = None,
    top_p: float = None,
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
                    max_tokens, temperature, top_p,
                    status, created_at
                )
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18)
                RETURNING id
                """,
                user_id,
                vertical,
                template_slug,  # tool_name (canvas slug, e.g., "iatr-due-diligence-manus-test")
                json.dumps(form_data),  # input_data (JSONB - must be JSON string)
                generated_prompt,  # generated_prompt (TEXT)
                response,  # claude_response
                model,  # claude_model
                input_tokens,  # tokens_input
                output_tokens,  # tokens_output
                input_tokens + output_tokens,  # tokens_total
                cost_usd,
                response_time_ms,
                system_prompt,  # system_prompt_sent
                max_tokens,  # max_tokens used
                temperature,  # temperature used
                top_p,  # top_p used
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
        system_prompt, user_prompt, api_params_override, template_slug = await _build_prompt(
            req.vertical,
            req.template_id,
            req.data,
        )

        # Apply API params override from canvas template (if configured)
        # Priority: request params > template override > defaults
        model = req.model or api_params_override.get("claude_model") or api_params_override.get("model") or "claude-sonnet-4-5"
        max_tokens = req.max_tokens if req.max_tokens != 4096 else api_params_override.get("max_tokens", 4096)
        temperature = req.temperature if req.temperature is not None else api_params_override.get("temperature")
        top_p = req.top_p if req.top_p is not None else api_params_override.get("top_p")

        # === STREAMING MODE ===
        if req.stream:
            # Generate a stream session ID
            stream_id = str(uuid.uuid4())

            # Store context in Redis for stream endpoint to retrieve
            await redis_cache.set_stream_context(
                session_id=stream_id,
                context={
                    "user_id": req.user_id,
                    "vertical": req.vertical,
                    "template_slug": template_slug,
                    "form_data": req.data,
                    "system_prompt": system_prompt,
                    "user_prompt": user_prompt,
                    "model": model,
                    "max_tokens": max_tokens,
                    "temperature": temperature,
                    "top_p": top_p,
                },
                ttl=600,  # 10 minutes
            )

            logger.info(
                "Canvas stream submission: vertical=%s, template=%d, user=%d, session=%s",
                req.vertical,
                req.template_id,
                req.user_id,
                stream_id,
            )

            # Return stream_url for client to connect
            return CanvasSubmitResponse(
                success=True,
                stream_url=f"/api/ai/canvas/stream?session_id={stream_id}",
                is_final=False,
            )

        # === SYNC MODE ===
        logger.info(
            "Canvas sync submission: vertical=%s, template=%d, user=%d",
            req.vertical,
            req.template_id,
            req.user_id,
        )

        # Generate response via LLM (with overrides applied)
        result = await generate(
            model=model,
            system=system_prompt,
            prompt=user_prompt,
            max_tokens=max_tokens,
            temperature=temperature,
            top_p=top_p,
        )

        # Save to prompt_history (with API params used)
        history_id = await _save_to_history(
            user_id=req.user_id,
            vertical=req.vertical,
            template_slug=template_slug,
            form_data=req.data,  # Original form data as JSONB
            system_prompt=system_prompt,  # System prompt used
            generated_prompt=user_prompt,  # Generated user prompt
            response=result["response"],
            model=result["model"],
            input_tokens=result["usage"]["input_tokens"],
            output_tokens=result["usage"]["output_tokens"],
            cost_usd=result["cost_usd"],
            response_time_ms=result["response_time_ms"],
            max_tokens=max_tokens,
            temperature=temperature,
            top_p=top_p,
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

    Client connects to this endpoint after receiving stream_url from /canvas/submit.
    Streams LLM response as SSE events, then saves to prompt_history.
    """

    async def event_generator():
        # Retrieve context from Redis
        context = await redis_cache.get_stream_context(session_id)

        if not context:
            yield 'data: {"type":"error","error":"Session not found or expired"}\n\n'
            return

        try:
            # Extract context
            user_id = context["user_id"]
            vertical = context["vertical"]
            template_slug = context["template_slug"]
            form_data = context["form_data"]
            system_prompt = context["system_prompt"]
            user_prompt = context["user_prompt"]
            model = context["model"]
            max_tokens = context["max_tokens"]
            temperature = context.get("temperature")
            top_p = context.get("top_p")

            # Stream LLM response
            full_result = None
            async for chunk in stream(
                model=model,
                system=system_prompt,
                prompt=user_prompt,
                max_tokens=max_tokens,
                temperature=temperature,
                top_p=top_p,
            ):
                # Yield token chunks
                if chunk["type"] == "token":
                    yield f'data: {json.dumps(chunk)}\n\n'

                # Final chunk with usage/cost
                elif chunk["type"] == "done":
                    full_result = chunk["result"]
                    yield f'data: {json.dumps(chunk)}\n\n'

            # Save to prompt_history
            if full_result:
                history_id = await _save_to_history(
                    user_id=user_id,
                    vertical=vertical,
                    template_slug=template_slug,
                    form_data=form_data,
                    system_prompt=system_prompt,
                    generated_prompt=user_prompt,
                    response=full_result["response"],
                    model=full_result["model"],
                    input_tokens=full_result["usage"]["input_tokens"],
                    output_tokens=full_result["usage"]["output_tokens"],
                    cost_usd=full_result["cost_usd"],
                    response_time_ms=full_result["response_time_ms"],
                    max_tokens=max_tokens,
                    temperature=temperature,
                    top_p=top_p,
                )

                # Send final metadata with history_id
                yield f'data: {json.dumps({"type": "complete", "history_id": history_id})}\n\n'

            # Cleanup Redis context
            await redis_cache.delete_stream_context(session_id)

        except Exception as e:
            logger.exception("Error during streaming")
            yield f'data: {json.dumps({"type": "error", "error": str(e)})}\n\n'
            await redis_cache.delete_stream_context(session_id)

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )
