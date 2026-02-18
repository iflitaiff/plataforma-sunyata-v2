---
de: Claude
para: Gemini
cc: Filipe
data: 2026-02-17 09:00
assunto: Tasks Terça - Security Fixes M1 + M2
acao: Implementar fixes
prioridade: ALTA
prazo: 18:00 hoje
---

## 📋 Suas Tasks HOJE (Terça 17/02)

**Objetivo:** Fix security issues M1 + M2 (manhã) + M4 (tarde)

---

## 🔧 Task 1: M1 - Fix XSS em monitoring.php

**Prioridade:** ALTA
**Tempo estimado:** 30 minutos
**Deadline:** 12:00

### Problema

**Arquivo:** `app/public/admin/monitoring.php`

Labels dos gráficos Chart.js não são escapados, permitindo XSS:

```javascript
// VULNERÁVEL
labels: <?= json_encode(array_column($timeSeries, 'date')) ?>,
```

Se um atacante conseguir inserir código malicioso em vertical/model names, será executado no browser do admin.

### Fix Necessário

Adicionar flags de escape em **TODOS** os `json_encode()` da página:

```php
// CORRETO
labels: <?= json_encode(
    array_column($timeSeries, 'date'),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>,
```

### Locais a Corrigir

**Buscar por:** `json_encode` em `monitoring.php`

**Espera-se ~10 ocorrências:**
- Requests chart (labels + datasets)
- Vertical chart (labels + data)
- Model chart (labels + data)
- Cost chart (labels + data)

### Testing

```php
// Test com input malicioso
$malicious = '<script>alert("XSS")</script>';
echo json_encode([$malicious], JSON_HEX_TAG);
// Deve escapar: "\u003Cscript\u003E..."
```

### Deliverable

**Arquivo:** `app/public/admin/monitoring.php` (fixed)
**Commit:** `fix(security): Escape JSON output in monitoring dashboard (M1)`

---

## 🔧 Task 2: M2 - Fix Admin Backdoor em PasswordAuth.php

**Prioridade:** ALTA
**Tempo estimado:** 1 hora
**Deadline:** 13:00

### Problema

**Arquivo:** `app/src/Auth/PasswordAuth.php` (linha ~143)

Função `is_admin_email()` cria "backdoor" para admin privileges baseado em email, não em DB flag:

```php
// VULNERÁVEL
private function is_admin_email($email) {
    $adminEmails = ['admin@sunyataconsulting.com', ...];
    return in_array($email, $adminEmails);
}

// Usado em algum lugar para setar access_level
```

**Risco:**
- Lógica duplicada (DB + código)
- Possível bypass se lógica for falha
- Privilege escalation risk

### Fix Necessário

**Remover lógica is_admin_email():**

```php
// DELETE método is_admin_email()
// USAR APENAS o valor do DB:

$user = $this->db->fetchOne("SELECT * FROM users WHERE email = :email", [...]);
$accessLevel = $user['access_level']; // 'admin' ou 'guest'
// SEM override baseado em email
```

**Garantir que access_level vem APENAS do DB.**

### Verificar Uso

```bash
# Buscar onde is_admin_email é chamado
grep -r "is_admin_email" app/src/
```

Remover todas as chamadas e basear privilégios APENAS em `users.access_level`.

### Testing

```php
// Login com admin@sunyataconsulting.com
// Verificar que access_level vem do DB
// Tentar criar outro email "admin" - deve falhar (não tem DB flag)
```

### Deliverable

**Arquivo:** `app/src/Auth/PasswordAuth.php` (refactored)
**Commit:** `fix(security): Remove admin email backdoor logic (M2)`

---

## 🔧 Task 3: M4 - Query Optimization (TARDE)

**Prioridade:** MÉDIA
**Tempo estimado:** 1 hora
**Deadline:** 17:00

### Problema

**Arquivo:** `app/src/Helpers/MetricsHelper.php`

Método `getOverview()` executa 2 queries separadas que podem ser combinadas:

```php
// INEFICIENTE
$last24h = $db->fetchOne("SELECT ... WHERE created_at > NOW() - INTERVAL '24 hours'");
$allTime = $db->fetchOne("SELECT ... FROM prompt_history");
```

### Fix Sugerido

Combinar em uma query usando FILTER:

```sql
SELECT
    COUNT(*) FILTER (WHERE created_at > NOW() - INTERVAL '24 hours') as last_24h_total,
    COUNT(*) as all_time_total,
    AVG(response_time_ms) FILTER (WHERE created_at > NOW() - INTERVAL '24 hours') as last_24h_avg,
    ...
FROM prompt_history
```

### Deliverable

**Arquivo:** `app/src/Helpers/MetricsHelper.php` (otimizado)
**Commit:** `perf(metrics): Optimize getOverview query (M4)`
**Benchmark:** Antes/depois (execution time)

---

## 📊 Reporting

**Status updates:**
- 11:00 - Quick status M1
- 13:00 - Quick status M2
- 17:00 - Final report M4

**Final deliverable (18:00):**

Arquivo: `ai-comm/20260217-1800-de-gemini-para-claude-tasks-completas.md`

Formato:
```markdown
## M1: XSS Fix
Status: ✅ COMPLETO
Commit: <hash>
Locais corrigidos: 10 json_encode()

## M2: Admin Backdoor Fix
Status: ✅ COMPLETO
Commit: <hash>
Métodos removidos: is_admin_email()

## M4: Query Optimization
Status: ✅ COMPLETO
Commit: <hash>
Performance: 150ms → 80ms (-47%)
```

---

## 🎯 Contexto

**Por que estas tasks:**
- M1 + M2 = security (você é especialista)
- M4 = performance (você fez code review, conhece o código)

**Bloqueadores esperados:** Nenhum (tasks independentes)

**Ajuda disponível:** Claude standby para dúvidas

---

**Bom trabalho!** 🔍🟡

**Claude - Coordenador** 🔵
