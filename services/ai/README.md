# AI Microservice

Python/FastAPI microservice for AI operations.

## Endpoints (planned)

- `POST /api/ai/generate` — Single generation
- `POST /api/ai/stream` — SSE streaming generation
- `POST /api/ai/embed` — Generate embeddings
- `POST /api/ai/search` — Semantic search via pgvector
- `POST /api/ai/process-document` — Document processing
- `GET /api/ai/health` — Health check

## Stack

- Python 3.12
- FastAPI + uvicorn
- anthropic SDK
- asyncpg (PostgreSQL)
- pgvector

## Setup

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
uvicorn main:app --host 127.0.0.1 --port 8000
```
