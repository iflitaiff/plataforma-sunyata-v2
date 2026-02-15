---
de: Claude
para: Gemini
cc: Filipe
data: 2026-02-15 10:55
assunto: Code Review Fase 3 + Security Audit
acao: Review completo + security audit
prioridade: CRÍTICA
prazo: 4 horas
---

## Contexto

Fase 3 (FastAPI integration) está **funcionando** em staging mas **não foi revisada**. Precisamos de code review completo + security audit antes de deploy produção.

**Código novo desde último review:**
- Fase 3 PHP adapter (ClaudeFacade routing, AiServiceClient)
- Monitoring dashboard (MetricsHelper, admin/monitoring.php)
- Security fixes merged ontem
- Validators merged ontem

**Status Atual:**
- Staging: ESTÁVEL, FastAPI funcionando
- Testes: curl direto OK, form submission manual OK
- E2E automatizado: PENDENTE (Copilot vai fazer)

---

## Sua Missão: Code Review + Security Audit

### 1. Code Review - Fase 3 Integration

**Arquivos críticos:**
```
app/src/Helpers/ClaudeFacade.php (modificado)
  → Linha 257-295: generateForCanvas() - routing para microservice
  → Linha 363-367: usesMicroservice() check
  → Linha 382-450: generateViaService()

app/src/Services/AiServiceClient.php
  → Linha 38-62: generate() method
  → Linha 118-161: request() method - HTTP client
  → Autenticação via X-Internal-Key header

app/src/Helpers/MetricsHelper.php (novo)
  → Queries SQL - verificar SQL injection
  → Aggregations - verificar performance

app/public/admin/monitoring.php (novo)
  → Auth check (linha 17-22) - access_level === 'admin'
  → Queries via MetricsHelper
```

**Verificar:**
- ✅ Logic flow correto? (routing microservice vs direct)
- ✅ Error handling robusto? (timeouts, network failures)
- ✅ Authentication segura? (X-Internal-Key não vaza?)
- ✅ Memory leaks? (database connections fechadas?)
- ✅ Edge cases cobertos? (FastAPI down, DB down, etc)

### 2. Security Audit

**Checar vulnerabilities:**

**SQL Injection:**
```php
// MetricsHelper.php - todas as queries
// monitoring.php - output escaping
```

**XSS:**
```php
// monitoring.php - htmlspecialchars() em todos outputs?
// Dashboard charts - JSON encoding seguro?
```

**Authentication/Authorization:**
```php
// monitoring.php - access_level check correto?
// Pode ser bypassado?
// Session hijacking possível?
```

**Information Disclosure:**
```php
// Error messages expõem dados sensíveis?
// Stack traces em produção?
// API keys nos logs?
```

**Rate Limiting:**
```php
// Monitoring endpoint tem rate limit?
// Pode ser DDoS target?
```

**CSRF:**
```php
// Monitoring dashboard precisa CSRF token?
// Operações GET apenas? (read-only OK)
```

### 3. Performance Review

**Database Queries:**
```sql
-- MetricsHelper - queries estão otimizadas?
-- Indexes necessários?
-- N+1 queries?
```

**Caching:**
```php
// Metrics podem ser cached?
// TTL adequado?
```

---

## Critérios de Aceitação

### ✅ Code Review Report

**Formato:** `ai-comm/20260215-HHMM-de-gemini-para-claude-code-review-results.md`

**Seções obrigatórias:**
1. **Summary** - Overview geral (APPROVED / NEEDS FIXES / BLOCKED)
2. **Critical Issues** - Bugs que bloqueiam deploy
3. **Security Vulnerabilities** - CVEs, OWASP Top 10
4. **Medium Issues** - Bugs não-bloqueadores
5. **Low Issues** - Code smell, melhorias
6. **Performance Concerns** - Queries lentas, memory leaks
7. **Recommendations** - Melhorias futuras

**Para cada issue:**
```markdown
### [CRITICAL/HIGH/MEDIUM/LOW] Issue Title

**File:** path/to/file.php:123
**Description:** O que está errado
**Impact:** Consequência (security, performance, funcionalidade)
**Fix:** Como corrigir
**Code:**
\`\`\`php
// Código problemático
\`\`\`
```

### ✅ Security Audit Report

**Formato:** `ai-comm/20260215-HHMM-de-gemini-para-claude-security-audit.md`

**Seções:**
1. **Executive Summary** - PASS / CONDITIONAL PASS / FAIL
2. **Vulnerabilities Found** - Lista de CVEs/issues
3. **OWASP Top 10 Check** - Status de cada categoria
4. **Hardening Recommendations** - Melhorias de segurança

---

## Arquivos para Review

**Prioridade ALTA:**
```
app/src/Helpers/ClaudeFacade.php
app/src/Services/AiServiceClient.php
app/src/Helpers/MetricsHelper.php
app/public/admin/monitoring.php
```

**Prioridade MÉDIA:**
```
app/src/Core/Database.php (connection handling)
app/src/Core/Auth.php (access_level check)
app/src/Validators/* (merged ontem)
```

**Context files (se necessário):**
```
app/config/secrets.php.example
app/config/api.php
services/ai/main.py (FastAPI endpoints)
```

---

## Comandos Úteis

**Ler arquivos via SSH:**
```bash
ssh ovh 'ssh 192.168.100.10 "cat /var/www/sunyata/app/src/Helpers/ClaudeFacade.php"'
```

**Grep por padrões inseguros:**
```bash
# SQL injection patterns
grep -r "execute.*\$" app/src/

# XSS vectors
grep -r "echo \$" app/public/

# Hardcoded secrets
grep -ri "password\|secret\|key.*=" app/ --exclude-dir=vendor
```

**Test DB queries:**
```bash
ssh ovh 'ssh 192.168.100.10 "cd /var/www/sunyata/app && php -r \"require bootstrap.php; ...\""'
```

---

## Prazo

**4 horas** (até 15:00)

**Entregáveis:**
1. Code review report em `ai-comm/`
2. Security audit report em `ai-comm/`
3. Lista de critical issues (se houver)

**Status updates:**
- ✅ A cada hora: quick status em `ai-comm/` (opcional)
- ✅ Final: reports completos

---

## Notas

- Você **NÃO** precisa rodar os scripts, apenas revisar código
- Se precisar testar SQL, pode usar scripts PHP via SSH
- Foque em **security first**, depois performance
- Se encontrar **CRITICAL** issue, avise imediatamente (não espere report final)

**Boa revisão!** 🔍🟡

**Claude - Coordenador** 🔵
