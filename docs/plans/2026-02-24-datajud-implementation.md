# DataJud Integration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Integrate CNJ DataJud public API to enrich edital analysis with judicial process data (3 features).

**Architecture:** FastAPI centralized endpoints query DataJud Elasticsearch API, cache results in PostgreSQL JSONB. Portal PHP proxies serve same-origin requests. N8N workflow reads cached data to enrich AI analysis prompts.

**Tech Stack:** Python/FastAPI (httpx async), PostgreSQL 16 (JSONB), PHP 8.3 (proxy), JavaScript (frontend), N8N (workflow modification)

**Design doc:** `docs/plans/2026-02-24-datajud-integration-design.md`

---

### Task 1: Database Migration 015

**Files:**
- Create: `/tmp/migration-015-datajud.sql`
- Modify: `docs/DATABASE.md`

**Step 1: Write migration SQL**

```sql
-- Migration 015: DataJud integration columns and table
-- Date: 2026-02-24

BEGIN;

-- New columns on pncp_editais for caching DataJud results (Feature 1 and 2)
ALTER TABLE pncp_editais ADD COLUMN IF NOT EXISTS datajud_orgao JSONB;
ALTER TABLE pncp_editais ADD COLUMN IF NOT EXISTS datajud_consultado_em TIMESTAMPTZ;

-- New table for ad-hoc CNPJ consultations (Feature 3)
CREATE TABLE IF NOT EXISTS datajud_consultas (
    id SERIAL PRIMARY KEY,
    cnpj TEXT NOT NULL,
    user_id INTEGER REFERENCES users(id),
    edital_id INTEGER REFERENCES pncp_editais(id),
    resultado JSONB NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_datajud_consultas_cnpj ON datajud_consultas(cnpj);
CREATE INDEX IF NOT EXISTS idx_datajud_consultas_user ON datajud_consultas(user_id);
CREATE INDEX IF NOT EXISTS idx_datajud_consultas_edital ON datajud_consultas(edital_id);

-- Grant permissions
GRANT SELECT, INSERT, UPDATE ON datajud_consultas TO sunyata_app;
GRANT USAGE, SELECT ON SEQUENCE datajud_consultas_id_seq TO sunyata_app;
GRANT SELECT ON datajud_consultas TO n8n_worker;

COMMIT;
```

**Step 2: Execute migration on VM100**

Run: `tools/ssh-cmd.sh vm100 -f /tmp/migration-015-datajud.sql`
Expected: ALTER TABLE, CREATE TABLE, CREATE INDEX, GRANT confirmations

**Step 3: Update DATABASE.md**

Add the 2 new columns to pncp_editais section and the new datajud_consultas table.

**Step 4: Commit**

```
git add docs/DATABASE.md
git commit -m "feat(datajud): migration 015 - datajud columns and consultas table"
```

---

### Task 2: FastAPI DataJud Router — Core Module

**Files:**
- Create: `services/ai/app/routers/datajud.py`
- Modify: `services/ai/main.py` (add router include)

**Step 1: Create the datajud router**

File: `services/ai/app/routers/datajud.py`

This module contains:
- Constants: DATAJUD_BASE_URL, API_KEY, timeouts, class codes, UF-to-tribunal mapping
- Models: OrgaoProcessosRequest, EmpresaIdoneidadeRequest
- Helpers: _extract_cnpj_from_pncp_id, _clean_cnpj, _get_tribunais_for_uf, _build_datajud_query, _query_tribunal, _query_datajud_parallel, _build_resumo, _build_alertas
- Endpoints: POST /datajud/orgao-processos, POST /datajud/empresa-idoneidade

Key implementation details:

**Constants and mappings:**

```python
DATAJUD_BASE_URL = "https://api-publica.datajud.cnj.jus.br"
DATAJUD_API_KEY = "cDZHYzlZa0JadVREZDJCendQbXY6SkJlTzNjLV9TRENyQk1RdnFKZGRQdw=="
DATAJUD_TIMEOUT = 15  # seconds per tribunal
CACHE_HOURS = 24
MAX_PROCESSOS_PER_TRIBUNAL = 20
MAX_PROCESSOS_TOTAL = 100

CLASSES_ORGAO = {1116, 1117, 12078, 65, 12135}
CLASSES_EMPRESA = {1037, 1049, 1116, 1117}
CLASSES_CRITICAS = {1037, 1049}  # Impedem habilitacao
CLASSES_ATENCAO = {1116, 1117}   # Risco fiscal

UF_TRIBUNAIS = {
    "AC": ["TJAC", "TRF1", "TRT14"],
    "AL": ["TJAL", "TRF5", "TRT19"],
    "AM": ["TJAM", "TRF1", "TRT11"],
    "AP": ["TJAP", "TRF1", "TRT8"],
    "BA": ["TJBA", "TRF1", "TRT5"],
    "CE": ["TJCE", "TRF5", "TRT7"],
    "DF": ["TJDFT", "TRF1", "TRT10"],
    "ES": ["TJES", "TRF2", "TRT17"],
    "GO": ["TJGO", "TRF1", "TRT18"],
    "MA": ["TJMA", "TRF1", "TRT16"],
    "MG": ["TJMG", "TRF1", "TRT3"],
    "MS": ["TJMS", "TRF3", "TRT24"],
    "MT": ["TJMT", "TRF1", "TRT23"],
    "PA": ["TJPA", "TRF1", "TRT8"],
    "PB": ["TJPB", "TRF5", "TRT13"],
    "PE": ["TJPE", "TRF5", "TRT6"],
    "PI": ["TJPI", "TRF1", "TRT22"],
    "PR": ["TJPR", "TRF4", "TRT9"],
    "RJ": ["TJRJ", "TRF2", "TRT1"],
    "RN": ["TJRN", "TRF5", "TRT21"],
    "RO": ["TJRO", "TRF1", "TRT14"],
    "RR": ["TJRR", "TRF1", "TRT11"],
    "RS": ["TJRS", "TRF4", "TRT4"],
    "SC": ["TJSC", "TRF4", "TRT12"],
    "SE": ["TJSE", "TRF5", "TRT20"],
    "SP": ["TJSP", "TRF3", "TRT2", "TRT15"],
    "TO": ["TJTO", "TRF1", "TRT10"],
}
TRIBUNAIS_SUPERIORES = ["STJ", "TST"]
```

