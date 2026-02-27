# PNCP Enrichment + Smart Analysis Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enrich edital data at capture time (3 PNCP API calls), fix the ZIP extraction bug blocking 99.7% of editais, show enriched data on the edital page, and implement 3 analysis modes (complete/partial/insufficient).

**Architecture:** The PNCP Daily Monitor (N8N) gains 3 enrichment calls per edital. The FastAPI extract-pdf endpoint learns to handle ZIP files and detect formats by magic bytes instead of filename. The N8N IATR Analysis workflow v3 gains a decision node that routes to complete/partial/insufficient modes before calling the LLM. The edital page shows items, documents, and enriched details.

**Tech Stack:** N8N (CT104), FastAPI/Python (VM100:8000), PHP 8.3 (VM100), PostgreSQL 16

---

## Task 1: Database Migration — New JSONB Columns

**Files:**
- Create: `migrations/016_pncp_enrichment_columns.sql`

**Context:** We need 2 new JSONB columns in `pncp_editais` for enriched data from 3 PNCP API endpoints. The `arquivos_pncp` column already exists but is only populated during PDF extraction (1 of 319 editais). Items and full details don't exist yet.

**Step 1: Write the migration SQL**

```sql
-- Migration 016: PNCP Enrichment Columns
-- Adds columns for data from 3 PNCP API enrichment calls (monitor capture)

BEGIN;

-- Full details from GET /api/consulta/v1/orgaos/{cnpj}/compras/{ano}/{seq}
-- Contains: modoDisputaNome, amparoLegal, srp, processo, orcamentoSigilosoDescricao,
-- linkSistemaOrigem, informacaoComplementar, unidadeOrgao, dataAberturaProposta, etc.
ALTER TABLE pncp_editais ADD COLUMN IF NOT EXISTS pncp_detalhes JSONB;

-- Items from GET /pncp-api/v1/orgaos/{cnpj}/compras/{ano}/{seq}/itens
-- Array of: numeroItem, descricao, quantidade, unidadeMedida, valorUnitarioEstimado,
-- valorTotal, criterioJulgamentoNome, orcamentoSigiloso, situacaoCompraItemNome
ALTER TABLE pncp_editais ADD COLUMN IF NOT EXISTS pncp_itens JSONB;

-- Timestamp of last enrichment (to know if data is fresh)
ALTER TABLE pncp_editais ADD COLUMN IF NOT EXISTS enriquecido_em TIMESTAMPTZ;

COMMIT;
```

**Step 2: Run migration on VM100**

Run: `tools/ssh-cmd.sh vm100 "sudo -u postgres psql -d sunyata_platform -f /dev/stdin" < migrations/016_pncp_enrichment_columns.sql`
Expected: `ALTER TABLE` x3

**Step 3: Update docs/DATABASE.md**

Add the 3 new columns to the `pncp_editais` table documentation.

**Step 4: Commit**

```bash
git add migrations/016_pncp_enrichment_columns.sql docs/DATABASE.md
git commit -m "feat(db): migration 016 — PNCP enrichment columns (pncp_detalhes, pncp_itens, enriquecido_em)"
```

---

## Task 2: Fix ZIP Extraction Bug in FastAPI

**Files:**
- Modify: `services/ai/app/routers/pncp.py` (lines 431–506)
- Modify: `services/ai/app/services/document_processor.py`

**Context:** 318 of 319 editais have no extracted text because the extractor checks `titulo.lower().endswith(".pdf")` but PNCP file titles are numeric codes (e.g. `17908305900092026000`), not filenames. The actual files are often ZIPs containing PDFs. Magic bytes: `PK\x03\x04` = ZIP, `%PDF-` = PDF.

**Step 1: Add ZIP extraction helper to document_processor.py**

Add after the existing `extract_text_from_plain` function (line 59):

```python
import zipfile


def extract_texts_from_zip(data: bytes) -> list[dict]:
    """Extract text from all supported files inside a ZIP archive.

    Returns a list of extraction results, one per file found inside.
    Each dict has: filename, success, text, pages, word_count, error.
    """
    results = []
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            for name in zf.namelist():
                # Skip directories and hidden files
                if name.endswith("/") or name.startswith("__MACOSX"):
                    continue

                inner_bytes = zf.read(name)
                fmt = detect_format(inner_bytes)

                if fmt == "pdf":
                    r = extract_text_from_pdf(inner_bytes)
                elif fmt == "docx":
                    r = extract_text_from_docx(inner_bytes)
                else:
                    r = {"success": False, "text": "", "pages": 0,
                         "word_count": 0, "error": f"Unsupported format inside ZIP: {name}"}

                r["filename"] = name
                results.append(r)
    except zipfile.BadZipFile:
        results.append({"success": False, "text": "", "pages": 0,
                        "word_count": 0, "error": "Invalid ZIP file",
                        "filename": "(archive)"})
    return results


def detect_format(data: bytes) -> str:
    """Detect file format by magic bytes.

    Returns one of: 'pdf', 'zip', 'docx', 'xlsx', 'odt', 'unknown'.
    Note: DOCX, XLSX, ODT all start with PK\\x03\\x04 (ZIP-based formats).
    """
    if data[:4] == b"%PDF":
        return "pdf"
    if data[:4] == b"PK\x03\x04":
        try:
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                names = zf.namelist()
                # Identify specific ZIP-based Office formats by their internal structure
                if any(n.startswith("word/") for n in names):
                    return "docx"
                if any(n.startswith("xl/") for n in names):
                    return "xlsx"
                if "content.xml" in names and "mimetype" in names:
                    return "odt"
                # Generic ZIP (may contain PDFs or other files)
                return "zip"
        except zipfile.BadZipFile:
            return "unknown"
    return "unknown"
```

