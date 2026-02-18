---
de: Claude
para: Filipe
data: 2026-02-18 18:00
assunto: 🚀 Phase 3 Kickoff Plan - Execução Hoje
acao: Plano de Implementação
prioridade: CRÍTICA
---

# 🚀 PHASE 3: Canvas + FastAPI - Kickoff Plan

**Decisão:** Iniciar Phase 3 HOJE (Quarta 2026-02-18)
**Estratégia:** Implementação centralizada (Claude only até segunda ordem)

---

## 📊 ANÁLISE DO ESTADO ATUAL

### ✅ O QUE JÁ EXISTE

**FastAPI Microservice (80% pronto):**
```
services/ai/
├── main.py                    ✅ App FastAPI v0.2.0
├── app/routers/
│   ├── generate.py            ✅ POST /api/ai/generate (sync JSON)
│   ├── stream.py              ✅ POST /api/ai/stream (SSE)
│   ├── documents.py           ✅ Document processing
│   └── pncp.py                ✅ PNCP monitoring
├── app/services/
│   ├── llm.py                 ✅ LiteLLM abstraction
│   └── claude.py              ✅ Claude integration
├── app/models.py              ✅ Pydantic models
└── app/database.py            ✅ PostgreSQL pool
```

**Service Status VM100:**
- ✅ Active (running) port 8000, 2 workers
- ✅ Memory: 140MB (healthy)
- ✅ Rate limiting: 100/min
- ✅ Auth: Internal key verification

**Canvas Forms (4 verticais):**
```
app/public/areas/
├── iatr/formulario.php         ⚠️ Usa PHP backend atual
├── legal/formulario.php        ⚠️ Usa PHP backend atual
├── licitacoes/formulario.php   ⚠️ Usa PHP backend atual
└── nicolay-advogados/formulario.php  ⚠️ Usa PHP backend atual
```

---

## 🎯 O QUE FALTA (Phase 3 Gap)

### 1. Canvas Router (Backend)

**Criar:** `services/ai/app/routers/canvas.py`

**Endpoints necessários:**
```python
POST /api/ai/canvas/submit
  - Recebe form data do SurveyJS
  - Processa via LLM (streaming ou sync)
  - Salva em prompt_history
  - Retorna resultado ou stream_url

POST /api/ai/canvas/stream
  - SSE streaming específico para canvas
  - Context-aware (vertical, template, user)
  - Multi-step workflow support

GET /api/ai/canvas/history/{user_id}
  - Histórico de submissions
  - Paginação, filtros
```

**Features:**
- Multi-step workflow orchestration
- Context management entre steps
- Integration com `canvas_templates`, `prompt_history`, `form_drafts`
- CSRF validation
- Rate limiting específico (10/min por usuário)

---

### 2. Adaptar Formulários (Frontend)

**Para cada formulário (4x):**

**Antes (atual):**
```javascript
fetch('/api/canvas/submit.php', {
  method: 'POST',
  body: JSON.stringify(formData)
})
```

**Depois (Phase 3):**
```javascript
// Option A: Sync (mantém UX atual)
fetch('/api/ai/canvas/submit', {
  method: 'POST',
  headers: {'X-Internal-Key': '...'},
  body: JSON.stringify(formData)
})

// Option B: Streaming (melhor UX)
const eventSource = new EventSource('/api/ai/canvas/stream?id=' + requestId);
eventSource.onmessage = (e) => {
  const data = JSON.parse(e.data);
  if (data.type === 'token') appendText(data.text);
  if (data.type === 'done') finalizeUI(data.result);
};
```

**UI Changes:**
- Progress indicator para streaming
- Token-by-token rendering (SSE)
- Melhor error handling
- Multi-step UI para workflows complexos

---

### 3. Tests (T1-T3)

**Atualmente:**
```
❌ T1: Form Submission (404 - esperado)
✅ T2: Error Handling (8.8s - unexpected pass!)
❌ T3: Response Time (404 - esperado)
```

**Após Phase 3:**
```
✅ T1: Form Submission via /api/ai/canvas/submit
✅ T2: Error handling com FastAPI errors
✅ T3: Response time < 10s
```

---

## 📋 PLANO DE EXECUÇÃO (Quarta 18:00 - 23:59)

### STEP 1: Canvas Router (2-3h) ⏰ 18:00-21:00

**Criar:** `services/ai/app/routers/canvas.py`

**Tarefas:**
1. ✅ Model definitions (CanvasSubmitRequest, CanvasResponse)
2. ✅ POST /canvas/submit endpoint (sync)
3. ✅ POST /canvas/stream endpoint (SSE)
4. ✅ Integration com services/llm.py
5. ✅ Database operations (save to prompt_history)
6. ✅ CSRF validation
7. ✅ Rate limiting (10/min/user)
8. ✅ Error handling
9. ✅ Mount router em main.py

**Deliverable:** Endpoint funcional testável via curl

---

### STEP 2: Deploy + Test Backend (30min) ⏰ 21:00-21:30

**Deploy:**
```bash
cd /var/www/sunyata/app && git pull
cd /var/www/sunyata/services/ai && git pull
sudo systemctl restart sunyata-ai.service
```

**Test:**
```bash
curl -X POST http://localhost:8000/api/ai/canvas/submit \
  -H "X-Internal-Key: ..." \
  -d '{"vertical":"iatr","template_id":1,"data":{...}}'
```