**Endpoint 1 — orgao-processos logic:**
1. Resolve CNPJ from edital_id (pncp_id first 14 digits) or direct cnpj param
2. Check cache: datajud_consultado_em < 24h and datajud_orgao exists
3. Get tribunais for UF + superiores
4. Query DataJud in parallel (asyncio.gather with httpx.AsyncClient)
5. Build resumo (por_classe, por_tribunal, date range)
6. Save to pncp_editais.datajud_orgao if edital_id provided
7. Return result

**Endpoint 2 — empresa-idoneidade logic:**
1. Clean CNPJ, validate 14 digits
2. Check cache: datajud_consultas table, same CNPJ, < 24h
3. Get tribunais for uf_prioridade or edital UF
4. Query DataJud parallel with CLASSES_EMPRESA
5. Build alertas (CRITICO for falencia/recuperacao, ATENCAO for execucoes, INFO for rest)
6. Save to datajud_consultas table
7. Return result with alertas array

**Step 2: Register router in main.py**

Add to imports: `from app.routers import canvas, documents, generate, pncp, stream, datajud`
Add include: `app.include_router(datajud.router, prefix="/api/ai", tags=["datajud"])`

**Step 3: Test endpoint**

Run curl against FastAPI with X-Internal-Key header and a known CNPJ.
Expected: JSON response (processos array may be empty, confirms endpoint works).

**Step 4: Commit**

```
git add services/ai/app/routers/datajud.py services/ai/main.py
git commit -m "feat(datajud): FastAPI router with orgao-processos and empresa-idoneidade endpoints"
```

---

### Task 3: PHP Proxy Endpoints

**Files:**
- Create: `app/public/api/datajud/orgao-processos.php`
- Create: `app/public/api/datajud/empresa-idoneidade.php`

**Step 1: Create orgao-processos.php**

Follow exact pattern of `app/public/api/pncp/trigger-analise.php`:
- Session auth check
- CSRF token check
- POST method check
- Parse JSON body
- curl POST to `http://127.0.0.1:8000/api/ai/datajud/orgao-processos` with X-Internal-Key
- Forward response
- 60s timeout (DataJud queries multiple tribunais)

**Step 2: Create empresa-idoneidade.php**

Same pattern as orgao-processos.php but:
- Injects `user_id` from `$_SESSION['user']['id']` into the request body
- Forwards to `/api/ai/datajud/empresa-idoneidade`

**Step 3: Commit**

```
git add app/public/api/datajud/
git commit -m "feat(datajud): PHP proxy endpoints for orgao-processos and empresa-idoneidade"
```

---

### Task 4: Frontend — Feature 1 (Historico Judicial do Orgao)

**Files:**
- Modify: `app/public/areas/iatr/edital.php`

**Step 1: Add DataJud CSS styles**

In the headExtra style block, add styles for:
- `.datajud-card` (border styling like analise-card)
- `.classe-badge` (counter cards per class)
- `.alerta-critico`, `.alerta-atencao`, `.alerta-info` (colored left-border alerts)
- `.cnpj-input` (monospace)
- `.processo-table` (compact table)

**Step 2: Add Feature 1 HTML**

Insert between "Links" div and "AI Analysis Section" (between lines 183 and 185 of current file):
- Card with header "Historico Judicial do Orgao" (bi-briefcase icon)
- Body starts with loading spinner (replaced by JS)
- Status indicator in header (badge with count)

**Step 3: Add Feature 1 JavaScript**

- `loadDatajudOrgao(forceRefresh)` — fetches from proxy, handles loading/error states
- `renderDatajudOrgao(data)` — renders badge counters per class, expandable details table
- Auto-loads on DOMContentLoaded
- "Atualizar" button calls `loadDatajudOrgao(true)`