**Step 2: Rewrite the file processing loop in pncp.py**

Replace the current loop at lines 436–506 in `services/ai/app/routers/pncp.py`. The new logic:
1. Download each file from the PNCP API
2. Detect format by magic bytes (not filename)
3. If PDF → extract directly
4. If ZIP → extract all supported files inside
5. If DOCX → extract text
6. Otherwise → skip with descriptive error

```python
        async with httpx.AsyncClient(timeout=PDF_DOWNLOAD_TIMEOUT, follow_redirects=True) as client:
            for file_info in files_data:
                titulo = file_info.get("titulo", "")
                file_url = file_info.get("url", "")
                seq_doc = file_info.get("sequencialDocumento", 0)
                tipo_doc = file_info.get("tipoDocumentoNome", "")

                try:
                    logger.info(f"Downloading: {titulo} ({file_url})")
                    dl_resp = await client.get(file_url)
                    if dl_resp.status_code != 200:
                        arquivos_result.append(PncpArquivo(
                            sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                            url=file_url, extraido=False,
                            erro=f"Download failed: HTTP {dl_resp.status_code}",
                        ))
                        continue

                    file_bytes = dl_resp.content
                    fmt = detect_format(file_bytes)

                    if fmt == "pdf":
                        result = extract_text_from_pdf(file_bytes)
                        if result["success"] and result["text"].strip():
                            text = result["text"]
                            pages = result["pages"]
                            all_texts.append(f"=== {titulo} ({pages} páginas) ===\n\n{text}")
                            total_pages += pages
                            arquivos_result.append(PncpArquivo(
                                sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                                url=file_url, paginas=pages,
                                caracteres=len(text), extraido=True,
                            ))
                        else:
                            arquivos_result.append(PncpArquivo(
                                sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                                url=file_url, extraido=False,
                                erro=result.get("error", "Empty text extracted"),
                            ))

                    elif fmt == "zip":
                        zip_results = extract_texts_from_zip(file_bytes)
                        any_extracted = False
                        for zr in zip_results:
                            inner_name = zr.get("filename", "unknown")
                            if zr["success"] and zr["text"].strip():
                                text = zr["text"]
                                pages = zr["pages"]
                                label = f"{titulo}/{inner_name}" if inner_name != "(archive)" else titulo
                                all_texts.append(f"=== {label} ({pages} páginas) ===\n\n{text}")
                                total_pages += pages
                                any_extracted = True
                        # Record the ZIP as a single entry with summary
                        extracted_count = sum(1 for zr in zip_results if zr["success"] and zr["text"].strip())
                        total_inner = len(zip_results)
                        inner_pages = sum(zr["pages"] for zr in zip_results if zr["success"])
                        inner_chars = sum(len(zr["text"]) for zr in zip_results if zr["success"])
                        arquivos_result.append(PncpArquivo(
                            sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                            url=file_url, paginas=inner_pages,
                            caracteres=inner_chars, extraido=any_extracted,
                            erro=None if any_extracted else f"ZIP: 0/{total_inner} files extractable",
                        ))

                    elif fmt == "docx":
                        result = extract_text_from_docx(file_bytes)
                        if result["success"] and result["text"].strip():
                            text = result["text"]
                            all_texts.append(f"=== {titulo} (DOCX) ===\n\n{text}")
                            arquivos_result.append(PncpArquivo(
                                sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                                url=file_url, paginas=0,
                                caracteres=len(text), extraido=True,
                            ))
                        else:
                            arquivos_result.append(PncpArquivo(
                                sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                                url=file_url, extraido=False,
                                erro=result.get("error", "Empty text from DOCX"),
                            ))

                    elif fmt in ("xlsx", "odt"):
                        # Recognized format but extraction not implemented yet.
                        # Log clearly so logs are actionable (not silent empty ZIP result).
                        logger.info(f"Recognized unsupported format ({fmt.upper()}): {titulo}")
                        arquivos_result.append(PncpArquivo(
                            sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                            url=file_url, extraido=False,
                            erro=f"Formato reconhecido mas não suportado: {fmt.upper()}",
                        ))

                    else:
                        arquivos_result.append(PncpArquivo(
                            sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                            url=file_url, extraido=False,
                            erro=f"Formato desconhecido (magic bytes: {file_bytes[:4].hex()})",
                        ))

                except Exception as e:
                    logger.warning(f"Error processing {titulo}: {e}")
                    arquivos_result.append(PncpArquivo(
                        sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                        url=file_url, extraido=False, erro=str(e),
                    ))
```

**Step 3: Add import for detect_format and extract_texts_from_zip in pncp.py**

At the top of pncp.py, update the import from document_processor:

```python
from app.services.document_processor import (
    extract_text_from_pdf,
    extract_text_from_docx,
    extract_texts_from_zip,
    detect_format,
)
```

**Step 4: Test with edital 148 (Casa da Moeda — the ZIP file)**

Run:
```bash
tools/ssh-cmd.sh vm100 "curl -s -X POST http://localhost:8000/api/ai/iatr/extract-pdf \
  -H 'X-Internal-Key: 3be587ec449cb5ddb42c071d61ca5892720179bd7545015aa208275cc200759f' \
  -H 'Content-Type: application/json' \
  -d '{\"edital_id\": 148, \"save_to_db\": true}' | python3 -c 'import sys,json; d=json.load(sys.stdin); print(f\"success={d[\"success\"]}, pages={d[\"total_paginas\"]}, chars={d[\"total_caracteres\"]}, files={len(d[\"arquivos\"])}\")'
"
```
Expected: `success=True, pages=N, chars=N, files=N` (non-zero pages/chars)

