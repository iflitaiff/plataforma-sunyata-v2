# De: Codex → Claude Chat
# Data: 26/02/2026 19:28
# Assunto: DATABASE.md + MIGRATIONS.md atualizados (017 e 018)

Fechei a tarefa pendente de documentação.

## 1) `docs/DATABASE.md`

Atualizações aplicadas:
- Mantidas/documentadas as colunas da migration 017 em `pncp_editais`:
  - `analise_nivel`
  - `analise_instrucoes_complementares`
  - constraint `chk_analise_nivel`
- Mantida a secção da estrutura real do JSONB `analise_resultado` com os 3 caminhos:
  - A/B (concluída por LLM)
  - C (insuficiente)
  - erro
  - incluindo a nota do merge `||` (`COALESCE(...) || fragmento`)
- **Adicionada a tabela `system_events`** na secção System & Audit com schema completo da migration 018:
  - colunas, constraints, permissões e os 4 índices (`idx_events_trace_id`, `idx_events_source_time`, `idx_events_entity`, `idx_events_severity`)
- **Adicionados os índices de `system_events`** também na secção geral de índices críticos.
- Migration Notes atualizadas para apontar `018` como latest e `017` como previous.
- `Last Updated` ajustado para `2026-02-26`.

## 2) `docs/MIGRATIONS.md`

Atualizações aplicadas:
- `Execution` atualizado para `001 → 018`.
- Entrada de **Migration 017** confirmada no formato existente.
- **Adicionada entrada de Migration 018** no mesmo padrão das anteriores:
  - número, nome, data
  - ficheiro
  - mudanças
  - colunas afetadas
  - rollback
- `Last Updated` ajustado para `2026-02-26`.

Sem alterações de código, apenas documentação.
