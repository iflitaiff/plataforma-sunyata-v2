-- Migration 018: System Events
-- Centralized event log with trace correlation

BEGIN;

CREATE TABLE IF NOT EXISTS system_events (
    id BIGSERIAL PRIMARY KEY,
    trace_id UUID,                          -- correlação entre componentes (mesmo UUID na cadeia inteira)
    source VARCHAR(30) NOT NULL,            -- 'portal', 'n8n', 'fastapi', 'litellm', 'cron'
    event_type VARCHAR(80) NOT NULL,        -- 'iatr.analysis.requested', 'iatr.pdf.extracted', etc.
    severity VARCHAR(10) NOT NULL DEFAULT 'info',  -- 'debug', 'info', 'warning', 'error'
    entity_type VARCHAR(30),                -- 'edital', 'workflow', 'user', 'monitor'
    entity_id VARCHAR(100),                 -- id do edital, id do workflow, email do user, etc.
    summary TEXT,                           -- descrição curta e legível do evento
    payload JSONB,                          -- dados flexíveis (tokens, custos, erros, duração, etc.)
    duration_ms INTEGER,                    -- duração da operação (se aplicável)
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Índice principal: buscar todos os eventos de uma transação
CREATE INDEX IF NOT EXISTS idx_events_trace_id ON system_events(trace_id) WHERE trace_id IS NOT NULL;

-- Filtrar por componente + tempo (página de logs)
CREATE INDEX IF NOT EXISTS idx_events_source_time ON system_events(source, created_at DESC);

-- Buscar eventos de uma entidade específica ("tudo que aconteceu com edital 148")
CREATE INDEX IF NOT EXISTS idx_events_entity ON system_events(entity_type, entity_id, created_at DESC);

-- Filtrar só erros/warnings (alertas)
CREATE INDEX IF NOT EXISTS idx_events_severity ON system_events(severity, created_at DESC)
    WHERE severity IN ('warning', 'error');

-- Permissões para o n8n escrever eventos
GRANT INSERT, SELECT ON system_events TO n8n_worker;
GRANT USAGE, SELECT ON SEQUENCE system_events_id_seq TO n8n_worker;

COMMIT;

-- ROLLBACK
-- DROP INDEX IF EXISTS idx_events_severity;
-- DROP INDEX IF EXISTS idx_events_entity;
-- DROP INDEX IF EXISTS idx_events_source_time;
-- DROP INDEX IF EXISTS idx_events_trace_id;
-- DROP TABLE IF EXISTS system_events;