**Step 5: Sample extraction — BLOCKING GATE before backfill**

Before running the full backfill (Task 3 Step 4), extract a sample of 5-10 editais and verify the success rate. Do NOT proceed to Task 5 without confirming this works in production.

```bash
# Get 5 editais without extracted text
tools/ssh-cmd.sh vm100 "sudo -u postgres psql -d sunyata_platform -t -c \
  \"SELECT id, pncp_id FROM pncp_editais WHERE texto_completo IS NULL LIMIT 5;\""

# Test extraction on each
tools/ssh-cmd.sh vm100 "curl -s -X POST http://localhost:8000/api/ai/iatr/extract-pdf \
  -H 'X-Internal-Key: 3be587ec449cb5ddb42c071d61ca5892720179bd7545015aa208275cc200759f' \
  -H 'Content-Type: application/json' \
  -d '{\"edital_id\": <ID>, \"save_to_db\": false}' | python3 -c \
  'import sys,json; d=json.load(sys.stdin); print(d[\"success\"], d[\"total_paginas\"], [(a[\"titulo\"],a[\"extraido\"],a.get(\"erro\",\"\")) for a in d[\"arquivos\"]])'"
```

Acceptable success rate: ≥50% of editais with at least 1 file successfully extracted. If rate is 0%, investigate before proceeding — do NOT run the backfill.

**Step 6: Commit**

```bash
git add services/ai/app/routers/pncp.py services/ai/app/services/document_processor.py
git commit -m "fix(pncp): detect files by magic bytes, support ZIP/DOCX extraction

Fixes bug where 318/319 editais had no extracted text because the extractor
checked filename extension (.pdf) instead of magic bytes. PNCP files are
often ZIPs containing PDFs. Now detects PDF/ZIP/DOCX by magic bytes and
extracts text from all supported files inside ZIPs."
```

---

## Task 3: N8N PNCP Monitor — Add 3 Enrichment Calls

**Files:**
- Modify: N8N workflow `kWX9x3IteHYZehKC` (PNCP Daily Monitor v3) via API

**Context:** Currently the Monitor fetches editais from the search API and saves basic metadata. We need to add 3 calls per edital after insert/update:

1. `GET /api/consulta/v1/orgaos/{cnpj}/compras/{ano}/{seq}` → full details
2. `GET /pncp-api/v1/orgaos/{cnpj}/compras/{ano}/{seq}/itens` → items
3. `GET /pncp-api/v1/orgaos/{cnpj}/compras/{ano}/{seq}/arquivos` → documents list

These populate `pncp_detalhes`, `pncp_itens`, and `arquivos_pncp` JSONB columns.

**Implementation approach:** Rather than adding complexity to the N8N workflow (which would require sub-workflows or complex looping), add a **new FastAPI endpoint** that does all 3 calls and saves to DB. The N8N monitor calls this endpoint once per edital after insert, similar to how it already calls `extract-pdf`.

**Step 1: Create FastAPI enrichment endpoint**

Add to `services/ai/app/routers/pncp.py`:

```python
class PncpEnrichRequest(BaseModel):
    edital_id: int | None = None
    cnpj: str | None = None
    ano: int | None = None
    sequencial: int | None = None


class PncpEnrichResponse(BaseModel):
    success: bool
    edital_id: int | None = None
    detalhes_ok: bool = False
    itens_count: int = 0
    arquivos_count: int = 0
    error: str | None = None


@router.post("/iatr/enrich-edital", response_model=PncpEnrichResponse)
@limiter.limit("30/minute")
async def enrich_edital(
    req: PncpEnrichRequest,
    request: Request,
    _key=Depends(verify_internal_key),
):
    """Fetch details, items, and documents list from PNCP API and save to DB."""
    pool = request.app.state.pool

    # Resolve CNPJ/ano/seq from edital_id or params
    edital_id = req.edital_id
    cnpj, ano, seq = req.cnpj, req.ano, req.sequencial

    if edital_id and not (cnpj and ano and seq):
        row = await pool.fetchrow(
            "SELECT pncp_id FROM pncp_editais WHERE id = $1", edital_id
        )
        if not row:
            return PncpEnrichResponse(success=False, error="Edital not found")
        cnpj, ano, seq = parse_pncp_id(row["pncp_id"])

    if not (cnpj and ano and seq):
        return PncpEnrichResponse(success=False, error="Missing cnpj/ano/sequencial")

    try:
        async with httpx.AsyncClient(timeout=PNCP_TIMEOUT) as client:
            # 1. Full details
            details_url = f"https://pncp.gov.br/api/consulta/v1/orgaos/{cnpj}/compras/{ano}/{seq}"
            det_resp = await client.get(details_url)
            detalhes = det_resp.json() if det_resp.status_code == 200 else None

            # 2. Items (paginated — fetch first page, usually enough)
            itens_url = f"{PNCP_API_BASE}/orgaos/{cnpj}/compras/{ano}/{seq}/itens?pagina=1&tamanhoPagina=500"
            itens_resp = await client.get(itens_url)
            itens = itens_resp.json() if itens_resp.status_code == 200 else None

            # 3. Documents list
            arqs_url = f"{PNCP_API_BASE}/orgaos/{cnpj}/compras/{ano}/{seq}/arquivos"
            arqs_resp = await client.get(arqs_url)
            arquivos = arqs_resp.json() if arqs_resp.status_code == 200 else None

        # Save to DB
        if edital_id:
            import json
            await pool.execute(
                """UPDATE pncp_editais
                   SET pncp_detalhes = COALESCE($1::jsonb, pncp_detalhes),
                       pncp_itens = COALESCE($2::jsonb, pncp_itens),
                       arquivos_pncp = COALESCE($3::jsonb, arquivos_pncp),
                       enriquecido_em = NOW(),
                       updated_at = NOW()
                   WHERE id = $4""",
                json.dumps(detalhes, ensure_ascii=False) if detalhes else None,
                json.dumps(itens, ensure_ascii=False) if itens else None,
                json.dumps(arquivos, ensure_ascii=False) if arquivos else None,
                edital_id,
            )

        return PncpEnrichResponse(
            success=True,
            edital_id=edital_id,
            detalhes_ok=detalhes is not None,
            itens_count=len(itens) if isinstance(itens, list) else 0,
            arquivos_count=len(arquivos) if isinstance(arquivos, list) else 0,
        )
    except Exception as e:
        logger.exception(f"enrich-edital error: {e}")
        return PncpEnrichResponse(success=False, error=str(e))
```

