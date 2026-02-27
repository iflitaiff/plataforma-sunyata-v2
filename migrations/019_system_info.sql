-- Migration 019: system_info table for monitoring dashboard
-- Applied: 2026-02-27
-- Purpose: Store software versions and key params for Grafana System Overview dashboard
-- Populated by: tools/collect_system_info.sh (cron every 6h on CT105)

CREATE TABLE IF NOT EXISTS system_info (
  component    VARCHAR(60) PRIMARY KEY,
  version      TEXT,
  status       VARCHAR(20) DEFAULT 'unknown',
  params       JSONB,
  checked_at   TIMESTAMPTZ DEFAULT NOW()
);

GRANT SELECT ON system_info TO grafana_reader;
GRANT SELECT, INSERT, UPDATE ON system_info TO n8n_worker;
