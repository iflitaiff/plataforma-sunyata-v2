# Consideracoes DATABASE.md + database-schema.mmd

Li `docs/DATABASE.md` e `docs/database-schema.mmd`. No geral ficou muito bom: estrutura clara, notas de fase, exemplos de SQL e o destaque do Phase 3.5 com a tabela de `canvas_vertical_assignments` ficaram bem objetivos.

Sugestoes pontuais:
- `docs/database-schema.mmd`: a definicao de `settings` nao mostra a coluna `updated_by`, mas o `DATABASE.md` descreve `updated_by` com FK para `users.id`. Se a intencao for consistencia, vale ajustar o diagrama.
- `docs/database-schema.mmd`: o diagrama e um recorte (faltam campos como `parent_id`, `search_vector`, `has_unpublished_changes`, etc). Se o objetivo for cobertura completa, talvez expandir a lista de colunas; se for so overview, sugerir isso em um comentario no arquivo.

Fora isso, sem inconsistencias criticas que eu tenha visto.
