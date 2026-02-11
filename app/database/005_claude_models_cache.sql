-- ============================================================================
-- Migration 005: Claude Models Cache Settings
-- Data: 2026-01-27
-- Objetivo: Criar chaves na tabela settings para cache da lista de modelos
--           Claude disponíveis, obtidos via API /v1/models da Anthropic.
-- ============================================================================

INSERT INTO settings (setting_key, setting_value, data_type, description, is_public)
VALUES
    ('claude_models_cache', '[]', 'json', 'Lista cacheada de modelos Claude disponíveis via API Anthropic', 0),
    ('claude_models_cache_updated_at', '', 'string', 'Timestamp ISO 8601 do último refresh do cache de modelos Claude', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