Also add a robust `parse_pncp_id` helper that extracts CNPJ, ano, seq from `pncp_id` format `{CNPJ}-{esfera}-{seq}/{ano}`.

**Before writing the helper, verify all 319 existing records match the expected pattern:**

Run:
```sql
SELECT DISTINCT regexp_replace(pncp_id, '[0-9]', 'N', 'g') AS pattern, count(*)
FROM pncp_editais
GROUP BY 1;
```
Expected: one pattern like `NNNNNNNNNNNNNN-N-NNNNNN/NNNN`. If multiple patterns exist, adjust the parser accordingly.

```python
def parse_pncp_id(pncp_id: str) -> tuple[str, int, int]:
    """Parse pncp_id format 'CNPJ-ESFERA-SEQ/ANO' into (cnpj, ano, seq).

    Example: '42498600000171-1-000313/2026' → ('42498600000171', 2026, 313)
    Raises ValueError with a clear message if format is unexpected.
    """
    try:
        # Split on '-' — format: {14-digit CNPJ}-{esfera}-{seq}/{ano}
        # CNPJ itself has no dashes in this format
        parts = pncp_id.split("-")
        if len(parts) < 3:
            raise ValueError(f"Expected at least 3 dash-separated parts, got {len(parts)}")
        cnpj = parts[0]
        if len(cnpj) != 14 or not cnpj.isdigit():
            raise ValueError(f"CNPJ part '{cnpj}' is not 14 digits")
        rest = "-".join(parts[2:])  # Everything after esfera: '000313/2026'
        if "/" not in rest:
            raise ValueError(f"Missing '/' in seq/ano part: '{rest}'")
        seq_str, ano_str = rest.split("/", 1)
        return cnpj, int(ano_str), int(seq_str)
    except (ValueError, IndexError) as e:
        raise ValueError(f"Cannot parse pncp_id '{pncp_id}': {e}") from e
```

**Step 2: Test enrichment endpoint manually**

Run:
```bash
tools/ssh-cmd.sh vm100 "curl -s -X POST http://localhost:8000/api/ai/iatr/enrich-edital \
  -H 'X-Internal-Key: 3be587ec449cb5ddb42c071d61ca5892720179bd7545015aa208275cc200759f' \
  -H 'Content-Type: application/json' \
  -d '{\"edital_id\": 84}' | python3 -m json.tool"
```
Expected: `{"success": true, "detalhes_ok": true, "itens_count": N, "arquivos_count": N}`

**Step 3: Add enrichment call to N8N PNCP Monitor workflow**

Add an HTTP Request node after the PostgreSQL insert/update that calls `enrich-edital` for each new edital. This is done via the N8N API:

The node to add:
```json
{
  "parameters": {
    "method": "POST",
    "url": "http://192.168.100.10:8000/api/ai/iatr/enrich-edital",
    "sendHeaders": true,
    "specifyHeaders": "keypair",
    "headerParameters": {
      "parameters": [
        { "name": "X-Internal-Key", "value": "3be587ec449cb5ddb42c071d61ca5892720179bd7545015aa208275cc200759f" },
        { "name": "Content-Type", "value": "application/json" }
      ]
    },
    "sendBody": true,
    "specifyBody": "json",
    "jsonBody": "={{ JSON.stringify({ edital_id: $json.id }) }}",
    "options": { "timeout": 60000, "neverError": true }
  },
  "name": "Enrich Edital",
  "type": "n8n-nodes-base.httpRequest",
  "typeVersion": 4.2
}
```

**Note:** The exact integration depends on the Monitor workflow structure. May need to fetch the workflow, understand the save-to-DB node, and add the enrichment call after it. Use the N8N API patterns from MEMORY.md.

**Step 4: Run backfill for existing 319 editais**

Do NOT use 319 SSH connections — that takes 30+ minutes. Write a Python script, copy it to VM100, and run it there directly:

