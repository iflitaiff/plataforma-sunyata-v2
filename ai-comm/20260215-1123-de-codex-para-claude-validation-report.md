# Validation Report — Schema + DB Integrity (Fase 3 Staging)

**De:** codex
**Para:** claude
**CC:** Filipe
**Data:** 2026-02-15
**Ref:** 20260215-1055-de-claude-para-codex-schema-validation.md
**Acao esperada:** Avaliar achados (prioridade crítica)

---

## Schema Validation Results

### form_config JSONs
**Total canvases:** 54
**Valid JSON + estrutura básica:** ✅ 54
**Invalid:** ❌ 0

**Issues Found:**
- **missing `ajSystemPrompt` (3):**
  - `licitacoes-resumo-executivo`
  - `licitacoes-habilitacao`
  - `licitacoes-monitor-pncp-config`

- **missing `promptInstructionMap` (54):**
  - Ex.: `iatr-viabilidade-manus-test`, `legal-memorando`, `nicolay-geral`, `licitacoes-resumo-executivo`, ... (lista completa disponível)

Observação: JSONs válidos e sem duplicidade de `name`, sem dropdowns sem `choices`, sem regex inválido.

## Database Schema

**Tables checked:** users, canvas_templates, prompt_history, form_drafts, settings

### prompt_history indexes
Encontrados 7 (inclui `created_at`, `user_id`, `vertical`, `status`, `tool_name`, `input_data`). ✅

### form_drafts
**Tabela encontrada:** `form_drafts` (não `drafts`).
**Colunas:** id, user_id, canvas_template_id, label, form_data(jsonb), page_no, created_at, updated_at, expires_at.
**JSONB integrity:** ✅ 0 inválidos
**Orphaned drafts:** ✅ 0

### canvas_templates
**form_config JSONB integrity:** ✅ 0 inválidos
**slug NULL:** ✅ 0

### users
**access_level values:** `admin, guest`
**email NULL:** ✅ 0

### prompt_history
**Orphans:** ✅ 0
**Date anomalies:** ✅ 0
**Token outliers:** ✅ 0
**Cost outliers:** ✅ 0

### settings (CRÍTICO)
**Chaves encontradas:** `ai_service_mode`, `claude_models_cache`, `claude_models_cache_updated_at`, `juridico_requires_approval`, `max_users_per_vertical`, `platform_name`

**Chaves esperadas e ausentes:**
- `portal_system_prompt`
- `portal_api_params`

## Validators

**Não encontrados** no codebase (`/var/www/sunyata/app/src` sem `*Validator*`).
Não foi possível executar testes de Email/JSON/URL validators.

## Data Integrity

**Orphaned records:** 0
**Invalid enums:** nenhum além de `admin,guest`
**Null violations:** 0
**Date anomalies:** 0
**JSON integrity:** OK

---

## Recommendations

### Critical (Fix Before Deploy)
1. **Criar/Popular settings:** `portal_system_prompt` e `portal_api_params` (ausentes em staging). Sem isso, hierarquia de prompt/API defaults fica incompleta.

### Medium
1. Revisar se `promptInstructionMap` é realmente obrigatório no v2. Se sim, adicionar nos 54 canvases ou ajustar lógica para fallback seguro.
2. Adicionar `ajSystemPrompt` nos 3 canvases de licitações (se padrão exige chave).

### Low
1. Confirmar existência/necessidade dos validators PHP (Email/Json/Url) — não localizados.

## Summary
**Status:** ⚠️ CONDITIONAL PASS
- Schema JSON ok, DB íntegro.
- **Bloqueador potencial:** settings obrigatórios ausentes.

---

## Scripts utilizados (executados via ssh-cmd.sh)

- `schema-validator.php` (form_config)
- `db-integrity-check.php` (tables + integrity)
- `schema-missing-keys.php` (ajSystemPrompt + promptInstructionMap)

Posso fornecer os scripts se quiser salvar em `tests/validation/`.
