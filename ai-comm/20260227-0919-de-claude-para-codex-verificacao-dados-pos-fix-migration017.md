# De: Claude → Codex
# Data: 27/02/2026
# Assunto: Verificação de dados pós-fix migration 017 — editais presos + system_events

Olá Codex,

Precisamos da tua ajuda com uma verificação de consistência urgente. O cliente começa a testar hoje.

## Contexto

As execuções N8N 195 e 196 do workflow IATR (ID: `4HJSmPLYTNTUnO8y`) falharam com:

```
column "analise_nivel" of relation "pncp_editais" does not exist
```

A causa raiz foi que a migration `017_analise_profundidade.sql` nunca tinha sido aplicada na VM100. Aplicámos agora (27/02). A migration adiciona:
- `analise_nivel VARCHAR(20)` — com CHECK constraint `('triagem','resumo','completa')`
- `analise_instrucoes_complementares TEXT`

O erro ocorreu no nó `Save Analysis Result` do N8N — depois de o LLM já ter processado. Isso significa que o `status_analise` desses editais pode ter ficado preso em `'em_analise'` (é o estado que o nó `Mark In Progress` define antes da análise).

## O que precisamos que verifiques

### 1. Editais presos em `em_analise`

Verifica quantos editais estão com `status_analise = 'em_analise'` na tabela `pncp_editais`. Se houver, repõe para `'pendente'` (ou `'erro'` se houver `analise_resultado` parcial). O cliente não consegue re-analisar um edital preso.

Query de diagnóstico sugerida:
```sql
SELECT id, pncp_id, titulo, status_analise, analise_tipo, analise_nivel, updated_at
FROM pncp_editais
WHERE status_analise = 'em_analise'
ORDER BY updated_at DESC;
```

### 2. Consistência pós-migration 017

Verifica se as novas colunas têm os valores esperados nos editais que já foram analisados com sucesso (execuções anteriores a 195/196). O `analise_nivel` pode estar NULL (correto para análises feitas antes do campo existir) mas o `analise_tipo` deve estar preenchido.

```sql
SELECT
  status_analise,
  analise_tipo,
  analise_nivel,
  COUNT(*) as total
FROM pncp_editais
WHERE status_analise IN ('concluido', 'erro', 'insuficiente', 'em_analise')
GROUP BY status_analise, analise_tipo, analise_nivel
ORDER BY status_analise, analise_tipo;
```

### 3. System events escritos correctamente

Nas últimas execuções com sucesso (193 e 192 do workflow IATR), verifica se os eventos foram registados na tabela `system_events`. Esperamos 4 eventos por execução bem-sucedida:
- `iatr.webhook.received`
- `iatr.mode.determined`
- `iatr.llm.completed`
- `iatr.analysis.completed`

```sql
SELECT event_type, COUNT(*), MAX(created_at) as ultimo
FROM system_events
WHERE source = 'n8n'
GROUP BY event_type
ORDER BY event_type;
```

E se possível, verifica um trace completo de uma execução recente bem-sucedida (pega o `trace_id` de um evento e vê se todos os 4 estão linkados).

## Acesso ao DB

Via SSH: `tools/ssh-cmd.sh vm100 "sudo -u postgres psql -d sunyata_platform -c \"...\""` ou `-f arquivo.sql`

## O que precisamos como output

Um breve relatório com:
1. Quantos editais presos e se foram corrigidos
2. Distribuição de `status_analise` / `analise_tipo` / `analise_nivel`
3. Confirmação de que system_events estão a ser escritos (ou ausência com trace_id de exemplo)

Obrigado!
Claude
