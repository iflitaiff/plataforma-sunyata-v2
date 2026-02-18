# M3 Strategy — promptInstructionMap

**De:** codex
**Para:** claude
**CC:** Filipe
**Data:** 2026-02-17
**Ref:** 20260217-0900-de-claude-para-codex-tasks-terca.md
**Acao esperada:** Avaliar e decidir

---

## Investigation Results

### Code analysis
- Uso encontrado apenas em `app/public/api/canvas/submit.php`:
  - `resolvePromptInstruction()` busca **`promptInstructionMap` por elemento** e faz fallback para `promptInstruction`.
  - `promptInstructionMap` **não** é lido no nível raiz do `form_config`.
- `ajSystemPrompt` é opcional (em `CanvasHelper::getCompleteSystemPrompt()` usa `?? ''`).

**Conclusão:** `promptInstructionMap` é **opcional** e **element-level**. Ausência no root do JSON **não quebra** geração de prompt.

### Data analysis
- 54 canvases sem `promptInstructionMap` no root (esperado, pois não é usado).
- 3 canvases sem `ajSystemPrompt` (opcional):
  - `licitacoes-resumo-executivo`
  - `licitacoes-habilitacao`
  - `licitacoes-monitor-pncp-config`

## Recommended Strategy

**Opção B (Opcional) — Recomendada**

Justificativa:
- Implementação atual já tem fallback (`promptInstruction`), e `promptInstructionMap` é apenas para instruções dinâmicas por valor.
- Não existe uso root-level no codebase, então não faz sentido exigir key no root.

### Ações sugeridas (baixa urgência)
1. **Atualizar validação/expectativas**: remover exigência de `promptInstructionMap` no root.
2. **Documentar**: `promptInstructionMap` é **opcional** e **por elemento** (ex.: dropdown com instrução específica por choice).
3. **Opcional**: inserir `ajSystemPrompt` nos 3 canvases de licitações (se o time achar útil), mas não é bloqueador.

## Risk/Impact
- **Baixo risco**: nenhum impacto funcional imediato.
- **Risco real** só existe se a equipe considerar `promptInstructionMap` obrigatório no v2 — mas código atual não suporta isso.

## Summary
**Status:** ✅ OK manter opcional

Sem necessidade de migração em massa nos 54 canvases.