```python
# Save as: /tmp/backfill_enrich.py (run on VM100)
import requests
import psycopg2
import time
import sys

DB_URL = "postgresql://sunyata_app@localhost/sunyata_platform"
INTERNAL_KEY = "3be587ec449cb5ddb42c071d61ca5892720179bd7545015aa208275cc200759f"
API_URL = "http://localhost:8000/api/ai/iatr/enrich-edital"
HEADERS = {"X-Internal-Key": INTERNAL_KEY, "Content-Type": "application/json"}

conn = psycopg2.connect(DB_URL)
cur = conn.cursor()
cur.execute("SELECT id FROM pncp_editais WHERE enriquecido_em IS NULL ORDER BY id")
ids = [row[0] for row in cur.fetchall()]
cur.close()
conn.close()

print(f"Backfilling {len(ids)} editais...")
ok = err = 0
for edital_id in ids:
    try:
        r = requests.post(API_URL, json={"edital_id": edital_id}, headers=HEADERS, timeout=30)
        d = r.json()
        if d.get("success"):
            ok += 1
            print(f"  ✓ {edital_id}: itens={d.get('itens_count', 0)}, arqs={d.get('arquivos_count', 0)}")
        else:
            err += 1
            print(f"  ✗ {edital_id}: {d.get('error', 'unknown error')}", file=sys.stderr)
    except Exception as e:
        err += 1
        print(f"  ✗ {edital_id}: exception {e}", file=sys.stderr)
    time.sleep(0.5)  # Polite to PNCP API: ~2 req/s

print(f"\nDone: {ok} OK, {err} errors")
```

Copy and run:
```bash
cat /tmp/backfill_enrich.py | ssh ovh "ssh root@192.168.100.10 'cat > /tmp/backfill_enrich.py'"
tools/ssh-cmd.sh vm100 "cd /var/www/sunyata/services/ai && python3 /tmp/backfill_enrich.py"
```

Expected runtime: ~5 minutes (319 × 0.5s delay + HTTP overhead).

**Step 5: Commit**

```bash
git add services/ai/app/routers/pncp.py
git commit -m "feat(pncp): enrichment endpoint — fetches details, items, documents from PNCP API

New POST /api/ai/iatr/enrich-edital fetches 3 PNCP API endpoints per edital
and saves to pncp_detalhes, pncp_itens, arquivos_pncp JSONB columns.
Called by N8N monitor at capture time and available for backfill."
```

---

## Task 4: Edital Page — Show Enriched Data

**Files:**
- Modify: `app/public/areas/iatr/edital.php`

**Context:** The edital page currently shows basic metadata (orgao, uf, modalidade, dates). With enrichment data in DB, we can now show:
- Items with quantities, descriptions, values
- Documents list with download links
- Additional details (dispute mode, legal basis, SRP, process number, complementary info)

**Step 1: Add items section after Objeto**

Below the "Objeto" section (around line 206), add a new "Itens da Licitação" section that reads from `pncp_itens` JSONB:

```php
<?php
$itens = is_string($edital['pncp_itens'] ?? null)
    ? json_decode($edital['pncp_itens'], true) ?: []
    : ($edital['pncp_itens'] ?? []);
if ($itens): ?>
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title"><i class="bi bi-list-ol"></i> Itens da Licitação (<?= count($itens) ?>)</h3>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table table-striped">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Descrição</th>
                    <th style="width:80px">Qtd</th>
                    <th style="width:80px">Unidade</th>
                    <th style="width:130px">Valor Unit. Est.</th>
                    <th style="width:130px">Valor Total Est.</th>
                    <th style="width:120px">Critério</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($itens as $item):
                $valorUnit = $item['valorUnitarioEstimado'] ?? null;
                $valorTotal = $item['valorTotal'] ?? null;
                $sigiloso = !empty($item['orcamentoSigiloso']);
            ?>
                <tr>
                    <td><?= (int)($item['numeroItem'] ?? 0) ?></td>
                    <td><?= sanitize_output($item['descricao'] ?? 'N/I') ?></td>
                    <td><?= sanitize_output($item['quantidade'] ?? '-') ?></td>
                    <td><?= sanitize_output($item['unidadeMedida'] ?? '-') ?></td>
                    <td class="text-end"><?= $sigiloso ? '<span class="text-muted">Sigiloso</span>' : ($valorUnit !== null ? 'R$ ' . number_format((float)$valorUnit, 2, ',', '.') : '-') ?></td>
                    <td class="text-end"><?= $sigiloso ? '<span class="text-muted">Sigiloso</span>' : ($valorTotal !== null ? 'R$ ' . number_format((float)$valorTotal, 2, ',', '.') : '-') ?></td>
                    <td><?= sanitize_output($item['criterioJulgamentoNome'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
```

**Step 2: Add documents section**

Below items, add a "Documentos" section showing available files from `arquivos_pncp`:

```php
<?php
$arquivos = is_string($edital['arquivos_pncp'] ?? null)
    ? json_decode($edital['arquivos_pncp'], true) ?: []
    : ($edital['arquivos_pncp'] ?? []);
if ($arquivos): ?>
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title"><i class="bi bi-file-earmark-text"></i> Documentos (<?= count($arquivos) ?>)</h3>
    </div>
    <div class="list-group list-group-flush">
    <?php foreach ($arquivos as $arq):
        $downloadUrl = $arq['url'] ?? ($arq['uri'] ?? '');
        $titulo = $arq['titulo'] ?? $arq['tipoDocumentoNome'] ?? 'Documento';
        $tipo = $arq['tipoDocumentoNome'] ?? $arq['tipo'] ?? '';
    ?>
        <div class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-file-earmark"></i>
                <?= sanitize_output($titulo) ?>
                <?php if ($tipo): ?><span class="text-muted ms-2">(<?= sanitize_output($tipo) ?>)</span><?php endif; ?>
            </div>
            <?php if ($downloadUrl): ?>
            <a href="<?= sanitize_output($downloadUrl) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-download"></i> Baixar
            </a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
```

