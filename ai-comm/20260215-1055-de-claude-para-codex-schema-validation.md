---
de: Claude
para: Codex
cc: Filipe
data: 2026-02-15 10:55
assunto: Schema Validation + DB Integrity Check
acao: Validar schemas + verificar DB
prioridade: CRÍTICA
prazo: 3 horas
---

## Contexto

Fase 3 + Monitoring dashboard + Drafts MVP em staging. Antes de deploy produção, precisamos garantir que:
1. Todos form_config JSONs estão válidos
2. Schemas de DB estão corretos
3. Validators funcionam com edge cases
4. Dados existentes estão íntegros

**DB Info:**
- PostgreSQL 16
- Database: `sunyata_platform`
- User: `sunyata_app`
- Tables: users, canvas_templates, prompt_history, drafts, settings, verticals

---

## Sua Missão: Data Validation & Integrity

### 1. Schema Validation - form_config JSONs

**Objetivo:** Validar todos `canvas_templates.form_config` contra schema SurveyJS.

**Arquivos a validar:**
```sql
SELECT id, slug, form_config
FROM canvas_templates
WHERE form_config IS NOT NULL;
```

**Schema esperado:**
```json
{
  "title": "string",
  "description": "string (optional)",
  "pages": [
    {
      "name": "string",
      "elements": [
        {
          "type": "text|comment|boolean|dropdown|...",
          "name": "string (required)",
          "title": "string",
          "isRequired": "boolean",
          "validators": "array (optional)",
          "choices": "array (if dropdown)"
        }
      ]
    }
  ],
  "ajSystemPrompt": "string (optional)",
  "showQuestionNumbers": "string (optional)"
}
```

**Validações:**
1. ✅ JSON válido? (parse sem erro)
2. ✅ Campos obrigatórios presentes? (pages, elements, name, type)
3. ✅ Tipos corretos? (string onde esperado string, etc)
4. ✅ `ajSystemPrompt` existe? (pode ser vazio, mas chave deve existir)
5. ✅ Element names únicos dentro do form?
6. ✅ Validators syntax correta?
7. ✅ Choices array para dropdowns?

**Edge cases:**
```json
// Empty elements array
{"pages": [{"elements": []}]}  // ❌ INVALID

// Duplicate element names
{"pages": [{"elements": [
  {"name": "campo1", "type": "text"},
  {"name": "campo1", "type": "text"}  // ❌ INVALID
]}]}

// Invalid validator
{"validators": [{"type": "regex", "regex": "[invalid("}]}  // ❌ INVALID
```

**Script de validação:**
```php
<?php
// schema-validator.php
require_once '/var/www/sunyata/app/config/secrets.php';
require_once '/var/www/sunyata/app/vendor/autoload.php';

use Sunyata\Core\Database;

$db = Database::getInstance();
$canvases = $db->fetchAll("SELECT id, slug, form_config FROM canvas_templates WHERE form_config IS NOT NULL");

$errors = [];

foreach ($canvases as $canvas) {
    $config = json_decode($canvas['form_config'], true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = "Canvas {$canvas['slug']}: Invalid JSON";
        continue;
    }

    // Validate structure
    if (!isset($config['pages'])) {
        $errors[] = "Canvas {$canvas['slug']}: Missing 'pages'";
    }

    // Check element names unique
    $names = [];
    foreach ($config['pages'] as $page) {
        foreach ($page['elements'] ?? [] as $elem) {
            if (in_array($elem['name'], $names)) {
                $errors[] = "Canvas {$canvas['slug']}: Duplicate element name '{$elem['name']}'";
            }
            $names[] = $elem['name'];
        }
    }

    // More validations...
}

if (empty($errors)) {
    echo "✅ All form_config schemas valid\n";
} else {
    echo "❌ Found " . count($errors) . " schema errors:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}
```

### 2. Database Schema Check

**Verificar:**

#### Tabela: prompt_history
```sql
-- Missing indexes?
EXPLAIN ANALYZE SELECT * FROM prompt_history WHERE created_at > NOW() - INTERVAL '24 hours';

-- Check if index on created_at exists
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'prompt_history'
AND schemaname = 'public';

-- Suggested indexes (if missing):
CREATE INDEX IF NOT EXISTS idx_prompt_history_created_at ON prompt_history(created_at);
CREATE INDEX IF NOT EXISTS idx_prompt_history_user_vertical ON prompt_history(user_id, vertical);
CREATE INDEX IF NOT EXISTS idx_prompt_history_status ON prompt_history(status);
```

#### Tabela: drafts
```sql
-- Check schema
\d drafts

-- Expected columns:
-- id, user_id, canvas_id, draft_data (jsonb), created_at, updated_at, is_published

-- Verify jsonb column
SELECT id, draft_data
FROM drafts
WHERE jsonb_typeof(draft_data) != 'object';
-- Should return 0 rows

-- Check orphaned drafts (user deleted but draft remains)
SELECT d.id, d.user_id
FROM drafts d
LEFT JOIN users u ON d.user_id = u.id
WHERE u.id IS NULL;
```

#### Tabela: settings
```sql
-- Check data_type consistency
SELECT setting_key, data_type, setting_value
FROM settings
WHERE data_type = 'json'
AND jsonb_typeof(setting_value::jsonb) != 'object';
-- Should return 0 rows (or handle arrays)

-- Check required settings exist
SELECT setting_key FROM settings WHERE setting_key IN (
  'portal_system_prompt',
  'portal_api_params',
  'ai_service_mode'
);
-- Should return 3 rows
```

### 3. Validators Testing

**Arquivos:** `app/src/Validators/*`

**Test cases para cada validator:**

