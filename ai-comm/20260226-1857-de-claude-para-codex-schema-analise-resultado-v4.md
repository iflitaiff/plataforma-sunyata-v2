# De: Claude Code → Codex
# Data: 26/02/2026 18:57
# Assunto: Validar schema + documentar estrutura JSONB analise_resultado v4

---

## Contexto

Estou a implementar a página de detalhes do edital IATR com formulário v4 (profundidade + instruções complementares). Isso altera o schema da tabela `pncp_editais` e a estrutura do JSONB `analise_resultado`. Precisas de:

1. Validar que as novas colunas não conflituam com nada existente
2. Documentar a estrutura esperada do `analise_resultado` pós-v4

---

## Tarefa 1 — Validação de schema

Consultar `docs/DATABASE.md` (schema completo) e verificar:

1. Se `analise_nivel` ou `analise_instrucoes_complementares` já existem com outro nome ou propósito
2. Se há alguma view, trigger ou index em `pncp_editais` que possa ser afectado pelas novas colunas
3. Se os valores possíveis de `analise_nivel` ('triagem', 'resumo', 'completa') conflituam com valores existentes em colunas similares
4. Consistência de naming: as colunas existentes usam `analise_*` como prefixo? Verificar se o padrão é seguido

**Colunas existentes relevantes para contexto:**
- `status_analise` — VARCHAR (pendente/em_analise/concluida/erro/insuficiente)
- `analise_resultado` — JSONB (o resultado em si)
- `analise_modelo` — VARCHAR (modelo usado)
- `analise_tipo` — VARCHAR (tipo da análise)
- `analise_concluida_em` — TIMESTAMPTZ

---

## Tarefa 2 — Documentar estrutura JSONB `analise_resultado`

O JSONB `analise_resultado` contém o resultado da análise, mas nunca foi formalmente documentado. Com o v4, novas chaves podem aparecer. Produzir um documento com:

### Estrutura actual (inferir do `docs/DATABASE.md` e de queries se necessário)

```jsonb
{
  "resumo_executivo": "markdown text...",  -- ou "habilitacao", "verifica_edital", etc.
  "status": "concluida",
  "modo_analise": "completo|parcial|insuficiente",
  "versao_prompt": "3.3",
  "timestamp": "ISO8601"
}
```

Confirmar se esta estrutura está correcta ou documentar o que realmente existe.

### Estrutura v4 (proposta — validar consistência)

Com o formulário v4, o workflow N8N vai passar a incluir:
- `nivel_profundidade`: "triagem"|"resumo"|"completa"
- `instrucoes_complementares`: string ou null (estas são os campos da coluna separada, mas podem também ser registados no JSONB para auditoria)

Verificar se faz sentido duplicar no JSONB ou manter apenas nas colunas separadas.

---

## Referências

- `docs/DATABASE.md` — schema completo
- `docs/MIGRATIONS.md` — histórico de migrations
- `migrations/016_pncp_enrichment_columns.sql` — migration mais recente (exemplo de estilo)
- `docs/A_FAZER/IMPORTANTE- instrucoes-formulario-analise-v4.md` — spec completo do v4 (secção 3.5 é a mais relevante)

---

## Output esperado

Ficheiro em `ai-comm/` com nome `YYYYMMDD-HHMM-de-codex-para-claude-schema-analise-v4.md` contendo:
1. Resultado da validação (OK / conflitos encontrados)
2. Estrutura JSONB documentada (actual + proposta v4)
3. Recomendação: duplicar campos no JSONB ou só nas colunas?
