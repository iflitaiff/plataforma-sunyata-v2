# System Events Queries (PostgreSQL 16)

Queries base para a página de System Logs e dashboard, compatíveis com a migration `018_system_events.sql`.

---

## 1) Listagem com filtros + paginação

Filtros suportados:
- `source`
- `severity`
- `entity_type` + `entity_id`
- período (`created_at` inicial/final)

```sql
-- Params:
-- $1 :: varchar(30)   -> source (NULL = todos)
-- $2 :: varchar(10)   -> severity (NULL = todos)
-- $3 :: varchar(30)   -> entity_type (NULL = todas)
-- $4 :: varchar(100)  -> entity_id (NULL = todos do entity_type)
-- $5 :: timestamptz   -> periodo_inicio (NULL = now() - 7 days)
-- $6 :: timestamptz   -> periodo_fim (NULL = now())
-- $7 :: integer       -> limit (1..500)
-- $8 :: integer       -> offset (>= 0)

SELECT
    se.id,
    se.trace_id,
    se.source,
    se.event_type,
    se.severity,
    se.entity_type,
    se.entity_id,
    se.summary,
    se.payload,
    se.duration_ms,
    se.created_at
FROM system_events se
WHERE ($1::varchar(30) IS NULL OR se.source = $1::varchar(30))
  AND ($2::varchar(10) IS NULL OR se.severity = $2::varchar(10))
  AND (
        $3::varchar(30) IS NULL
        OR (
            se.entity_type = $3::varchar(30)
            AND ($4::varchar(100) IS NULL OR se.entity_id = $4::varchar(100))
        )
      )
  AND se.created_at >= COALESCE($5::timestamptz, NOW() - INTERVAL '7 days')
  AND se.created_at <  COALESCE($6::timestamptz, NOW())
ORDER BY se.created_at DESC, se.id DESC
LIMIT GREATEST(1, LEAST($7::integer, 500))
OFFSET GREATEST($8::integer, 0);
```

Exemplo de teste rápido no `psql`:

```sql
PREPARE system_events_list (
    varchar(30), varchar(10), varchar(30), varchar(100),
    timestamptz, timestamptz, integer, integer
) AS
SELECT
    se.id, se.trace_id, se.source, se.event_type, se.severity,
    se.entity_type, se.entity_id, se.summary, se.payload, se.duration_ms, se.created_at
FROM system_events se
WHERE ($1::varchar(30) IS NULL OR se.source = $1::varchar(30))
  AND ($2::varchar(10) IS NULL OR se.severity = $2::varchar(10))
  AND (
        $3::varchar(30) IS NULL
        OR (
            se.entity_type = $3::varchar(30)
            AND ($4::varchar(100) IS NULL OR se.entity_id = $4::varchar(100))
        )
      )
  AND se.created_at >= COALESCE($5::timestamptz, NOW() - INTERVAL '7 days')
  AND se.created_at <  COALESCE($6::timestamptz, NOW())
ORDER BY se.created_at DESC, se.id DESC
LIMIT GREATEST(1, LEAST($7::integer, 500))
OFFSET GREATEST($8::integer, 0);

-- Source = 'n8n', severity = 'error', entity edital 148, últimos 30 dias
EXECUTE system_events_list(
    'n8n', 'error', 'edital', '148',
    NOW() - INTERVAL '30 days', NOW(),
    50, 0
);
```

---

## 2) Timeline de um trace

```sql
-- Param:
-- $1 :: uuid -> trace_id

SELECT
    se.id,
    se.trace_id,
    se.source,
    se.event_type,
    se.severity,
    se.entity_type,
    se.entity_id,
    se.summary,
    se.payload,
    se.duration_ms,
    se.created_at
FROM system_events se
WHERE se.trace_id = $1::uuid
ORDER BY se.created_at ASC, se.id ASC;
```

Exemplo:

```sql
PREPARE system_events_trace (uuid) AS
SELECT
    se.id, se.trace_id, se.source, se.event_type, se.severity,
    se.entity_type, se.entity_id, se.summary, se.payload, se.duration_ms, se.created_at
FROM system_events se
WHERE se.trace_id = $1::uuid
ORDER BY se.created_at ASC, se.id ASC;

EXECUTE system_events_trace('11111111-2222-4333-8444-555555555555');
```

---

## 3) Dashboard resumo (últimas 24h)

Métricas:
- total de eventos
- total de erros
- total de warnings
- análises concluídas (`iatr.analysis.completed`)
- custo total LLM em USD (somatório de `payload.custo_usd` para `iatr.llm.completed`)

```sql
SELECT
    COUNT(*)::bigint AS total_eventos_24h,
    COUNT(*) FILTER (WHERE se.severity = 'error')::bigint AS erros_24h,
    COUNT(*) FILTER (WHERE se.severity = 'warning')::bigint AS warnings_24h,
    COUNT(*) FILTER (WHERE se.event_type = 'iatr.analysis.completed')::bigint AS analises_concluidas_24h,
    COALESCE(
        SUM(
            CASE
                WHEN se.event_type = 'iatr.llm.completed'
                 AND se.payload ? 'custo_usd'
                 AND (se.payload->>'custo_usd') ~ '^-?[0-9]+(\\.[0-9]+)?$'
                THEN (se.payload->>'custo_usd')::numeric
                ELSE 0::numeric
            END
        ),
        0::numeric
    ) AS llm_custo_total_usd_24h
FROM system_events se
WHERE se.created_at >= NOW() - INTERVAL '24 hours';
```

---

## 4) Compatibilidade com migration 018

Validado com `migrations/018_system_events.sql`:
- Colunas usadas nas queries existem (`trace_id`, `source`, `event_type`, `severity`, `entity_type`, `entity_id`, `summary`, `payload`, `duration_ms`, `created_at`)
- Tipos estão alinhados com casts dos parâmetros
- Índices existentes suportam os filtros principais:
  - `idx_events_trace_id`
  - `idx_events_source_time`
  - `idx_events_entity`
  - `idx_events_severity`

Observação:
- O dashboard 24h pode fazer varredura temporal (não há índice dedicado só em `created_at`). Com volume estimado do spec (~1500 rows/dia), o custo é baixo.