**Step 4: Commit**

```
git add app/public/areas/iatr/edital.php
git commit -m "feat(datajud): Feature 1 - judicial history UI in edital page"
```

---

### Task 5: Frontend — Feature 3 (Verificacao de Idoneidade)

**Files:**
- Modify: `app/public/areas/iatr/edital.php`

**Step 1: Add Feature 3 HTML**

Insert after Feature 1 section, before "AI Analysis Section":
- Card with header "Verificar Idoneidade de Empresa" (bi-shield-check icon)
- CNPJ input field with mask (XX.XXX.XXX/XXXX-XX)
- "Consultar" button
- Result div (filled by JS)

**Step 2: Add Feature 3 JavaScript**

- CNPJ mask on input (formats as user types)
- Enter key triggers search
- `verificarIdoneidade()` — validates 14 digits, fetches from proxy, renders result
- `renderIdoneidade(data)` — shows alertas with colored cards (CRITICO red, ATENCAO yellow, INFO blue)
- Zero results shows green success message

**Step 3: Commit**

```
git add app/public/areas/iatr/edital.php
git commit -m "feat(datajud): Feature 3 - empresa idoneidade check UI"
```

---

### Task 6: Replicate to Licitacoes Edital Page

**Files:**
- Modify: `app/public/areas/licitacoes/edital.php`

**Step 1: Check structure of licitacoes/edital.php**

Read the file. If it is a near-copy of iatr/edital.php, apply same CSS + HTML + JS changes. If it includes the iatr version, no changes needed.

**Step 2: Apply same DataJud sections**

Copy the CSS, HTML, and JS from Tasks 4-5 to the licitacoes version.

**Step 3: Commit**

```
git add app/public/areas/licitacoes/edital.php
git commit -m "feat(datajud): replicate DataJud UI to licitacoes edital page"
```

---

### Task 7: N8N Workflow — Feature 2 (Enrich Prompt)

**Files:**
- Modify: N8N workflow "IATR - Analise de Edital" (ID: JzfXXdEuOOe7FFf6)

**Step 1: Read current workflow via N8N API**

GET `/api/v1/workflows/JzfXXdEuOOe7FFf6` — identify "Check Edital Data" and "Build Analysis Prompt" nodes.

**Step 2: Modify "Check Edital Data" node**

The PostgreSQL query that fetches edital data must also SELECT `datajud_orgao` and `datajud_consultado_em`.

**Step 3: Modify "Build Analysis Prompt" Code node**

After building the userMessage, append DataJud context if available:

```javascript
const datajudOrgao = $json.datajud_orgao;
if (datajudOrgao && datajudOrgao.total_processos > 0) {
    const resumo = datajudOrgao.resumo || {};
    const porClasse = resumo.por_classe || {};
    const classeList = Object.entries(porClasse)
        .map(([k, v]) => k + ' (' + v + ')').join(', ');

    userMessage += '\n\n--- CONTEXTO JUDICIAL DO ORGAO (DataJud/CNJ) ---\n';
    userMessage += 'O orgao licitante possui ' + datajudOrgao.total_processos + ' processos judiciais registrados.\n';
    userMessage += 'Distribuicao por tipo: ' + classeList + '.\n';
    if (resumo.mais_recente) userMessage += 'Processo mais recente: ' + resumo.mais_recente + '.\n';
    if (resumo.mais_antigo) userMessage += 'Processo mais antigo: ' + resumo.mais_antigo + '.\n';
    userMessage += 'Considere estas informacoes ao avaliar riscos na secao RESUMO EXECUTIVO.\n';
}
```

**Step 4: Publish updated workflow via N8N API**

PUT with cleaned settings (only executionOrder, strip binaryMode etc).

**Step 5: Test**

Trigger analysis on an edital that has datajud_orgao cached. Verify the resumo executivo mentions judicial context.

---

### Task 8: Deploy and End-to-End Test

**Step 1: Deploy FastAPI to VM100**

Copy datajud.py and main.py, restart sunyata-ai service.

**Step 2: Deploy PHP changes to VM100**

Push to git, then `git pull` on VM100.

**Step 3: End-to-end test**

1. Open edital page in browser (logged in)
2. Feature 1: Historico Judicial loads automatically with badges/table
3. Feature 3: Enter CNPJ, click Consultar, verify alertas
4. Feature 2: Trigger Analisar com IA, check analysis mentions judicial data

**Step 4: Fix any issues found during testing**

---

### Task 9: Final Documentation

**Files:**
- Modify: `CLAUDE.md` — add DataJud section under Stack/APIs
- Modify: `docs/DATABASE.md` — ensure all new columns and table documented

**Step 1: Update CLAUDE.md**

Add DataJud Integration section with API details, cache strategy, feature list.

**Step 2: Update DATABASE.md**

Ensure migration 015 columns and datajud_consultas table are fully documented.

**Step 3: Commit and push**

```
git add CLAUDE.md docs/DATABASE.md
git commit -m "docs(datajud): update CLAUDE.md and DATABASE.md with DataJud integration"
git push
```
