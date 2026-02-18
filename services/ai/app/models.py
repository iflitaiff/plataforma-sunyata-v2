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


# --- Canvas ---

class CanvasSubmitRequest(BaseModel):
    """Canvas form submission request."""

    # Required fields
    vertical: str = Field(..., min_length=1, max_length=50)
    template_id: int = Field(..., ge=1)
    user_id: int = Field(..., ge=1)
    data: dict = Field(..., description="Form data from SurveyJS")

    # Optional fields
    stream: bool = Field(default=False, description="Enable SSE streaming")
    model: str | None = Field(default=None, description="Override model (uses template default if None)")
    max_tokens: int = Field(default=4096, ge=1, le=128000)
    temperature: float | None = Field(default=None, ge=0.0, le=1.0)
    top_p: float | None = Field(default=None, ge=0.0, le=1.0)

    # Context (for multi-step workflows)
    context_id: str | None = Field(default=None, description="Context ID for multi-step")
    step: int = Field(default=1, ge=1, description="Step number in workflow")

    @field_validator("vertical")
    @classmethod
    def validate_vertical(cls, v):
        """Validate vertical slug format."""
        if not v.replace("-", "").replace("_", "").isalnum():
            raise ValueError("vertical must be alphanumeric with hyphens/underscores")
        return v

    @model_validator(mode="after")
    def validate_sampling_params(self):
        """Ensure temperature and top_p don't coexist."""
        if self.temperature is not None and self.top_p is not None:
            logger.warning(
                "Both temperature and top_p provided for canvas submission. "
                "Using temperature (top_p ignored)."
            )
        return self


class CanvasSubmitResponse(BaseModel):
    """Canvas submission response (sync mode)."""

    success: bool
    response: str = ""
    model: str = ""
    usage: TokenUsage = TokenUsage()
    cost_usd: float = 0.0
    response_time_ms: int = 0
    error: str | None = None

    # Canvas-specific metadata
    history_id: int | None = Field(default=None, description="prompt_history record ID")
    stream_url: str | None = Field(default=None, description="SSE stream URL if streaming")

    # Multi-step workflow
    next_step: int | None = Field(default=None, description="Next step in workflow")
    context_id: str | None = Field(default=None, description="Context ID for next step")
    is_final: bool = Field(default=True, description="Is this the final step?")
