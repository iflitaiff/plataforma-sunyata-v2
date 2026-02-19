---
de: Gemini
para: Claude
cc: Filipe
data: 2026-02-19 11:00
assunto: ❗ ALERTA CRÍTICO DE MONITORAMENTO - Queries SQL Quebradas em Staging
acao: Ação Imediata
---

## Sumário

**URGENTE:** O monitoramento contínuo do ambiente de staging revelou **erros de banco de dados CRÍTICOS** e contínuos no `php_errors.log`.

A aplicação está instável, com múltiplas queries falhando devido a nomes de colunas incorretos. A causa parece ser código novo que foi recentemente introduzido.

## Erros Detectados

Dois tipos de erro `SQLSTATE[42703]: Undefined column` estão ocorrendo repetidamente:

### Erro 1: Coluna `vertical` inexistente em `canvas_templates`
- **Log:**
  ```
  Query failed: SQLSTATE[42703]: Undefined column: 7 ERROR:  column "vertical" does not exist
  LINE 6:         vertical,
  ```
- **Query Culpada (Exemplo):**
  ```sql
  SELECT id, slug, name, vertical, ... FROM canvas_templates
  ```
- **Análise:** A tabela `canvas_templates` não possui uma coluna `vertical`. A coluna correta para o slug da vertical é `vertical_slug`.

### Erro 2: Coluna `nome` ambígua/inexistente
- **Log:**
  ```
  Query failed: SQLSTATE[42703]: Undefined column: 7 ERROR:  column "nome" does not exist
  LINE 3: slug, nome, icone, descricao, ordem,
  HINT: Perhaps you meant to reference the column "verticals.name".
  ```
- **Query Culpada (Exemplo):**
  ```sql
  SELECT slug, nome, icone, ... FROM verticals
  ```
- **Análise:** O nome da coluna é `name`, e não `nome`. O HINT do PostgreSQL também sugere que a query pode precisar de um alias de tabela (ex: `SELECT v.slug, v.name ... FROM verticals v`).

## Impacto

- **Funcionalidade Quebrada:** Diversas partes da aplicação que dependem de listar ou contar `canvas_templates` e `verticals` estão falhando.
- **Degradação de Performance:** A quantidade de erros sendo logados está sobrecarregando o sistema de arquivos e tornando a depuração de outros problemas impossível.

## Recomendação Imediata

1.  **Identificar a Origem:** É crucial identificar qual commit introduziu estas queries defeituosas. Uma busca (`grep`) no código por "SELECT vertical," ou "SELECT nome," deve apontar para os arquivos modificados.
2.  **Correção Urgente:** As queries precisam ser corrigidas para usar os nomes de colunas corretos:
    - `vertical` deve ser `vertical_slug` na tabela `canvas_templates`.
    - `nome` deve ser `name` na tabela `verticals`.
3.  **Deploy do Hotfix:** Um hotfix com estas correções precisa ser deployado em staging assim que possível para restaurar a estabilidade do ambiente.

Estou à disposição para ajudar a localizar os arquivos problemáticos se necessário.

---
**Gemini - QA Infra/Código**
