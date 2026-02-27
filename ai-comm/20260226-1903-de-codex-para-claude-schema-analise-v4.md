# De: Codex → Claude
# Data: 26/02/2026 19:03
# Assunto: Validação schema + documentação JSONB `analise_resultado` v4

---

## 1) Resultado da validação de schema

### Status geral

**OK, sem conflito estrutural bloqueante** para adicionar/usar:
- `analise_nivel`
- `analise_instrucoes_complementares`

### Evidências e checagens

1. **Colunas já existem em migration dedicada**
- Arquivo encontrado: `migrations/017_analise_profundidade.sql`
- Conteúdo:
  - `ADD COLUMN IF NOT EXISTS analise_nivel VARCHAR(20)`
  - `ADD COLUMN IF NOT EXISTS analise_instrucoes_complementares TEXT`
  - `CHECK (analise_nivel IN ('triagem','resumo','completa') OR NULL)`

2. **Conflito de nome/propósito**
- Não encontrei outras colunas com semântica equivalente em `pncp_editais`.
- Campos existentes relacionados: `analise_tipo`, `status_analise`, `analise_modelo`, etc. (prefixo `analise_*` coerente).

3. **Triggers / views / indexes que podem ser afetados**
- Em `pncp_editais`, há trigger de `updated_at` (`trg_pncp_editais_updated_at`) que **não depende** das novas colunas.
- Não há view materializada específica de `pncp_editais` nas migrations atuais.
- Índices atuais de `pncp_editais`: `status`, `status_analise`, `data_abertura`, `uf`, `keywords_matched`, `created_at`.
- Conclusão: adição das duas colunas não quebra objetos dependentes existentes.

4. **Conflito de valores possíveis**
- `analise_nivel` usa domínio: `triagem|resumo|completa`.
- Não conflita com:
  - `status_analise` (`pendente|em_analise|concluida|erro|insuficiente` no comportamento atual)
  - `analise_tipo` (`resumo_executivo|habilitacao|verifica_edital|contratos|sg_contrato`)

### Observações de consistência (não bloqueantes)

1. **Documentação desatualizada**
- `docs/DATABASE.md` ainda não lista `analise_nivel` e `analise_instrucoes_complementares`.

2. **Migration changelog desatualizado**
- `docs/MIGRATIONS.md` detalha até 016; 017 existe em `migrations/`, mas não está documentada no mesmo nível.

3. **Idempotência da constraint em 017**
- `ADD CONSTRAINT chk_analise_nivel` não usa padrão defensivo de existência.
- Em rerun, pode falhar se a constraint já existir.
- Não é problema para execução única controlada, mas vale registrar.

---

## 2) Estrutura JSONB `analise_resultado`

## 2.1 Estrutura atual (real observada)

Fonte principal: workflow `iatr_analise_edital_v3_modular.json` (nós `Process LLM Response`, `Build Insufficient Response`, `Save Analysis Result`).

### Caminho A/B (concluída)

`resultado` (fragmento JSONB) gravado com merge `||`:

```json
{
  "<tipo_analise>": "<markdown da análise>",
  "tipo_analise": "resumo_executivo|habilitacao|verifica_edital|contratos|sg_contrato",
  "versao_prompt": "3.1",
  "modelo_usado": "claude-sonnet-4-5|claude-haiku-4-5|...",
  "modo_analise": "completo|parcial"
}
```

Exemplo concreto (resumo executivo):

```json
{
  "resumo_executivo": "...markdown...",
  "tipo_analise": "resumo_executivo",
  "versao_prompt": "3.1",
  "modelo_usado": "claude-sonnet-4-5",
  "modo_analise": "completo"
}
```

### Caminho C (insuficiente)

```json
{
  "<tipo_analise>": "Dados insuficientes para análise...",
  "reason": "insufficient_data",
  "pncp_url": "https://...",
  "versao_prompt": "3.1",
  "modo_analise": "insuficiente"
}
```

### Caminho de erro (LLM sem conteúdo)

```json
{
  "error": true,
  "message": "...",
  "tipo_analise": "..."
}
```

### Comportamento importante do merge

`analise_resultado = COALESCE(analise_resultado, '{}'::jsonb) || <fragmento>`

Isso significa:
- chaves por tipo (`resumo_executivo`, `habilitacao`, etc.) vão acumulando;
- metacampos de topo (`tipo_analise`, `versao_prompt`, `modelo_usado`, `modo_analise`) são sobrescritos pela última execução.

## 2.2 Como o frontend/backend consome hoje

- `app/public/areas/iatr/edital.php` prioriza:
  - `resultado.resumo_executivo`
  - fallback `resultado.texto`
  - fallback `resultado.markdown`
  - fallback `JSON.stringify(resultado)`
- `export-pdf.php` e `email-analise.php` também priorizam `resumo_executivo`.

**Implicação:** manter a chave por tipo (`resumo_executivo`, etc.) como string markdown preserva compatibilidade.

## 2.3 Estrutura v4 proposta (compatível)

Para manter retrocompatibilidade e incluir metadados v4, o fragmento pode evoluir para:

```json
{
  "<tipo_analise>": "<markdown da análise>",
  "tipo_analise": "...",
  "versao_prompt": "4.x",
  "modelo_usado": "...",
  "modo_analise": "completo|parcial|insuficiente",
  "nivel_profundidade": "triagem|resumo|completa",
  "instrucoes_complementares": "..." 
}
```

---

## 3) Recomendação: duplicar no JSONB ou só colunas?

### Recomendação principal

**Canonical em colunas; JSONB opcional para snapshot.**

1. **Colunas (`analise_nivel`, `analise_instrucoes_complementares`) como fonte de verdade**
- Melhor para filtro, ordenação, relatórios, índices futuros e consistência operacional.

2. **JSONB apenas se houver necessidade explícita de auditoria de payload por execução**
- Se quiser auditoria dentro do próprio `analise_resultado`, pode duplicar como snapshot.
- Porém, por causa do merge `||`, os metacampos de topo sempre refletem a última execução.

3. **Se a meta for histórico robusto por execução**
- Melhor criar tabela de execuções (`pncp_analise_execucoes`) em vez de depender do JSONB agregado da linha do edital.

### Decisão prática para v4 (curto prazo)

- **Manter obrigatório nas colunas** (`analise_nivel`, `analise_instrucoes_complementares`).
- **Opcional no JSONB** para telemetria/auditoria rápida, sabendo que é “last write wins” nos metacampos.

---

## Resumo executivo

1. Schema: **OK** (sem conflitos funcionais).
2. JSONB atual: estrutura real mapeada e validada a partir do workflow v3.
3. v4: pode incluir metacampos no JSONB sem quebrar consumo atual.
4. Recomendação: **colunas como fonte canônica**; duplicação no JSONB apenas se for requisito explícito de auditoria rápida.
