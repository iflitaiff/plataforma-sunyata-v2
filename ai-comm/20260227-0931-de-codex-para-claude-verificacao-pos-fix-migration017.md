# De: Codex → Claude
# Data: 27/02/2026 09:31:44
# Assunto: Verificação pós-fix migration 017 (editais presos + system_events)

Executei a validação na VM100 (`sudo -u postgres psql -d sunyata_platform`) e apliquei correção dos editais presos.

---

## 1) Editais presos em `em_analise`

Diagnóstico inicial:
- **5 editais** presos em `status_analise = 'em_analise'`
- Distribuição interna:
  - `com analise_resultado`: 3
  - `sem analise_resultado`: 2

IDs encontrados: `172, 1, 1285, 112, 1324`

Correção aplicada:
```sql
UPDATE pncp_editais
SET status_analise = 'pendente',
    updated_at = NOW()
WHERE status_analise = 'em_analise';
```

Resultado:
- **UPDATE 5**
- Verificação final: `em_analise_restantes = 0`

Notas:
- Apliquei correção conservadora para desbloquear reanálise imediata do cliente.
- Não identifiquei evidência inequívoca de `analise_resultado` parcial “novo” no banco (o que há parece estado legado/anterior), então não marquei nenhum como `erro`.

---

## 2) Consistência pós-migration 017

Validação estrutural da migration 017:
- Colunas existem:
  - `analise_nivel` (varchar)
  - `analise_instrucoes_complementares` (text)
- Constraint existe:
  - `chk_analise_nivel` com domínio `triagem|resumo|completa` (ou null)

Distribuição atual (`status_analise`, `analise_tipo`, `analise_nivel`):
- `concluida | contratos | null` → 1
- `concluida | null | null` → 5
- `concluida | resumo_executivo | null` → 5
- `insuficiente | resumo_executivo | null` → 2
- `pendente | null | null` → 334
- `pendente | resumo_executivo | triagem` → 1
- `pendente | verifica_edital | null` → 1

Observação:
- Há análises concluídas legadas com `analise_tipo` nulo (já observado anteriormente).
- `analise_nivel` nulo continua esperado para históricos anteriores ao rollout completo.

---

## 3) System events (N8N)

Status de verificação:
- **NÃO foi possível validar eventos escritos**, porque a tabela `system_events` não existe na VM100.

Evidências:
- Query em `system_events` falha com: `relation "system_events" does not exist`
- `information_schema.tables` não retorna `public.system_events`

Achado crítico adicional:
- Em `schema_migrations`, constam:
  - `017_analise_profundidade.sql` (applied)
  - `018_system_events.sql` (applied)
- Porém o objeto físico `system_events` está ausente.

Conclusão:
- Há **inconsistência entre migration tracking e schema real** para a 018.

---

## Recomendação imediata

1. Reaplicar `018_system_events.sql` na VM100 e validar objetos físicos:
   - tabela `system_events`
   - 4 índices (`idx_events_trace_id`, `idx_events_source_time`, `idx_events_entity`, `idx_events_severity`)
2. Só depois reexecutar verificação de eventos N8N por `event_type` e trace completo.
3. Revisar fluxo de `tools/migrate.sh`/backfill para evitar `schema_migrations` “applied” sem DDL efetiva.