**Step 3: Show enriched details from pncp_detalhes**

Enhance the info grid with additional fields from `pncp_detalhes`:

```php
<?php
$detalhes = is_string($edital['pncp_detalhes'] ?? null)
    ? json_decode($edital['pncp_detalhes'], true) ?: []
    : ($edital['pncp_detalhes'] ?? []);

$modoDisputa = $detalhes['modoDisputaNome'] ?? '';
$amparoLegal = $detalhes['amparoLegal'] ?? ($detalhes['amparoLegalNome'] ?? '');
$srp = isset($detalhes['srp']) ? ($detalhes['srp'] ? 'Sim' : 'Não') : '';
$processo = $detalhes['processo'] ?? '';
$infoComplementar = $detalhes['informacaoComplementar'] ?? '';
$linkOrigem = $detalhes['linkSistemaOrigem'] ?? '';
?>
```

Add these to the info grid:
- Modo de Disputa (if present)
- Amparo Legal (if present)
- SRP — Sistema de Registro de Preços (if present)
- Processo Administrativo (if present)

Add `informacaoComplementar` as a collapsible section below Objeto (if present and non-empty).

**Step 4: Also add pncp_itens and pncp_detalhes to the DB query**

The current DB query in `edital.php` must be updated to include the new columns. Find the `SELECT` query and add `pncp_detalhes, pncp_itens` to the column list.

**Step 5: Test the page visually**

Open the edital page for an enriched edital and verify items, documents, and details display correctly.

**Step 6: Commit**

```bash
git add app/public/areas/iatr/edital.php
git commit -m "feat(iatr): edital page shows items, documents, enriched details from PNCP API"
```

---

## Task 5: Three Analysis Modes in N8N IATR Workflow v3

**Files:**
- Modify: `iatr_analise_edital_v3_modular.json` (local reference)
- Deploy: N8N workflow `4HJSmPLYTNTUnO8y` via API

**Context:** Currently the workflow has a binary path: has text → full analysis, no text → still calls LLM with just metadata (producing poor results like "Não informado" × 40). We need 3 modes:

- **Mode A (Complete):** `texto_completo` available → full 12-section analysis with Sonnet
- **Mode B (Partial):** No text but has `pncp_itens` → partial analysis (6 sections) with Haiku
- **Mode C (Insufficient):** No text AND no items → programmatic response, NO LLM call

**Step 1: Add "Determine Analysis Mode" Code node**

Insert between "Check Edital Data" and "Has Cached Text?" nodes. This node replaces the binary IF with a 3-way routing:

```javascript
// ============================================================
// DETERMINE ANALYSIS MODE — v3.1
// ============================================================
const edital = $json;

// Read enrichment data from DB (passed through from Mark In Progress query)
const textoCompleto = edital.texto_completo || '';
const temTexto = textoCompleto.length > 500;

// pncp_itens is a JSONB column — parse if string
let itens = edital.pncp_itens;
if (typeof itens === 'string') {
  try { itens = JSON.parse(itens); } catch(e) { itens = null; }
}
const temItens = Array.isArray(itens) && itens.length > 0;

// pncp_detalhes for extra metadata
let detalhes = edital.pncp_detalhes;
if (typeof detalhes === 'string') {
  try { detalhes = JSON.parse(detalhes); } catch(e) { detalhes = null; }
}

let modo;
if (temTexto) {
  modo = 'completo';
} else if (temItens) {
  modo = 'parcial';
} else {
  modo = 'insuficiente';
}

return [{ json: {
  ...edital,
  modo_analise: modo,
  tem_texto: temTexto,
  tem_itens: temItens,
  pncp_itens_parsed: itens,
  pncp_detalhes_parsed: detalhes
} }];
```

**Step 2: Update "Mark In Progress" query to fetch new columns**

Add `pncp_detalhes, pncp_itens, enriquecido_em` to the RETURNING clause:

```sql
UPDATE pncp_editais SET status_analise = 'em_analise', updated_at = NOW()
WHERE id = {{$json.edital_id}}
RETURNING id, pncp_id, titulo, objeto, orgao, uf, modalidade, valor_estimado,
  data_encerramento, url_pncp, raw_data, texto_completo, texto_total_caracteres,
  pncp_detalhes, pncp_itens, enriquecido_em;
```

**Step 3: Add routing IF node — 3 outputs**

Replace "Has Cached Text?" with a new routing logic. N8N IF nodes only support 2 branches, so use a Code node that outputs to 3 different paths using `$items()`:

Actually, use two chained IF nodes:
1. `Is Mode Insuficiente?` — if `modo_analise === 'insuficiente'` → Respond Insufficient (no LLM)
2. `Has Text for Full Analysis?` — if `modo_analise === 'completo'` → Build Full Prompt, else → Build Partial Prompt

**Step 4: Add "Respond Insufficient" path (Mode C)**

A Code node that builds the programmatic response + a Save node + Respond.

**IMPORTANT:** Use `status: 'insuficiente'` — NOT `'erro'`. Mode C is an intentional decision, not a failure. The portal treats `status_analise = 'erro'` as a generic failure ("Erro na análise. Tente novamente."). With `'insuficiente'` we render a distinct, helpful message with a PNCP link.

