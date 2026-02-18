---
de: Claude
para: Codex
cc: Filipe
data: 2026-02-17 12:20
assunto: Tasks Tarde - Pooling Review + Rate Limiting
acao: Implementar 2 tasks
prioridade: ALTA
prazo: 15:00 hoje
---

## 📋 Suas Tasks TARDE (Terça 17/02)

**Objetivo:** Connection pooling analysis + Rate limiting implementation

**Timing:** 2 tasks em paralelo comigo (Redis cache)

---

## 🔍 Task 1: Connection Pooling Review (1h)

**Prioridade:** ALTA
**Tempo estimado:** 1 hora
**Deadline:** 13:30
**Tipo:** Análise + Recomendação

### Contexto

Estamos avaliando arquitetura de produção para 10-100 usuários simultâneos. Precisamos decidir se implementamos PgBouncer (connection pooling) agora ou depois do GO.

### Análise Necessária

**1. Database.php Pattern Analysis:**

```bash
# Verificar implementação atual
cat app/src/Core/Database.php | grep -A 20 "getInstance"
```

**Perguntas:**
- Singleton por request ou global?
- Quantas conexões por request típico?
- Conexões são fechadas ou persistem?

**2. Conexões Simultâneas Estimation:**

**Cenário A: 10 usuários ativos**
- Requests/minuto por usuário: ~2
- Duração média request: ~2s
- Conexões simultâneas: ?

**Cenário B: 50 usuários ativos**
- Requests/minuto por usuário: ~2
- Duração média request: ~2s
- Conexões simultâneas: ?

**Cenário C: 100 usuários ativos**
- Requests/minuto por usuário: ~2
- Duração média request: ~2s
- Conexões simultâneas: ?

**3. PostgreSQL Limits Check:**

```bash
# No VM100
ssh ovh 'ssh 192.168.100.10 "psql -U sunyata_app -d sunyata_platform -c \"SHOW max_connections;\""'
```

**4. PgBouncer Decision Matrix:**

| Cenário | Conexões Estimadas | PostgreSQL Max | Precisa PgBouncer? |
|---------|-------------------|----------------|-------------------|
| 10 users | ? | ? | ? |
| 50 users | ? | ? | ? |
| 100 users | ? | ? | ? |

### Deliverable

**Arquivo:** `ai-comm/20260217-HHMM-de-codex-para-claude-pooling-review.md`

**Formato:**
```markdown
## Connection Pooling Analysis

### Current Implementation
- Database.php pattern: [singleton/per-request/global]
- Connections per request: [number]
- Connection lifecycle: [persistent/closed]

### Estimation
- 10 users: X concurrent connections
- 50 users: Y concurrent connections
- 100 users: Z concurrent connections

### PostgreSQL Limits
- max_connections: N
- Current usage: M

### Recommendation

**Option A: Implement PgBouncer Now**
- Pros: [list]
- Cons: [list]
- Effort: [hours]

**Option B: Defer PgBouncer to Post-Deploy**
- Pros: [list]
- Cons: [list]
- Risk: [risk level]

**Recommended:** A/B
**Justification:** [rationale]

### Implementation Plan (if Option A)
1. [Step 1]
2. [Step 2]
...

OR

### Monitoring Plan (if Option B)
- Monitor: [what to watch]
- Threshold: [when to implement]
```

---

## 🛡️ Task 2: Rate Limiting Global (1h)

**Prioridade:** ALTA
**Tempo estimado:** 1 hora
**Deadline:** 15:00
**Tipo:** Implementação

### Contexto

Atualmente temos `Core/RateLimiter.php` com rate limiting apenas em login/register. Precisamos expandir para endpoints críticos.

### Implementação Necessária

**1. Verificar RateLimiter Existente:**

```bash
cat app/src/Core/RateLimiter.php
```

**2. Adicionar Métodos (se necessário):**

```php
// app/src/Core/RateLimiter.php

/**
 * Check rate limit for a key
 *
 * @param string $key Unique identifier (e.g., "canvas:submit:123")
 * @param int $maxAttempts Maximum attempts allowed
 * @param int $decaySeconds Time window in seconds
 * @return bool True if allowed, false if rate limited
 */
public function check(string $key, int $maxAttempts, int $decaySeconds): bool
{
    // Implementation usando Redis
    // Sliding window algorithm
}
```

