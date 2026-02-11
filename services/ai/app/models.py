"""
Pydantic models for request/response validation.
"""

from pydantic import BaseModel, Field


# --- Generate ---

class GenerateRequest(BaseModel):
    model: str = "claude-haiku-4-5-20251001"
    system: str | None = None
    prompt: str = Field(..., min_length=1)
    max_tokens: int = Field(default=4096, ge=1, le=128000)
    temperature: float | None = Field(default=None, ge=0.0, le=1.0)
    top_p: float | None = Field(default=None, ge=0.0, le=1.0)

    # Metadata for prompt_history (optional, PHP can provide)
    user_id: int | None = None
    vertical: str | None = None
    tool_name: str | None = None


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
    model: str = "claude-haiku-4-5-20251001"
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


# --- Document Processing ---

class DocumentProcessRequest(BaseModel):
    file_path: str | None = None
    file_content_base64: str | None = None
    filename: str = "document"
    mime_type: str = "application/pdf"


class DocumentProcessResponse(BaseModel):
    success: bool
    text: str = ""
    pages: int = 0
    word_count: int = 0
    error: str | None = None
