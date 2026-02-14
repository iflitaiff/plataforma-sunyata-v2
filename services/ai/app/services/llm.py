"""
LLM service — sync and streaming via OpenAI-compatible API (LiteLLM proxy).

Replaces direct Anthropic SDK calls with OpenAI SDK pointed at LiteLLM,
enabling multi-provider support (Anthropic, OpenAI, Google) transparently.
"""

import time
from collections.abc import AsyncGenerator

import httpx
import openai

from ..config import settings

# Pricing per token [input, output] — updated 2026-02
PRICING = {
    # Anthropic
    "haiku": (0.000001, 0.000005),
    "sonnet": (0.000003, 0.000015),
    "opus": (0.000015, 0.000075),
    # OpenAI
    "gpt-4o-mini": (0.00000015, 0.0000006),
    "gpt-4o": (0.0000025, 0.000010),
    "gpt-4.1-mini": (0.0000004, 0.0000016),
    "gpt-4.1-nano": (0.0000001, 0.0000004),
    # Google
    "gemini-2.0-flash": (0.0000001, 0.0000004),
    "gemini-1.5-flash": (0.000000075, 0.0000003),
    "gemini-1.5-pro": (0.00000125, 0.000005),
}


def _detect_family(model: str) -> str:
    """Detect pricing family from model ID."""
    m = model.lower()
    # Anthropic families
    if "haiku" in m:
        return "haiku"
    if "opus" in m:
        return "opus"
    if "sonnet" in m:
        return "sonnet"
    # OpenAI families
    if "gpt-4o-mini" in m:
        return "gpt-4o-mini"
    if "gpt-4.1-nano" in m:
        return "gpt-4.1-nano"
    if "gpt-4.1-mini" in m:
        return "gpt-4.1-mini"
    if "gpt-4o" in m:
        return "gpt-4o"
    # Google families
    if "gemini-2.0-flash" in m:
        return "gemini-2.0-flash"
    if "gemini-1.5-flash" in m:
        return "gemini-1.5-flash"
    if "gemini-1.5-pro" in m:
        return "gemini-1.5-pro"
    # Default fallback (cheapest Claude)
    return "sonnet"


def calculate_cost(model: str, input_tokens: int, output_tokens: int) -> float:
    rates = PRICING.get(_detect_family(model), PRICING["sonnet"])
    return (input_tokens * rates[0]) + (output_tokens * rates[1])


def _get_client() -> openai.AsyncOpenAI:
    """Create an AsyncOpenAI client pointed at LiteLLM proxy."""
    return openai.AsyncOpenAI(
        base_url=settings.litellm_base_url.rstrip("/") + "/v1",
        api_key=settings.litellm_api_key,
        timeout=httpx.Timeout(120.0),  # 120s timeout
    )


def _build_messages(system: str | None, prompt: str) -> list[dict]:
    """Build OpenAI-format messages list.

    Anthropic uses a top-level `system` param; OpenAI uses a system message.
    """
    messages = []
    if system:
        messages.append({"role": "system", "content": system})
    messages.append({"role": "user", "content": prompt})
    return messages


def _build_params(
    model: str,
    system: str | None,
    prompt: str,
    max_tokens: int,
    temperature: float | None,
    top_p: float | None,
) -> dict:
    """Build OpenAI chat completion parameters."""
    params: dict = {
        "model": model,
        "max_tokens": max_tokens,
        "messages": _build_messages(system, prompt),
    }

    # Claude 4.x: temperature and top_p cannot coexist.
    # For OpenAI/Gemini models both CAN coexist, but using the same
    # priority rule (temperature > top_p) is safe for all providers.
    if temperature is not None:
        params["temperature"] = temperature
    elif top_p is not None:
        params["top_p"] = top_p
    else:
        params["temperature"] = settings.default_temperature

    return params


async def generate(
    model: str,
    system: str | None,
    prompt: str,
    max_tokens: int = 4096,
    temperature: float | None = None,
    top_p: float | None = None,
) -> dict:
    """Synchronous (non-streaming) generation. Returns full response."""
    client = _get_client()
    params = _build_params(model, system, prompt, max_tokens, temperature, top_p)

    start = time.monotonic()
    response = await client.chat.completions.create(**params)
    elapsed_ms = int((time.monotonic() - start) * 1000)

    choice = response.choices[0] if response.choices else None
    text = choice.message.content if choice and choice.message else ""
    input_tokens = response.usage.prompt_tokens if response.usage else 0
    output_tokens = response.usage.completion_tokens if response.usage else 0

    return {
        "success": True,
        "response": text or "",
        "model": response.model or model,
        "usage": {
            "input_tokens": input_tokens,
            "output_tokens": output_tokens,
            "total_tokens": input_tokens + output_tokens,
        },
        "cost_usd": calculate_cost(response.model or model, input_tokens, output_tokens),
        "response_time_ms": elapsed_ms,
    }


async def stream(
    model: str,
    system: str | None,
    prompt: str,
    max_tokens: int = 4096,
    temperature: float | None = None,
    top_p: float | None = None,
) -> AsyncGenerator[dict, None]:
    """Streaming generation via SSE. Yields dicts for each event."""
    client = _get_client()
    params = _build_params(model, system, prompt, max_tokens, temperature, top_p)
    params["stream"] = True
    params["stream_options"] = {"include_usage": True}

    start = time.monotonic()
    input_tokens = 0
    output_tokens = 0
    full_text = ""

    response_stream = await client.chat.completions.create(**params)

    async for chunk in response_stream:
        # Extract token deltas
        if chunk.choices:
            delta = chunk.choices[0].delta
            if delta and delta.content:
                full_text += delta.content
                yield {"type": "token", "text": delta.content}

        # Usage info comes in the final chunk (with stream_options.include_usage)
        if chunk.usage:
            input_tokens = chunk.usage.prompt_tokens or 0
            output_tokens = chunk.usage.completion_tokens or 0

    elapsed_ms = int((time.monotonic() - start) * 1000)
    cost = calculate_cost(model, input_tokens, output_tokens)

    yield {
        "type": "done",
        "result": {
            "response": full_text,
            "model": model,
            "usage": {
                "input_tokens": input_tokens,
                "output_tokens": output_tokens,
                "total_tokens": input_tokens + output_tokens,
            },
            "cost_usd": cost,
            "response_time_ms": elapsed_ms,
        },
    }