```javascript
// MODE C — Insufficient data, NO LLM call, zero tokens spent
const edital = $json;

return [{ json: {
  edital_id: edital.edital_id,
  status: 'insuficiente',   // distinct from 'erro' — intentional, not a failure
  resultado: JSON.stringify({
    [edital.tipo_analise]: 'Dados insuficientes para análise. Os documentos deste edital não estão disponíveis ou não puderam ser processados. Consulte diretamente no PNCP para baixar os documentos.',
    reason: 'insufficient_data',
    pncp_url: edital.url_pncp || '',
    versao_prompt: '3.1',
    modo_analise: 'insuficiente'
  }),
  model: 'none',
  tokens: 0,
  tokens_input: 0,
  tokens_output: 0,
  custo_usd: 0,
  tipo_analise: edital.tipo_analise,
  has_full_text: false
} }];
```

**Also update `edital.php` JS to handle `'insuficiente'` status:**

Add this case to `renderAnaliseResult()` before the `'erro'` check. Use DOM methods (not innerHTML) — defensive coding even for trusted URLs:

```javascript
if (data.status_analise === 'insuficiente') {
    const resultado = data.analise_resultado || {};
    const pncpUrl = resultado.pncp_url || '';

    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-warning';

    const icon = document.createElement('i');
    icon.className = 'bi bi-info-circle me-2';
    alertDiv.appendChild(icon);

    const strong = document.createElement('strong');
    strong.textContent = 'Dados insuficientes para análise. ';
    alertDiv.appendChild(strong);

    alertDiv.appendChild(document.createTextNode(
        'Os documentos deste edital não estão disponíveis para extração automática.'
    ));

    if (pncpUrl) {
        const link = document.createElement('a');
        link.href = pncpUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.className = 'alert-link ms-1';
        link.textContent = 'Ver documentos no PNCP';
        alertDiv.appendChild(link);
    }

    analiseContent.replaceChildren(alertDiv);
    return;
}
```

**Step 5: Add "Build Partial Prompt" node (Mode B)**

Use the **full SYSTEM_BASE** from the v3 workflow (not a shortened version). The formatting rules (numeração hierárquica, literalidade, rastreabilidade) are universal — Haiku without them produces inconsistent output. Only the TASK prompt differs.

```javascript
// ============================================================
// BUILD PARTIAL ANALYSIS PROMPT — Mode B (items + metadata only)
// ============================================================
const edital = $json;
const tipoAnalise = edital.tipo_analise;
const itens = edital.pncp_itens_parsed || [];
const detalhes = edital.pncp_detalhes_parsed || {};

// Reuse SYSTEM_BASE from Build Analysis Prompt — same rules, same formatting
// (copy the full SYSTEM_BASE string from that node here)
const SYSTEM_BASE = /* ... same SYSTEM_BASE as in Build Analysis Prompt node ... */;

// Format items as text
let itensText = '';
if (itens.length > 0) {
  itensText = 'ITENS DA LICITAÇÃO:\n\n';
  for (const item of itens) {
    const valor = item.orcamentoSigiloso ? 'Sigiloso' :
      (item.valorUnitarioEstimado != null ? `R$ ${Number(item.valorUnitarioEstimado).toLocaleString('pt-BR', {minimumFractionDigits: 2})}` : 'N/I');
    itensText += `- Item ${item.numeroItem}: ${item.descricao || 'N/I'} | Qtd: ${item.quantidade || 'N/I'} ${item.unidadeMedida || ''} | Valor unit.: ${valor} | Critério: ${item.criterioJulgamentoNome || 'N/I'}\n`;
  }
}

const userMessage = `TAREFA: ANÁLISE PARCIAL DO EDITAL (baseada em metadados da API PNCP)

AVISO: O texto completo do edital NÃO está disponível. Esta análise é baseada apenas nos dados estruturados da API PNCP (metadados e itens). Gere SOMENTE as 5 seções abaixo — não invente seções adicionais nem tente preencher seções que dependem do texto do edital.

1. INFORMAÇÕES ESSENCIAIS
2. RESUMO EXECUTIVO (parcial — apenas o que os dados estruturados permitem)
3. DETALHAMENTO DO OBJETO (itens da API com quantidades e valores)
10. VALORES (orçamento estimado conforme API — se sigiloso, declarar explicitamente)
12. RISCOS E LIMITAÇÕES DA ANÁLISE — liste as seções não geradas, o que falta para análise completa, e link para documentos no PNCP

Nota: Seções 4, 5, 6, 7, 8, 9, 11 dependem do texto do edital e não serão geradas nesta análise parcial.

---

DADOS DO EDITAL:
- Título: ${edital.titulo || 'N/I'}
- Órgão: ${edital.orgao || 'N/I'} (${edital.uf || 'N/I'})
- Modalidade: ${edital.modalidade || detalhes.modalidadeLicitacaoNome || 'N/I'}
- Modo de Disputa: ${detalhes.modoDisputaNome || 'N/I'}
- Amparo Legal: ${detalhes.amparoLegal || detalhes.amparoLegalNome || 'N/I'}
- SRP: ${detalhes.srp != null ? (detalhes.srp ? 'Sim' : 'Não') : 'N/I'}
- Processo: ${detalhes.processo || 'N/I'}
- Valor Estimado: ${typeof edital.valor_estimado === 'number' && edital.valor_estimado > 0 ? 'R$ ' + edital.valor_estimado.toLocaleString('pt-BR', {minimumFractionDigits: 2}) : (detalhes.orcamentoSigilosoDescricao || 'Não informado')}
- Encerramento: ${edital.data_encerramento || 'N/I'}
- URL: ${edital.url_pncp || 'N/I'}

Descrição do Objeto:
${edital.objeto || 'N/I'}

