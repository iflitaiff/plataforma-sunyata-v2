# Phase C Tasks 1 & 2 — Completas

**De:** Claude (Executor)
**Para:** Claude Chat (Arquiteto)
**Data:** 2026-02-24 08:30
**Ref:** Briefing Phase C de 2026-02-24

---

## Resumo

Tasks 1 e 2 implementadas, deployadas, e testadas com o edital PRODERJ. O endpoint está funcional e a cache no DB está a funcionar.

---

## Task 1: Endpoint `/api/ai/iatr/extract-pdf`

**Port:** 8000 (FastAPI, Nginx proxy `/api/ai/`)
**Método:** POST
**Auth:** `X-Internal-Key` header (mesmo padrão dos outros endpoints)

### Decisão Estrutural: pypdf em vez de pdfplumber

O serviço FastAPI já tinha `pypdf` instalado e um `extract_text_from_pdf()` funcional em `services/document_processor.py`. Reutilizei essa infraestrutura existente em vez de instalar pdfplumber. **Zero dependências novas.**

### Request

```json
{
  "edital_id": 84,         // OR cnpj + ano + sequencial
  "cnpj": "42498600000171",
  "ano": 2026,
  "sequencial": 313,
  "save_to_db": true        // cache no pncp_editais
}
```

### Response (resumido)

```json
{
  "success": true,
  "edital_id": 84,
  "cnpj": "42498600000171",
  "ano": 2026,
  "sequencial": 313,
  "texto_completo": "=== EDITAL.pdf (30 páginas) ===\n\n...",
  "total_paginas": 262,
  "total_caracteres": 903465,
  "arquivos": [
    {"sequencial": 1, "titulo": "EDITAL.pdf", "paginas": 30, "caracteres": 93455, "extraido": true},
    {"sequencial": 4, "titulo": "TERMO DE REFERENCIA ANEXO I_E ANEXOS DO TR.pdf", "paginas": 107, "caracteres": 363117, "extraido": true},
    // ... 10 PDFs extraídos, 1 .xls skipped
  ]
}
```

### Fluxo Interno

1. Resolve CNPJ/ano/seq (via `edital_id` → DB lookup, ou params directos)
2. Verifica cache (`texto_completo IS NOT NULL` no DB) → retorna em ~40ms se cached
3. Fetch lista de arquivos: `GET pncp.gov.br/pncp-api/v1/orgaos/{cnpj}/compras/{ano}/{seq}/arquivos`
4. Download cada PDF via httpx, extract com pypdf
5. Consolida texto com headers `=== filename (N páginas) ===`
6. Salva no DB (colunas novas)

### Notas para N8N (Task 3)

- O texto consolidado usa separadores `=== FILENAME (N páginas) ===` entre documentos
- Ficheiros não-PDF são listados em `arquivos` com `extraido: false, erro: "Not a PDF, skipped"`
- O endpoint aceita tanto `edital_id` quanto `cnpj/ano/sequencial` direto
- Se `save_to_db: true` e o edital existe no DB, o texto é cached automaticamente

---

## Task 2: Database Migration

5 colunas adicionadas a `pncp_editais`:

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `texto_completo` | TEXT | Texto concatenado de todos os PDFs |
| `texto_extraido_em` | TIMESTAMPTZ | Timestamp da última extração |
| `texto_total_paginas` | INTEGER | Total de páginas (todos os PDFs) |
| `texto_total_caracteres` | INTEGER | Total de caracteres |
| `arquivos_pncp` | JSONB | Array de metadata dos ficheiros PNCP |

---

## Teste com PRODERJ

**Edital:** CNPJ 42498600000171, ANO 2026, SEQ 313 (id=84 no DB)

| Métrica | Resultado |
|---------|-----------|
| PDFs processados | 10 de 11 (1 .xls skipped) |
| Total páginas | 262 |
| Total caracteres | 903.465 |
| Maior ficheiro | TERMO DE REFERENCIA (107 pgs, 363K chars) |
| Cache hit | 41ms |
| Primeira extração | ~30-40s (download + extract) |

---

## Próximos passos (teus — Tasks 3 e 4)

- Endpoint pronto em `http://192.168.100.10:8000/api/ai/iatr/extract-pdf`
- Para N8N, usar IP interno (não sslip.io): `http://192.168.100.10:8000`
- Auth header: `X-Internal-Key: {INTERNAL_API_KEY do .env}`
