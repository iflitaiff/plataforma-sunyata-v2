"""
POST /api/ai/generate — Synchronous LLM generation via LiteLLM proxy.
"""

import logging

from fastapi import APIRouter, Depends, Request
from slowapi import Limiter
from slowapi.util import get_remote_address

from ..dependencies import verify_internal_key
from ..models import GenerateRequest, GenerateResponse, TokenUsage
from ..services.llm import generate

logger = logging.getLogger(__name__)
router = APIRouter()
limiter = Limiter(key_func=get_remote_address)


@router.post("/generate", response_model=GenerateResponse)
@limiter.limit("100/minute")
async def api_generate(
    request: Request,
    req: GenerateRequest,
    _key: str = Depends(verify_internal_key),
):
    """Generate a response synchronously (JSON). Used by PHP backend."""
    try:
        result = await generate(
            model=req.model,
            system=req.system,
            prompt=req.prompt,
            max_tokens=req.max_tokens,
            temperature=req.temperature,
            top_p=req.top_p,
        )

        return GenerateResponse(
            success=True,
            response=result["response"],
            model=result["model"],
            usage=TokenUsage(**result["usage"]),
            cost_usd=result["cost_usd"],
            response_time_ms=result["response_time_ms"],
        )

    except Exception as e:
        logger.exception("Generation failed")
        return GenerateResponse(
            success=False,
            error="Ocorreu um erro inesperado. Tente novamente.",
        )
