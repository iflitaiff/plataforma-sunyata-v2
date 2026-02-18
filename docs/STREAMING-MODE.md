# Canvas Streaming Mode

**Status:** ✅ Implementado (2026-02-18)
**Versão:** FastAPI v0.2.0

---

## Overview

Streaming mode permite receber respostas do LLM em tempo real (chunk-by-chunk) via Server-Sent Events (SSE), melhorando a UX com feedback instantâneo durante gerações longas.

---

## Backend API

### 1. Iniciar Stream

**Endpoint:** `POST /api/ai/canvas/submit`
**Payload:**
```json
{
    "vertical": "iatr",
    "template_id": 10,
    "user_id": 5,
    "data": { "campo1": "valor" },
    "stream": true  // ← Enable streaming
}
```

**Response:**
```json
{
    "success": true,
    "stream_url": "/api/ai/canvas/stream?session_id=550e8400-e29b-41d4-a716-446655440000",
    "is_final": false
}
```

---

### 2. Conectar ao Stream

**Endpoint:** `GET /api/ai/canvas/stream?session_id={id}`
**Headers:** `X-Internal-Key: {key}`
**Type:** `text/event-stream` (SSE)

**Event Stream:**
```javascript
// Token chunks (real-time)
data: {"type":"token","text":"### "}
data: {"type":"token","text":"Análise"}
data: {"type":"token","text":" Jurídica"}

// Final result with metadata
data: {"type":"done","result":{"response":"...", "model":"...", "usage":{...}, "cost_usd":0.05}}

// Save confirmation
data: {"type":"complete","history_id":42}
```

---

## Frontend Integration

### JavaScript (EventSource)

```javascript
const submitBtn = document.getElementById('submitBtn');
const resultContainer = document.getElementById('resultContainer');

submitBtn.addEventListener('click', async () => {
    // 1. Submit with stream=true
    const response = await fetch('/api/ai/canvas/submit', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
            'X-Internal-Key': internalKey
        },
        body: JSON.stringify({
            vertical: 'iatr',
            template_id: templateId,
            user_id: userId,
            data: formData,
            stream: true  // ← Enable streaming
        })
    });

    const result = await response.json();

    if (!result.success) {
        console.error(result.error);
        return;
    }

    // 2. Connect to SSE stream
    const streamUrl = `${baseUrl}${result.stream_url}&X-Internal-Key=${internalKey}`;
    const eventSource = new EventSource(streamUrl);

    let fullText = '';
    let historyId = null;

    eventSource.addEventListener('message', (event) => {
        const data = JSON.parse(event.data);

        if (data.type === 'token') {
            // Append chunk in real-time
            fullText += data.text;
            resultContainer.innerHTML = marked.parse(fullText);
        }
        else if (data.type === 'done') {
            // Show final metadata
            console.log('Usage:', data.result.usage);
            console.log('Cost:', data.result.cost_usd);
        }
        else if (data.type === 'complete') {
            // Save confirmed
            historyId = data.history_id;
            eventSource.close();
            console.log('Saved to history:', historyId);
        }
        else if (data.type === 'error') {
            console.error('Stream error:', data.error);
            eventSource.close();
        }
    });

    eventSource.onerror = (err) => {
        console.error('SSE connection error:', err);
        eventSource.close();
    };
});
```

---

## Arquitetura

```
┌─────────────┐                    ┌──────────────┐
│   Browser   │                    │   FastAPI    │
└──────┬──────┘                    └──────┬───────┘
       │                                  │
       │  POST /canvas/submit (stream=true)
       │──────────────────────────────────>
       │                                  │
       │                           ┌──────▼──────┐
       │                           │    Redis    │
       │                           │ Save context│
       │                           └──────┬──────┘
       │                                  │
       │  {stream_url, session_id}        │
       │<─────────────────────────────────┤
       │                                  │
       │  GET /canvas/stream?session_id   │
       │──────────────────────────────────>
       │                                  │
       │                           ┌──────▼──────┐
       │                           │  Get context│
       │                           │  from Redis │
       │                           └──────┬──────┘
       │                                  │
       │  SSE: {type:token, text:"..."}  │
       │<─────────────────────────────────┤
       │  SSE: {type:token, text:"..."}  │
       │<─────────────────────────────────┤
       │  SSE: {type:done, result:{...}} │
       │<─────────────────────────────────┤
       │                                  │
       │                           ┌──────▼──────┐
       │                           │    Save to  │
       │                           │ prompt_hist │
       │                           └──────┬──────┘
       │                                  │
       │  SSE: {type:complete, history_id}
       │<─────────────────────────────────┤
       │                                  │
       [Connection closed]                │
```

---

## Redis Session Management

**Key Format:** `stream:canvas:{session_id}`
**TTL:** 600 seconds (10 minutes)

**Stored Context:**
```json
{
    "user_id": 5,
    "vertical": "iatr",
    "template_slug": "iatr-due-diligence-manus-test",
    "form_data": {...},
    "system_prompt": "...",
    "user_prompt": "...",
    "model": "claude-sonnet-4-5-20250929",
    "max_tokens": 32000,
    "temperature": 0.25,
    "top_p": null
}
```

**Auto-Cleanup:** Session deleted after stream completes or on error

---

## Error Handling

**Session Expired:**
```json
data: {"type":"error","error":"Session not found or expired"}
```

**LLM Error:**
```json
data: {"type":"error","error":"API rate limit exceeded"}
```

**Frontend should:**
- Close EventSource on error
- Show user-friendly error message
- Fall back to sync mode if streaming fails

---

## Performance

**Advantages:**
- Real-time feedback (UX improvement)
- Lower perceived latency
- Can cancel mid-stream (close connection)

**Overhead:**
- Redis write/read (minimal, <5ms)
- SSE connection (keeps 1 worker busy)

**Recommendation:** Use streaming for long generations (>10s expected). Use sync mode for quick responses.

---

## Testing

```bash
# Test sync mode
curl -X POST http://127.0.0.1:8000/api/ai/canvas/submit \
  -H 'Content-Type: application/json' \
  -H 'X-Internal-Key: dev-key' \
  -d '{"vertical":"iatr","template_id":1,"user_id":1,"data":{},"stream":false}'

# Test streaming mode (get stream_url)
curl -X POST http://127.0.0.1:8000/api/ai/canvas/submit \
  -H 'Content-Type: application/json' \
  -H 'X-Internal-Key: dev-key' \
  -d '{"vertical":"iatr","template_id":1,"user_id":1,"data":{},"stream":true}'

# Connect to stream (replace session_id)
curl -N http://127.0.0.1:8000/api/ai/canvas/stream?session_id=XXX \
  -H 'X-Internal-Key: dev-key'
```

---

**Implemented:** 2026-02-18
**Author:** Claude (FastAPI backend)
**Next:** Frontend integration (delegated to Copilot)
