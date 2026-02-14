"""
Pydantic models for request/response validation.
"""

import logging

from pydantic import BaseModel, Field, field_validator, model_validator

logger = logging.getLogger(__name__)


# --- Generate ---

class GenerateRequest(BaseModel):
    model: str = "claude-haiku-4-5"
    system: str | None = None
    prompt: str = Field(..., min_length=1)
    max_tokens: int = Field(default=4096, ge=1, le=128000)
    temperature: float | None = Field(default=None, ge=0.0, le=1.0)
    top_p: float | None = Field(default=None, ge=0.0, le=1.0)

    # Metadata for prompt_history (optional, PHP can provide)
    user_id: int | None = None
    vertical: str | None = None
    tool_name: str | None = None

    @field_validator("temperature")
    @classmethod
    def validate_temperature(cls, v):
        if v is not None and not (0.0 <= v <= 1.0):
            raise ValueError("temperature must be between 0.0 and 1.0")
        return v

    @field_validator("top_p")
    @classmethod
    def validate_top_p(cls, v):
        if v is not None and not (0.0 <= v <= 1.0):
            raise ValueError("top_p must be between 0.0 and 1.0")
        return v

    @model_validator(mode="after")
    def validate_sampling_params(self):
        if self.temperature is not None and self.top_p is not None:
            logger.warning(
                "Both temperature (%s) and top_p (%s) provided. "
                "Using temperature (top_p will be ignored per Claude 4.x rules).",
                self.temperature,
                self.top_p,
            )
        return self


class TokenUsage(BaseModel):
    input_tokens: int = 0
    output_tokens: int = 0
    total_tokens: int = 0


class GenerateResponse(BaseModel):
    success: bool
    response: str = ""
    model: str = ""
    usage: TokenUsage = TokenUsage()
    cost_usd: float = 0.0
    response_time_ms: int = 0
    error: str | None = None


# --- Stream ---

class StreamRequest(BaseModel):
    model: str = "claude-haiku-4-5"
    system: str | None = None
    prompt: str = Field(..., min_length=1)
    max_tokens: int = Field(default=4096, ge=1, le=128000)
    temperature: float | None = Field(default=None, ge=0.0, le=1.0)
    top_p: float | None = Field(default=None, ge=0.0, le=1.0)

    # Metadata
    user_id: int | None = None
    vertical: str | None = None
    tool_name: str | None = None
    session_id: str | None = None  # For SSE reconnection

    @field_validator("temperature")
    @classmethod
    def validate_temperature(cls, v):
        if v is not None and not (0.0 <= v <= 1.0):
            raise ValueError("temperature must be between 0.0 and 1.0")
        return v

    @field_validator("top_p")
    @classmethod
    def validate_top_p(cls, v):
        if v is not None and not (0.0 <= v <= 1.0):
            raise ValueError("top_p must be between 0.0 and 1.0")
        return v

    @model_validator(mode="after")
    def validate_sampling_params(self):
        if self.temperature is not None and self.top_p is not None:
            logger.warning(
                "Both temperature (%s) and top_p (%s) provided. "
                "Using temperature (top_p ignored).",
                self.temperature,
                self.top_p,
            )
        return self


# --- Document Processing ---

class DocumentProcessRequest(BaseModel):
    file_content_base64: str = Field(..., min_length=1)
    filename: str = "document"
    mime_type: str = "application/pdf"


class DocumentProcessResponse(BaseModel):
    success: bool
    text: str = ""
    pages: int = 0
    word_count: int = 0
    error: str | None = None
