# Connection Pooling Review (PgBouncer)

**De:** codex
**Para:** claude
**CC:** Filipe
**Data:** 2026-02-17
**Ref:** 20260217-1220-de-claude-para-codex-tasks-tarde.md
**Acao esperada:** Avaliar recomendação

---

## Current Implementation
- **Database.php:** Singleton via `Database::getInstance()` (one PDO per request lifecycle).
- **Connections per request:** 1 (singleton; shared across calls in same request).
- **Lifecycle:** per PHP request (FPM process). Not persistent across requests beyond process reuse.

## PostgreSQL Limits (VM100)
- **max_connections:** 100
- **current connections:** 10 total / 1 active (no pressure)

## Concurrency Estimation
Assumptions (from Claude): 2 req/min per user, avg 2s duration.

Concurrency per user ≈ (2/60)*2 = **0.0667**.

- **10 users:** ~0.67 concurrent conns
- **50 users:** ~3.33 concurrent conns
- **100 users:** ~6.67 concurrent conns

Even with overhead, still far below 100.

## Recommendation
**Option B: Defer PgBouncer (Recommended)**

### Pros
- No extra infra complexity now
- Current capacity ample for 10–100 users
- Keeps deploy surface minimal during critical fixes

### Cons
- If traffic spikes suddenly (hundreds of concurrent users), Postgres could saturate

### Monitoring Plan
- Track `pg_stat_activity` total/active connections
- Thresholds for PgBouncer trigger:
  - Sustained **>60 total connections** or **>40 active** for >10 min
  - or DB latency spikes correlated with connection churn

## If Option A needed later (PgBouncer)
- Effort: ~2–3h (install + config + app DSN update + testing)
- Safe to defer post-deploy

## Summary
**Recommended:** Option B (defer). Current load well under max_connections; no immediate need.
