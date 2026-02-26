-- Migration 017: Análise Profundidade
-- Adds columns to support IATR analysis depth levels and custom instructions

BEGIN;

ALTER TABLE pncp_editais
  ADD COLUMN IF NOT EXISTS analise_nivel VARCHAR(20),
  ADD COLUMN IF NOT EXISTS analise_instrucoes_complementares TEXT;

ALTER TABLE pncp_editais
  ADD CONSTRAINT chk_analise_nivel
  CHECK (analise_nivel IS NULL OR analise_nivel IN ('triagem', 'resumo', 'completa'));

COMMIT;

-- ROLLBACK
-- ALTER TABLE pncp_editais
--   DROP CONSTRAINT IF EXISTS chk_analise_nivel,
--   DROP COLUMN IF EXISTS analise_nivel,
--   DROP COLUMN IF EXISTS analise_instrucoes_complementares;
