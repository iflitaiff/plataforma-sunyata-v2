-- Migration 013: PNCP Editais table
-- Purpose: Store bid data from PNCP API (written by N8N, read by Portal)
-- Contract: N8N writes via PostgreSQL node, Portal reads via PHP
-- Date: 2026-02-19

BEGIN;

-- Main table: bid data from PNCP + AI analysis fields
CREATE TABLE IF NOT EXISTS pncp_editais (
    id SERIAL PRIMARY KEY,
    pncp_id TEXT UNIQUE NOT NULL,            -- PNCP unique identifier
    numero TEXT,                              -- Bid number (e.g. "PE 001/2026")
    titulo TEXT NOT NULL,                     -- Bid title
    objeto TEXT,                              -- Full description/object
    orgao TEXT,                               -- Government body name
    orgao_cnpj TEXT,                          -- Government body CNPJ
    uf TEXT,                                  -- State (e.g. "RJ", "SP")
    municipio TEXT,                           -- City
    modalidade TEXT,                          -- Bid type (pregao, concorrencia, etc)
    valor_estimado NUMERIC,                   -- Estimated value in BRL
    data_abertura TIMESTAMPTZ,                -- Opening date
    data_encerramento TIMESTAMPTZ,            -- Closing date
    url_pncp TEXT,                            -- Direct PNCP URL
    status TEXT DEFAULT 'aberto',             -- aberto, encerrado, suspenso, etc
    keywords_matched TEXT[],                  -- Which keywords triggered this match
    raw_data JSONB,                           -- Full API response for future use

    -- AI Analysis fields (N8N fills via UPDATE after webhook trigger)
    status_analise TEXT DEFAULT 'pendente',   -- pendente | em_analise | concluida | erro
    analise_resultado JSONB,                  -- AI analysis result (markdown text + metadata)
    analise_modelo TEXT,                      -- Model used (e.g. "claude-haiku-4-5")
    analise_tokens INTEGER,                   -- Tokens consumed
    analise_erro TEXT,                        -- Error message if status_analise = 'erro'
    analise_concluida_em TIMESTAMPTZ,         -- When analysis completed

    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes for common query patterns
CREATE INDEX idx_pncp_editais_status ON pncp_editais(status);
CREATE INDEX idx_pncp_editais_status_analise ON pncp_editais(status_analise);
CREATE INDEX idx_pncp_editais_data_abertura ON pncp_editais(data_abertura DESC);
CREATE INDEX idx_pncp_editais_uf ON pncp_editais(uf);
CREATE INDEX idx_pncp_editais_keywords ON pncp_editais USING gin(keywords_matched);
CREATE INDEX idx_pncp_editais_created ON pncp_editais(created_at DESC);

-- Grant access to n8n_worker (CT104)
GRANT SELECT, INSERT, UPDATE ON pncp_editais TO n8n_worker;
GRANT USAGE, SELECT ON SEQUENCE pncp_editais_id_seq TO n8n_worker;

-- Trigger to auto-update updated_at
CREATE OR REPLACE FUNCTION update_pncp_editais_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_pncp_editais_updated_at
    BEFORE UPDATE ON pncp_editais
    FOR EACH ROW
    EXECUTE FUNCTION update_pncp_editais_updated_at();

COMMIT;

-- Rollback:
-- DROP TRIGGER IF EXISTS trg_pncp_editais_updated_at ON pncp_editais;
-- DROP FUNCTION IF EXISTS update_pncp_editais_updated_at();
-- DROP TABLE IF EXISTS pncp_editais;
