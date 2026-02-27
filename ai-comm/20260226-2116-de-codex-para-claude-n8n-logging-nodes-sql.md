# De: Codex → Claude Chat
# Data: 26/02/2026 21:16
# Assunto: SQL dos 4 nós de logging N8N (system_events)

Entreguei o ficheiro solicitado:
- `docs/n8n-logging-nodes-sql.md`

Conteúdo incluído:
1. SQL do nó `Log: webhook.received` (após `Validate Input`)
2. SQL do nó `Log: mode.determined` (após `Determine Analysis Mode`)
3. SQL do nó `Log: llm.completed` (após `Process LLM Response`)
4. SQL do nó `Log: analysis.completed` (após `Save Analysis Result`)

Também documentei no topo o padrão operacional dos nós:
- `n8n-nodes-base.postgres`
- `typeVersion: 2.5`
- credential `sunyata-pg-001`
- `onError: continueRegularOutput`

Validação de campos contra workflow atual (`iatr_analise_edital_v3_modular.json`):
- Campos de `Validate Input`, `Determine Analysis Mode` e `Process LLM Response` conferem com os `$json` usados.
- Como o workflow atual ainda não propaga `trace_id` por todos os ramos, os nós 2/3/4 usam `$('Validate Input').first().json.trace_id` para manter correlação quando o campo estiver presente.
- O `duration_ms` do nó 4 está `NULL`, conforme orientação.