#### EmailValidator
```php
// Valid
"test@example.com" ✅
"user+tag@domain.co.uk" ✅

// Invalid
"invalid" ❌
"@domain.com" ❌
"user@" ❌
"" ❌
null ❌
```

#### JsonValidator
```php
// Valid
'{"key": "value"}' ✅
'[]' ✅
'{"nested": {"key": 123}}' ✅

// Invalid
'{invalid}' ❌
'not json' ❌
'' ❌
null ❌
```

#### UrlValidator
```php
// Valid
"https://example.com" ✅
"http://sub.domain.com/path?query=1" ✅

// Invalid
"not-a-url" ❌
"ftp://invalid" ❌ (if only http/https allowed)
"javascript:alert(1)" ❌ (XSS vector)
```

**Script de teste:**
```php
<?php
// test-validators.php
use Sunyata\Validators\{EmailValidator, JsonValidator, UrlValidator};

$tests = [
    ['validator' => 'EmailValidator', 'input' => 'test@example.com', 'expected' => true],
    ['validator' => 'EmailValidator', 'input' => 'invalid', 'expected' => false],
    // ... more tests
];

foreach ($tests as $test) {
    $validator = new $test['validator']();
    $result = $validator->validate($test['input']);

    if ($result['valid'] !== $test['expected']) {
        echo "❌ FAIL: {$test['validator']} - {$test['input']}\n";
    }
}

echo "✅ All validator tests passed\n";
```

### 4. Data Integrity Check

**Queries de verificação:**

```sql
-- 1. Orphaned records
-- Drafts sem user
SELECT COUNT(*) FROM drafts d
LEFT JOIN users u ON d.user_id = u.id
WHERE u.id IS NULL;

-- prompt_history sem user (pode ser OK se user deletado)
SELECT COUNT(*) FROM prompt_history ph
LEFT JOIN users u ON ph.user_id = u.id
WHERE u.id IS NULL;

-- 2. Invalid enum values (access_level)
SELECT DISTINCT access_level FROM users;
-- Should only be: 'admin', 'guest', null

-- 3. Null checks em campos NOT NULL
SELECT COUNT(*) FROM users WHERE email IS NULL;
-- Should be 0

SELECT COUNT(*) FROM canvas_templates WHERE slug IS NULL;
-- Should be 0

-- 4. Date sanity
SELECT COUNT(*) FROM prompt_history
WHERE created_at > NOW() OR created_at < '2020-01-01';
-- Should be 0 (no future dates, no ancient dates)

-- 5. Token/cost sanity
SELECT id, tokens_total, cost_usd
FROM prompt_history
WHERE tokens_total < 0 OR tokens_total > 1000000;
-- Check outliers

SELECT id, cost_usd
FROM prompt_history
WHERE cost_usd < 0 OR cost_usd > 100;
-- Check outliers

-- 6. JSON integrity
SELECT id, slug FROM canvas_templates
WHERE form_config IS NOT NULL
AND jsonb_typeof(form_config::jsonb) != 'object';
-- Should be 0

SELECT id FROM drafts
WHERE jsonb_typeof(draft_data) != 'object';
-- Should be 0
```

---

## Critérios de Aceitação

### ✅ Validation Report

**Formato:** `ai-comm/20260215-HHMM-de-codex-para-claude-validation-report.md`

**Seções:**
```markdown
## Schema Validation Results

### form_config JSONs
**Total canvases:** X
**Valid:** ✅ Y
**Invalid:** ❌ Z

**Issues Found:**
- Canvas slug-name: Duplicate element names
- Canvas slug-name: Missing required field 'pages'

### Database Schema
**Tables checked:** 6
**Missing indexes:** X (list them)
**Schema issues:** Y (list them)

### Validators
**Total validators tested:** 3
**Passed:** ✅ X/Y test cases
**Failed:** ❌ Z test cases

**Failures:**
- EmailValidator: input "xyz" expected false, got true

### Data Integrity
**Orphaned records:** X found
**Invalid enum values:** Y found
**Null violations:** Z found
**Date anomalies:** W found
**JSON integrity:** OK / ISSUES

## Recommendations

### Critical (Fix Before Deploy)
1. Add missing indexes
2. Fix orphaned records
3. Correct invalid JSONs

### Medium (Fix Soon)
1. Improve validator edge cases
2. Add constraints

### Low (Future)
1. Optimize queries
2. Add monitoring

## Summary
**Status:** ✅ PASS / ⚠️ CONDITIONAL PASS / ❌ FAIL
```

### ✅ Scripts de Validação

Salvar em: `tests/validation/`
- `schema-validator.php`
- `db-integrity-check.sql`
- `test-validators.php`

---

## Comandos Úteis

**Rodar validator script:**
```bash
ssh ovh 'ssh 192.168.100.10 "cd /var/www/sunyata/app && php tests/validation/schema-validator.php"'
```

**SQL queries:**
```bash
# Via psql (não funciona com peer auth, use PHP)
# Via PHP:
ssh ovh 'ssh 192.168.100.10 "cd /var/www/sunyata/app && php -r \"require bootstrap.php; ...\""'
```

**Verificar indexes:**
```bash
ssh ovh 'ssh 192.168.100.10 "cd /var/www/sunyata/app && php query-indexes.php"'
```

---

## Prazo

**3 horas** (até 14:00)

**Entregáveis:**
1. Validation report em `ai-comm/`
2. Scripts em `tests/validation/` (opcional mas recomendado)
3. Lista de issues críticos (se houver)

---

## Notas

- Você **NÃO** modifica o código, apenas valida
- Se encontrar **CRITICAL** issue (data corruption), avise imediatamente
- Foque em **data integrity** primeiro, depois performance
- Scripts podem ser reutilizados em CI/CD futuro

**Boa validação!** 📊🟢

**Claude - Coordenador** 🔵
