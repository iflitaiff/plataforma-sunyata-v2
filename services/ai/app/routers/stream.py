"""
POST /api/ai/stream — SSE streaming generation.

The browser connects via EventSource after PHP validates the request
and returns a stream_url. SSE events:
  data: {"type":"token","text":"..."}
  data: {"type":"done","result":{...}}
  data: {"type":"error","error":"..."}
"""

import json
import logging

from fastapi import APIRouter, Depends, Request
from fastapi.responses import StreamingResponse

from ..dependencies import verify_internal_key
from ..models import StreamRequest
from ..services.claude import stream

logger = logging.getLogger(__name__)
router = APIRouter()


@router.post("/stream")
async def api_stream(
    req: StreamRequest,
    request: Request,
    _key: str = Depends(verify_internal_key),
):
    """Stream Claude response as SSE events."""

    async def event_generator():
        try:
            async for event in stream(
                model=req.model,
                system=req.system,
                prompt=req.prompt,
                max_tokens=req.max_tokens,
                temperature=req.temperature,
                top_p=req.top_p,
            ):
                # Check if client disconnected
                if await request.is_disconnected():
                    logger.info("Client disconnected, stopping stream")
                    break

                yield f"data: {json.dumps(event, ensure_ascii=False)}\n\n"

        except Exception as e:
            logger.exception("Stream error")
            error_event = {"type": "error", "error": str(e)}
            yield f"data: {json.dumps(error_event)}\n\n"

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",  # Nginx: disable buffering
        },
    )
