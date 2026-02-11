"""
Claude API service — sync and streaming via anthropic SDK.
"""

import time
from collections.abc import AsyncGenerator

import anthropic

from ..config import settings

# Pricing per token [input, output] — updated 2026-02
PRICING = {
    "haiku": (0.000001, 0.000005),
    "sonnet": (0.000003, 0.000015),
    "opus": (0.000015, 0.000075),
}


def _detect_family(model: str) -> str:
    m = model.lower()
    if "haiku" in m:
        return "haiku"
    if "opus" in m:
        return "opus"
    return "sonnet"


def calculate_cost(model: str, input_tokens: int, output_tokens: int) -> float:
    rates = PRICING.get(_detect_family(model), PRICING["sonnet"])
    return (input_tokens * rates[0]) + (output_tokens * rates[1])


def _build_params(
    model: str,
    system: str | None,
    prompt: str,
    max_tokens: int,
    temperature: float | None,
    top_p: float | None,
) -> dict:
    """Build Anthropic API parameters respecting temperature/top_p exclusivity."""
    params: dict = {
        "model": model,
        "max_tokens": max_tokens,
        "messages": [{"role": "user", "content": prompt}],
    }
    if system:
        params["system"] = system

    # Claude 4.x: temperature and top_p cannot coexist
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
    client = anthropic.AsyncAnthropic(api_key=settings.anthropic_api_key)
    params = _build_params(model, system, prompt, max_tokens, temperature, top_p)

    start = time.monotonic()
    response = await client.messages.create(**params)
    elapsed_ms = int((time.monotonic() - start) * 1000)

    text = response.content[0].text if response.content else ""
    input_tokens = response.usage.input_tokens
    output_tokens = response.usage.output_tokens

    return {
        "success": True,
        "response": text,
        "model": response.model,
        "usage": {
            "input_tokens": input_tokens,
            "output_tokens": output_tokens,
            "total_tokens": input_tokens + output_tokens,
        },
        "cost_usd": calculate_cost(response.model, input_tokens, output_tokens),
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
    client = anthropic.AsyncAnthropic(api_key=settings.anthropic_api_key)
    params = _build_params(model, system, prompt, max_tokens, temperature, top_p)

    start = time.monotonic()
    input_tokens = 0
    output_tokens = 0
    full_text = ""

    async with client.messages.stream(**params) as stream_resp:
        async for event in stream_resp:
            if event.type == "content_block_delta":
                delta_text = event.delta.text
                full_text += delta_text
                yield {"type": "token", "text": delta_text}

            elif event.type == "message_start":
                if hasattr(event.message, "usage") and event.message.usage:
                    input_tokens = event.message.usage.input_tokens

            elif event.type == "message_delta":
                if hasattr(event, "usage") and event.usage:
                    output_tokens = event.usage.output_tokens

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
