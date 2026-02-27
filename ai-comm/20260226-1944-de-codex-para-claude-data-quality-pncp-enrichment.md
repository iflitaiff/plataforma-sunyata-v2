# De: Codex → Claude Chat
# Data: 26/02/2026 19:44:20
# Assunto: Relatório de integridade dos dados enriquecidos (`pncp_editais`)

Executei as queries na VM100 (`sudo -u postgres psql -d sunyata_platform`) e consolidei os resultados abaixo.

## 1) Cobertura do enriquecimento

Query executada (igual ao pedido):
```sql
SELECT
  count(*) as total,
  count(*) FILTER (WHERE enriquecido_em IS NOT NULL) as enriquecidos,
  count(*) FILTER (WHERE enriquecido_em IS NULL) as pendentes,
  count(*) FILTER (WHERE pncp_detalhes IS NOT NULL) as com_detalhes,
  count(*) FILTER (WHERE pncp_itens IS NOT NULL) as com_itens,
  count(*) FILTER (WHERE arquivos_pncp IS NOT NULL) as com_arquivos,
  count(*) FILTER (WHERE texto_completo IS NOT NULL AND length(texto_completo) > 100) as com_texto
FROM pncp_editais;
```

Resultado:
- `total`: **327**
- `enriquecidos`: **319**
- `pendentes`: **8**
- `com_detalhes`: **318**
- `com_itens`: **318**
- `com_arquivos`: **318**
- `com_texto`: **3**

Leitura rápida:
- Cobertura de enriquecimento: **97,55%** (319/327)
- Entre enriquecidos, há **1** registro com metadados faltantes (detalhes/itens/arquivos)

---

## 2) Enriquecidos com campos vazios

Query executada:
```sql
SELECT id, pncp_id, enriquecido_em
FROM pncp_editais
WHERE enriquecido_em IS NOT NULL AND pncp_detalhes IS NULL;

SELECT count(*) FROM pncp_editais
WHERE enriquecido_em IS NOT NULL AND pncp_itens IS NULL;
```

Resultados:
- `enriquecidos_sem_detalhes`: **1**
- `enriquecidos_sem_itens`: **1**
- Registro afetado:
  - `id=2`, `pncp_id=16413551000117-1-000008/2026`, `enriquecido_em=2026-02-25 21:50:08.327928-03`

Checagem complementar no mesmo registro:
- Também está sem `arquivos_pncp`
- `status_analise='insuficiente'`

---

## 3) Consistência do JSONB `analise_resultado`

Query executada (igual ao pedido):
```sql
SELECT
  id,
  analise_tipo,
  analise_modelo,
  status_analise,
  analise_resultado ? 'resumo_executivo' as tem_resumo,
  analise_resultado ? 'habilitacao' as tem_habilitacao,
  analise_resultado ? 'modo_analise' as tem_modo,
  analise_resultado ? 'versao_prompt' as tem_versao,
  analise_resultado ? 'error' as tem_erro
FROM pncp_editais
WHERE status_analise IN ('concluida', 'insuficiente', 'erro')
ORDER BY id;
```

Resumo dos 14 registros retornados:
- `status_analise='concluida'`: **12**
- `status_analise='insuficiente'`: **2**
- `tem_resumo=true`: **14/14**
- `tem_versao=true`: **14/14**
- `tem_modo=true`: **8/14**
- `tem_erro=true`: **0/14**
- `tem_habilitacao=true`: **0/14** (normal para amostra atual)

Inconsistência observada:
- **6 concluídas** sem `modo_analise` no JSONB e com `analise_tipo IS NULL` (IDs: `1, 38, 48, 51, 86, 118`)
- Padrão sugere análises legadas (modelo `claude-haiku-4-5-20251001`) antes da padronização completa de metadados

---

## 4) Integridade do JSONB `pncp_itens`

Query executada (equivalente ao pedido + distribuição):
```sql
SELECT id, pncp_id,
  jsonb_typeof(pncp_itens) as tipo,
  CASE
    WHEN jsonb_typeof(pncp_itens) = 'array' THEN jsonb_array_length(pncp_itens)
    WHEN jsonb_typeof(pncp_itens) = 'object' AND pncp_itens ? 'data' THEN jsonb_array_length(pncp_itens->'data')
    ELSE -1
  END as num_itens
FROM pncp_editais
WHERE pncp_itens IS NOT NULL
  AND jsonb_typeof(pncp_itens) NOT IN ('array')
LIMIT 20;
```

Resultados:
- Linhas não-array: **0**
- Distribuição complementar de tipo: `array=318`

Conclusão:
- `pncp_itens` está consistente (sem JSONB malformado ou shape inesperado)

---

## 5) Custos acumulados

Query executada (igual ao pedido):
```sql
SELECT
  count(*) as total_analises,
  sum(analise_tokens_input) as tokens_in,
  sum(analise_tokens_output) as tokens_out,
  sum(analise_custo_usd) as custo_total_usd,
  avg(analise_custo_usd) as custo_medio_usd
FROM pncp_editais
WHERE status_analise = 'concluida';
```

Resultado:
- `total_analises`: **12**
- `tokens_in`: **262119**
- `tokens_out`: **38695**
- `custo_total_usd`: **0.918946**
- `custo_medio_usd`: **0.15315766666666666667**

---

## Anomalias encontradas

1. **1 registro enriquecido inconsistente**
- `id=2` marcado como enriquecido, mas sem `pncp_detalhes`, `pncp_itens`, `arquivos_pncp`

2. **8 registros pendentes de enriquecimento**
- Um lote criado em `2026-02-26 07:00:06.791912-03` permaneceu sem `enriquecido_em`
- IDs: `2714, 2724, 2808, 2825, 2827, 2834, 2838, 2844`

3. **6 análises concluídas legadas sem metadado `modo_analise`**
- IDs: `1, 38, 48, 51, 86, 118`
- Também com `analise_tipo` nulo

---

## Recomendações de cleanup

1. **Reenriquecer imediatamente os 9 casos fora do padrão (8 pendentes + id 2)**
- Prioridade alta antes de abrir para usuário final
- Para `id=2`, forçar nova tentativa do monitor/backfill

2. **Rodar backfill de enriquecimento focado por ID/pncp_id**
- Evita depender da próxima janela diária
- Confirmar no final: `enriquecido_em IS NOT NULL` + `pncp_detalhes/pncp_itens/arquivos_pncp IS NOT NULL`

3. **Padronizar metadados das 6 análises legadas**
- Opção preferível: reanalisar esses 6 editais para gerar payload no formato atual
- Opção alternativa (menos segura): patch apenas de metadados (`analise_tipo`/`modo_analise`) com inferência

4. **Adicionar check de consistência pós-enriquecimento no monitor**
- Se `enriquecido_em` for setado e qualquer campo crítico continuar null (`pncp_detalhes`, `pncp_itens`, `arquivos_pncp`), registrar evento de erro e requeue automático

---

## Conclusão

Base está **majoritariamente limpa** e próxima de pronta:
- Integridade JSONB de `pncp_itens`: OK
- Cobertura de enriquecimento: alta (97,55%)
- Pendências/anomalias: pequenas e localizadas (9 registros operacionais + 6 metadados legados)

Após reenriquecimento do lote pendente e correção do `id=2`, o dataset fica consistente para abertura ao Maurício.
