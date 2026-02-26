"""
Plataforma Sunyata - AI Microservice
FastAPI application for AI operations.

Entry point: uvicorn main:app --host 127.0.0.1 --port 8000
"""

import logging
from contextlib import asynccontextmanager
from datetime import datetime, timezone

from dotenv import load_dotenv
from fastapi import FastAPI
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.errors import RateLimitExceeded
from slowapi.util import get_remote_address

load_dotenv()

from app.config import settings
from app.database import close_pool, get_pool
from app.routers import canvas, datajud, documents, generate, pncp, stream
from app.services import redis_cache

logging.basicConfig(
    level=logging.DEBUG if settings.debug else logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)

limiter = Limiter(key_func=get_remote_address)


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Startup/shutdown lifecycle."""
    logger.info("Starting Sunyata AI Service v0.2.0")
    try:
        await get_pool()
        logger.info("Database pool created")
    except Exception:
        logger.warning("Database pool failed (non-fatal, some features unavailable)")
    yield
    await close_pool()
    await redis_cache.close_redis()
    logger.info("Sunyata AI Service stopped")


app = FastAPI(
    title="Sunyata AI Service",
    version="0.2.0",
    docs_url="/api/ai/docs",
    redoc_url=None,
    lifespan=lifespan,
)

app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)

# Mount routers
app.include_router(generate.router, prefix="/api/ai", tags=["generate"])
app.include_router(stream.router, prefix="/api/ai", tags=["stream"])
app.include_router(canvas.router, prefix="/api/ai", tags=["canvas"])
app.include_router(documents.router, prefix="/api/ai", tags=["documents"])
app.include_router(pncp.router, prefix="/api/ai", tags=["pncp"])
app.include_router(datajud.router, prefix="/api/ai", tags=["datajud"])


@app.get("/api/ai/health")
async def health():
    return {
        "status": "ok",
        "service": "sunyata-ai",
        "version": "0.2.0",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "checks": {
            "anthropic_key": bool(settings.anthropic_api_key),
            "database_url": bool(settings.database_url),
        },
    }
