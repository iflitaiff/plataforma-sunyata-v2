"""
FastAPI dependencies for authentication and database access.
"""

import asyncpg
from fastapi import Header, HTTPException

from .config import settings
from .database import get_pool


async def verify_internal_key(x_internal_key: str = Header(...)) -> str:
    """Verify the internal API key from PHP backend."""
    if x_internal_key != settings.internal_api_key:
        raise HTTPException(status_code=403, detail="Invalid internal API key")
    return x_internal_key


async def get_db() -> asyncpg.Pool:
    """Get database connection pool."""
    return await get_pool()