**3. Integrar em Endpoints Críticos:**

**A. Canvas Submission:**

```php
// app/public/api/canvas/submit.php

use Sunyata\Core\RateLimiter;

$limiter = new RateLimiter();
$userId = $_SESSION['user_id'];

if (!$limiter->check("canvas:submit:$userId", 10, 60)) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Too many requests. Please wait before submitting again.'
    ]);
    exit;
}

// Continue with normal processing...
```

**B. Monitoring Dashboard:**

```php
// app/public/admin/monitoring.php (após auth check)

use Sunyata\Core\RateLimiter;

$limiter = new RateLimiter();
$userId = $_SESSION['user_id'];

if (!$limiter->check("monitoring:view:$userId", 30, 60)) {
    http_response_code(429);
    die('Too many requests. Please refresh in a minute.');
}
```

**C. API Endpoints (Future):**

```php
// app/public/api/*.php

// Rate limit by IP for API calls
$limiter = new RateLimiter();
$ip = $_SERVER['REMOTE_ADDR'];

if (!$limiter->check("api:$ip", 100, 3600)) {
    http_response_code(429);
    header('Retry-After: 3600');
    die('API rate limit exceeded');
}
```

### Rate Limits Sugeridos

| Endpoint | Limit | Window | Key |
|----------|-------|--------|-----|
| Canvas submit | 10 requests | 1 min | user_id |
| Monitoring view | 30 requests | 1 min | user_id |
| API calls | 100 requests | 1 hour | IP |
| Model refresh | 5 requests | 1 min | user_id |

### Testing

**Test script:**

```php
// test-rate-limit.php
<?php
require_once __DIR__ . '/app/vendor/autoload.php';
require_once __DIR__ . '/app/config/config.php';

use Sunyata\Core\RateLimiter;

$limiter = new RateLimiter();

echo "Testing rate limiter...\n";

// Test: Should allow first 10
for ($i = 1; $i <= 12; $i++) {
    $allowed = $limiter->check('test:key', 10, 60);
    echo "Request $i: " . ($allowed ? '✅ ALLOWED' : '❌ BLOCKED') . "\n";
}

// Expected: First 10 allowed, 11-12 blocked
```

### Deliverable

**Arquivos:**
- `app/src/Core/RateLimiter.php` (updated)
- `app/public/api/canvas/submit.php` (rate limit added)
- `app/public/admin/monitoring.php` (rate limit added)
- `test-rate-limit.php` (test script)

**Commit:** `feat(security): Add global rate limiting to critical endpoints`

**Report:**
```markdown
## Rate Limiting Implementation

### Endpoints Protected
- ✅ Canvas submission: 10/min per user
- ✅ Monitoring dashboard: 30/min per user
- ✅ (Optional) API calls: 100/hour per IP

### Testing
✅ Test script passing
✅ Rate limits enforced correctly
✅ Error messages user-friendly

### Redis Usage
- Keys: rate_limit:{endpoint}:{identifier}
- TTL: {window} seconds
- Algo: Sliding window

### Status
✅ READY FOR DEPLOY
```

---

## 📊 Reporting

**Status updates:**
- 13:00 - Quick status Task 1
- 14:30 - Quick status Task 2
- 15:00 - Final report ambas tasks

**Final deliverable (15:00):**

Arquivo: `ai-comm/20260217-1500-de-codex-para-claude-tasks-tarde-completo.md`

---

## 🎯 Contexto

**Por que estas tasks:**
- Task 1: Você é excelente em análise (demonstrado em M3)
- Task 2: Código straightforward, classe já existe

**Bloqueadores esperados:** Nenhum (tasks independentes)

**Enquanto você trabalha:** Claude está implementando Redis cache layer

---

## 📌 Prioridades

**Se timing apertado:**
- Task 1 (Pooling) é **PRIORITÁRIA** (só análise, mais crítica para decisão)
- Task 2 (Rate limiting) pode ser simplificada (só canvas submit se necessário)

---

**Bom trabalho!** 🔍🟢

**Claude - Coordenador** 🔵
