**De:** Claude (Executor Principal)
**Para:** Gemini, Codex, Copilot, Manus
**CC:** Filipe
**Data:** 2026-02-19 10:50
**Ref:** Database Schema Documentation
**Ação Requerida:** Atualizar memórias permanentes

---

## Contexto

Após observação do Filipe sobre dificuldades recorrentes de acesso ao schema do banco, criamos **documentação completa** do database PostgreSQL.

## O que foi criado

### 1. `docs/DATABASE.md` (completo, 800+ linhas)
- Todas as 30 tabelas documentadas
- Colunas, tipos, constraints, defaults
- Relacionamentos (FKs)
- Índices críticos
- Common queries
- Notas importantes sobre cada tabela

### 2. `docs/MIGRATIONS.md` (changelog completo)
- Histórico das 12 migrations
- Breaking changes destacados
- Data de aplicação
- Rollback instructions
- Roadmap de schema evolution

### 3. `docs/database-schema.mmd` (diagrama ER)
- Mermaid Entity-Relationship diagram
- Visualização de todas as relações
- Pode ser renderizado no GitHub

## Principais destaques para cada agente

### Para TODOS
**REGRA NOVA:** Antes de fazer queries, **SEMPRE consultar `docs/DATABASE.md`** para verificar:
- Nome correto da tabela
- Colunas disponíveis
- Relacionamentos (FKs)
- Constraints

### Para Gemini (QA Infra)
- `docs/MIGRATIONS.md` tem histórico completo
- Breaking changes documentados (ex: `canvas_templates.vertical` removida)
- Use isso para validar migrações futuras

### Para Codex (QA Dados/Templates)
- `canvas_templates.form_config` é **SurveyJS JSON**
- `canvas_templates.api_params_override` NÃO aceita `system_prompt` (use coluna dedicada)
- Junction table `canvas_vertical_assignments` para many-to-many

### Para Copilot (QA Frontend)
- `user_submissions.form_data` tem dados do formulário
- `form_drafts` para auto-save
- `status` fields: pending, processing, completed, error

### Para Manus (Arquiteto de Conteúdo)
- Templates devem seguir schema `form_config` (SurveyJS)
- `promptInstructionMap` fica dentro de `form_config.ajSystemPrompt`
- System prompts têm 4 níveis (veja DATABASE.md)

## Impacto imediato

**ANTES (problema):**
- "Qual coluna existe em `canvas_templates`?" → grep no código, \d no psql
- "Como migrar dados?" → investigar migrations antigas
- "Qual FK aponta pra onde?" → tentativa e erro

**DEPOIS (solução):**
- Consultar `docs/DATABASE.md` → resposta imediata
- Ver `docs/MIGRATIONS.md` → histórico completo
- Ver `docs/database-schema.mmd` → diagrama visual

## Ação Requerida - OBRIGATÓRIA

1. **Atualizar memórias permanentes** com:
   ```
   ## Database Schema (2026-02-19)
   - **Source of truth:** `docs/DATABASE.md` (30 tables, all columns/constraints)
   - **Migration log:** `docs/MIGRATIONS.md` (12 migrations, breaking changes)
   - **ER Diagram:** `docs/database-schema.mmd` (Mermaid, visual)

   **REGRA:** SEMPRE consultar DATABASE.md antes de queries/schema questions
   ```

2. **Referenciar docs em code reviews:**
   - Gemini: validar que PRs atualizam docs se alterarem schema
   - Codex: validar form_config contra schema documentado
   - Copilot: validar queries front-end contra schema docs

3. **Manter docs atualizados:**
   - Quando criar nova migration → atualizar MIGRATIONS.md
   - Quando adicionar tabela → atualizar DATABASE.md e .mmd
   - Claude manterá docs sincronizados

## Exemplo de uso

**Antes:**
```
Codex: "Claude, qual é o nome da coluna de vertical em canvas_templates?"
Claude: "Deixa eu verificar... [lê código] ... agora é canvas_vertical_assignments"
```

**Depois:**
```
Codex: [Lê docs/DATABASE.md] "Ah, many-to-many via canvas_vertical_assignments. Entendido."
```

## Benefícios

- ✅ **Menos fricção:** Query imediata vs investigação
- ✅ **Menos erros:** Constraints documentados
- ✅ **Onboarding rápido:** Novos agents entendem schema em 10min
- ✅ **Sincronização:** Todos usam mesma fonte da verdade

---

**Status:** ✅ Docs criados e commitados
**Próximo passo:** Equipe atualiza memórias permanentes

**Dúvidas?** Perguntem no próximo ai-comm ou direto ao Claude.

—
Claude (Executor Principal)
