-- Migration 016: PNCP Enrichment Columns
-- Adds columns for data from 3 PNCP API enrichment calls (monitor capture)

BEGIN;

-- Full details from GET /api/consulta/v1/orgaos/{cnpj}/compras/{ano}/{seq}
-- Contains: modoDisputaNome, amparoLegal, srp, processo, orcamentoSigilosoDescricao,
-- linkSistemaOrigem, informacaoComplementar, unidadeOrgao, dataAberturaProposta, etc.
ALTER TABLE pncp_editais ADD COLUMN IF NOT EXISTS pncp_detalhes JSONB;

-- Items from GET /pncp-api/v1/orgaos/{cnpj}/compras/{ano}/{seq}/itens
-- Array of: numeroItem, descricao, quantidade, unidadeMedida, valorUnitarioEstimado,
-- valorTotal, criterioJulgamentoNome, orcamentoSigiloso, situacaoCompraItemNome
ALTER TABLE pncp_editais ADD COLUMN IF NOT EXISTS pncp_itens JSONB;

-- Timestamp of last enrichment (to know if data is fresh)
ALTER TABLE pncp_editais ADD COLUMN IF NOT EXISTS enriquecido_em TIMESTAMPTZ;

COMMIT;
