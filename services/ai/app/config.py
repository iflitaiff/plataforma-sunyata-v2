"""
Application configuration loaded from environment variables.
"""

import os
import secrets

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    # Anthropic (kept for fallback if LiteLLM is unavailable)
    anthropic_api_key: str = ""

    # LiteLLM proxy (multi-provider gateway)
    litellm_base_url: str = "http://192.168.100.12:4000"
    litellm_api_key: str = ""

    # Database
    database_url: str = "postgresql://sunyata_app:password@localhost:5432/sunyata_platform"

    # Redis
    redis_url: str = "redis://localhost:6379/0"

    # Service
    host: str = "127.0.0.1"
    port: int = 8000
    debug: bool = False

    # Internal auth (PHP -> FastAPI)
    internal_api_key: str = ""

    # Streaming defaults
    stream_timeout: int = 300  # 5 minutes max for SSE connections
    default_model: str = "claude-haiku-4-5"
    default_max_tokens: int = 4096
    default_temperature: float = 1.0

    # Document processing
    max_upload_size_mb: int = 20
    allowed_mime_types: list[str] = [
        "application/pdf",
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "text/plain",
        "text/markdown",
    ]

    model_config = {"env_file": ".env", "env_file_encoding": "utf-8"}


settings = Settings()

# Generate internal key if not set (for development)
if not settings.internal_api_key:
    settings.internal_api_key = os.getenv("INTERNAL_API_KEY", secrets.token_hex(32))