${itensText}

${detalhes.informacaoComplementar ? 'INFORMAÇÃO COMPLEMENTAR:\n' + detalhes.informacaoComplementar : ''}`;

return [{ json: {
  edital_id: edital.edital_id,
  pncp_id: edital.pncp_id,
  tipo_analise: tipoAnalise,
  system_prompt: SYSTEM_PARTIAL,
  user_message: userMessage,
  selected_model: 'claude-haiku-4-5',
  max_tokens: 4000,
  has_full_text: false,
  texto_chars: userMessage.length,
  modo_analise: 'parcial'
} }];
```

**Step 6: Update "Build Analysis Prompt" for Mode A**

The existing Build Analysis Prompt node stays mostly unchanged — it handles Mode A (complete). Just add `modo_analise: 'completo'` to its output for tracking.

**Step 7: Update "Save Analysis Result" to track mode**

The save query should also store the analysis mode. Since we don't want another migration, we can include it in the `analise_resultado` JSONB:

```javascript
jsonbFragment.modo_analise = promptData.modo_analise || 'completo';
```

**Step 8: Wire the 3 paths in the workflow connections**

```
Check Edital Data → Determine Analysis Mode
  → Is Insuficiente?
      → YES: Build Insufficient Response → Save → Format → Respond
      → NO: Has Text?
          → YES: [existing flow: Has Cached Text? → Extract PDF → Build Full Prompt → LLM → Process → Save → Respond]
          → NO: Build Partial Prompt → Call LiteLLM → Process → Save → Respond
```

**Step 9: Update the local JSON reference file**

Update `iatr_analise_edital_v3_modular.json` with the new nodes and connections.

**Step 10: Deploy via N8N API**

Use the N8N API to PUT the updated workflow. Remember the API patterns from MEMORY.md: only `name, nodes, connections, settings` in the payload, strip extras.

**Step 11: Test all 3 modes**

- Mode A: Trigger analysis for edital 84 (has extracted text) → expect full analysis
- Mode B: Trigger analysis for an edital with items but no text → expect partial analysis with Haiku
- Mode C: Trigger analysis for an edital with no items and no text → expect programmatic "insufficient" response without LLM call

**Step 12: Commit**

```bash
git add iatr_analise_edital_v3_modular.json
git commit -m "feat(iatr): 3 analysis modes — complete/partial/insufficient

Mode A (Sonnet): full 12-section analysis when extracted text available
Mode B (Haiku): partial 6-section analysis with items from PNCP API
Mode C (no LLM): programmatic insufficient-data response, saves tokens"
```

---

## Task 6: Deploy and Verify End-to-End

**Files:**
- Deploy: FastAPI changes to VM100
- Deploy: PHP changes to VM100
- Verify: Full flow from edital page

**Step 1: Deploy FastAPI changes**

```bash
# Push code to VM100
git push origin main
tools/ssh-cmd.sh vm100 "cd /var/www/sunyata && git pull"

# Restart uvicorn
tools/ssh-cmd.sh vm100 "ps aux | grep uvicorn | grep -v grep"
# Note PID, then:
tools/ssh-cmd.sh vm100 "kill <PID> && cd /var/www/sunyata/services/ai && nohup python -m uvicorn app.main:app --host 0.0.0.0 --port 8000 > /dev/null 2>&1 &"
```

**Step 2: Verify extraction fix**

```bash
# Test with edital 148 (was failing with "Not a PDF, skipped")
tools/ssh-cmd.sh vm100 "curl -s -X POST http://localhost:8000/api/ai/iatr/extract-pdf \
  -H 'X-Internal-Key: ...' -H 'Content-Type: application/json' \
  -d '{\"edital_id\": 148, \"save_to_db\": true}'"
```

**Step 3: Verify enrichment endpoint**

```bash
tools/ssh-cmd.sh vm100 "curl -s -X POST http://localhost:8000/api/ai/iatr/enrich-edital \
  -H 'X-Internal-Key: ...' -H 'Content-Type: application/json' \
  -d '{\"edital_id\": 148}'"
```

**Step 4: Verify edital page shows enriched data**

Open `http://158.69.25.114/areas/iatr/edital.php?id=148` and verify items, documents, and details display.

**Step 5: Verify analysis Mode B**

Trigger analysis for an edital with items but no extracted text. Verify Haiku is used and the output is a partial report (~2 pages).

**Step 6: Commit the uncommitted edital.php changes**

The current working tree has uncommitted improvements to `edital.php` (raw_data enrichment). Bundle these into the Task 4 commit.

---

## Dependency Graph

```
Task 1 (Migration) ─────────────────────────┐
                                             ├──→ Task 3 (Enrichment Endpoint + N8N)
Task 2 (ZIP Fix) ───────────────────────────┤    │
                                             │    ├──→ Task 4 (Edital Page)
                                             │    │
                                             │    └──→ Task 5 (3 Analysis Modes)
                                             │         │
                                             └─────────┴──→ Task 6 (Deploy + Verify)
```

Tasks 1 and 2 can run in parallel. Task 3 depends on Task 1. Tasks 4 and 5 depend on Task 3. Task 6 depends on all.

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| PNCP rate limits during backfill (319 × 3 calls) | Medium | Add 1s delay between editais; run during off-hours |
| ZIP contains only images (scanned docs) | Low | pypdf returns empty text → logged, not fatal |
| N8N workflow update breaks production | High | Test with inactive copy first; keep current v2 active |
| Large items lists (500+ items) | Low | Paginated fetch with 500 limit; JSONB handles any size |
