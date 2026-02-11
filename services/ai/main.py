"""
Plataforma Sunyata - AI Microservice
FastAPI application for AI operations.
"""

import os
from datetime import datetime, timezone

from dotenv import load_dotenv
from fastapi import FastAPI

load_dotenv()

app = FastAPI(
    title="Sunyata AI Service",
    version="0.1.0",
    docs_url="/api/ai/docs",
    redoc_url=None,
)


@app.get("/api/ai/health")
async def health():
    return {
        "status": "ok",
        "service": "sunyata-ai",
        "version": "0.1.0",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "checks": {
            "anthropic_key": bool(os.getenv("ANTHROPIC_API_KEY")),
            "database_url": bool(os.getenv("DATABASE_URL")),
        },
    }
