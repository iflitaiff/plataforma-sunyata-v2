"""
Redis cache for streaming sessions.

Stores temporary context for canvas streaming submissions.
"""

import json
from typing import Any

import redis.asyncio as redis

from ..config import settings

# Global Redis client (lazy init)
_redis_client: redis.Redis | None = None


async def get_redis() -> redis.Redis:
    """Get or create Redis client."""
    global _redis_client
    if _redis_client is None:
        _redis_client = redis.from_url(
            settings.redis_url,
            encoding="utf-8",
            decode_responses=True,
        )
    return _redis_client


async def set_stream_context(
    session_id: str,
    context: dict[str, Any],
    ttl: int = 600,  # 10 minutes default
) -> None:
    """Store streaming context in Redis.

    Args:
        session_id: Unique session identifier
        context: Context data (will be JSON-encoded)
        ttl: Time to live in seconds
    """
    client = await get_redis()
    key = f"stream:canvas:{session_id}"
    value = json.dumps(context)
    await client.setex(key, ttl, value)


async def get_stream_context(session_id: str) -> dict[str, Any] | None:
    """Retrieve streaming context from Redis.

    Returns:
        Context dict if found, None if expired or not found
    """
    client = await get_redis()
    key = f"stream:canvas:{session_id}"
    value = await client.get(key)

    if value is None:
        return None

    return json.loads(value)


async def delete_stream_context(session_id: str) -> None:
    """Delete streaming context (cleanup after completion)."""
    client = await get_redis()
    key = f"stream:canvas:{session_id}"
    await client.delete(key)


async def close_redis() -> None:
    """Close Redis connection (on shutdown)."""
    global _redis_client
    if _redis_client:
        await _redis_client.aclose()
        _redis_client = None