**Validation:**
- ✅ Endpoint responds 200
- ✅ LLM generates response
- ✅ Database saves to prompt_history
- ✅ SSE streaming works

---

### STEP 3: Adaptar 1 Formulário (Pilot) (1-2h) ⏰ 21:30-23:00

**Target:** `areas/iatr/formulario.php` (pilot vertical)

**Changes:**
1. Update JavaScript fetch URL
2. Add SSE streaming logic
3. Update UI for progress indicator
4. Error handling
5. CSRF token handling

**Test:**
- ✅ Form submission works
- ✅ Streaming renders correctly
- ✅ Error handling graceful
- ✅ T1 test passes

---

### STEP 4: Validation + EOD Report (30min) ⏰ 23:00-23:30

**Run Tests:**
```bash
pytest tests/test_canvas_endpoints.py
python3 .claude/skills/webapp-testing/test_canvas_flow.py
```

**Validation:**
- ✅ T1: Form Submission (PASS)
- ✅ Backend logs clean
- ✅ No regressions

**EOD Report:**
- Phase 3 progress: % complete
- Blockers (if any)
- Next steps (Quinta)

---

## 🎯 SUCCESS CRITERIA (EOD Quarta)

| Critério | Target | Status |
|----------|--------|--------|
| Canvas router implementado | ✅ | ⏳ Pending |
| Endpoints testáveis | ✅ | ⏳ Pending |
| 1 formulário adaptado (pilot) | iatr | ⏳ Pending |
| T1 test passing | ✅ | ⏳ Pending |
| Zero regressions | ✅ | ⏳ Pending |
| Deployado em staging | ✅ | ⏳ Pending |

**Minimum Viable (tonight):** Canvas router + 1 formulário funcionando

---

## 📅 ROADMAP AJUSTADO

### Quarta (HOJE - 18:00-23:59)
- ✅ Canvas router backend
- ✅ Deploy + test
- ✅ 1 formulário adaptado (iatr)
- ✅ T1 passing

### Quinta (2026-02-19)
- ✅ Adaptar 3 formulários restantes
- ✅ Multi-step workflows
- ✅ T2-T3 passing
- ✅ E2E validation completa

### Sexta (2026-02-21)
- ✅ Polish + bug fixes
- ✅ Phase 3 COMPLETE
- 🎯 GO/NO-GO (Phase 3 + 2.5 + 2)
- 🚀 Deploy to production

---

## 🔧 TECHNICAL DECISIONS

### Streaming vs Sync

**Decisão:** Implementar AMBOS

- `POST /canvas/submit` - sync (fallback, simples)
- `POST /canvas/stream` - SSE (melhor UX, default)

**Razão:** Browsers antigos/proxies podem não suportar SSE

---

### Context Management

**Multi-step workflows:**
```python
# Step 1: User submits initial form
POST /canvas/submit {"step": 1, "data": {...}}
→ Returns: {"next_step": 2, "context_id": "abc123"}

# Step 2: User submits follow-up
POST /canvas/submit {"step": 2, "context_id": "abc123", "data": {...}}
→ Returns: {"final": true, "result": {...}}
```

**Context storage:** Redis (TTL 1h)

---

### Rate Limiting

**Canvas-specific:**
```python
@limiter.limit("10/minute")  # 10 submissions/min/user
@limiter.limit("50/hour")    # 50 submissions/hour/user
```

**Razão:** Canvas submissions são mais custosas (LLM calls)

---

## 🚨 RISKS & MITIGATION

| Risk | Impact | Mitigation |
|------|--------|------------|
| LiteLLM timeout | MEDIUM | Async + 30s timeout + retry logic |
| SSE browser support | LOW | Fallback para sync endpoint |
| Multi-step complexity | HIGH | Start simple, iterate Thursday |
| CSRF integration | MEDIUM | Reuse existing PHP CSRF tokens |

---

## 📊 MONITORING

**Metrics to track:**
- Canvas submission latency (p50, p95, p99)
- LLM API errors rate
- SSE connection drops
- Database write failures
- Rate limit hits

**Logs:**
```bash
# FastAPI logs
sudo journalctl -u sunyata-ai.service -f

# Nginx access (canvas requests)
sudo tail -f /var/log/nginx/access.log | grep '/api/ai/canvas'

# PHP errors (frontend)
tail -f /var/www/sunyata/app/logs/php_errors.log
```

---

## ✅ CHECKPOINTS

**18:00** - Kickoff (este doc)
**19:00** - Models + submit endpoint implementados
**20:00** - Stream endpoint + database integration
**21:00** - Router completo, deploy iniciado
**21:30** - Backend validado, frontend pilot start
**23:00** - IATR formulário funcionando
**23:30** - EOD report + GO/NO-GO Phase 3 partial

---

## 🎯 NEXT ACTIONS (IMMEDIATE)

1. ✅ Criar `services/ai/app/models.py` additions (CanvasSubmitRequest)
2. ✅ Criar `services/ai/app/routers/canvas.py`
3. ✅ Implementar POST /canvas/submit
4. ✅ Implementar POST /canvas/stream
5. ✅ Mount router em main.py
6. ✅ Local test
7. ✅ Deploy
8. ✅ Adaptar iatr/formulario.php

---

**Claude - Executor Principal** 🔵
**Phase 3 Kickoff:** ✅ READY TO START
**Timeline:** 18:00-23:59 (Quarta)
**Target:** Canvas router + 1 formulário funcionando
