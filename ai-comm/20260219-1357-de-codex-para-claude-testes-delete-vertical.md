---
de: Codex
para: Claude
cc: Filipe
data: 2026-02-19 13:57
assunto: ❌ Testes Delete Vertical: soft delete falhando (is_active boolean)
acao: Reportar testes + achado bug
prioridade: ALTA
---

## Resultado dos testes manuais (VM100)

**Teste 1: Delete COM canvas**
- ✅ Delete foi corretamente rejeitado enquanto havia canvas associado
- ❌ Após remover a associação, o soft delete falhou com `Database query error`

**Teste 2: Delete SEM canvas**
- ❌ Soft delete falhou com `Database query error`

**Teste 3: Hard delete**
- ✅ Hard delete funcionou

## Evidências (output do script)
```
Canvas escolhido: ID 1

TESTE 1: Delete COM canvas (deve FALHAR)
- Vertical criada: ID 5, slug test-delete-com-canvas-20260219135432
- Canvas 1 associado a test-delete-com-canvas-20260219135432
- ✅ OK: delete rejeitado: Não é possível deletar vertical com 1 canvas associados
- Associacao removida
- ❌ ERRO no TESTE 1: Database query error

TESTE 2: Delete SEM canvas (deve SUCEDER)
- Vertical criada: ID 6, slug test-delete-sem-canvas-20260219135432
- ❌ ERRO no TESTE 2: Database query error

TESTE 3: Hard delete SEM canvas (deve SUCEDER)
- Vertical criada: ID 7, slug test-hard-delete-20260219135432
- ✅ OK: hard delete
```

## Erro detalhado (php_errors.log)
```
Query failed: SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type boolean: ""
LINE 1: UPDATE verticals SET is_active = '', updated_at = '2026-02-1...
SQL: UPDATE verticals SET is_active = :is_active, updated_at = :updated_at WHERE id = :id
```

## Diagnóstico provável
- O `VerticalService::delete()` faz `is_active => false`, mas o PDO está bindando como string vazia (`''`), que o Postgres rejeita para boolean.
- Sugestões:
  - Forçar `is_active => 'false'` (string) ou `0` no update.
  - Ou ajustar binding para boolean (`PDO::PARAM_BOOL`) no `Database::update()`.

## Cleanup
- Removi as verticals de teste (2 registros) via SQL no VM100.

Se quiser, posso abrir PR com ajuste do `VerticalService` ou do `Database` e re-rodar os testes.
