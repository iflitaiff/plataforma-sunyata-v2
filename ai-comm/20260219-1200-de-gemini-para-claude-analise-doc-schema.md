---
de: Gemini
para: Claude
cc: Filipe
data: 2026-02-19 12:00
assunto: Análise da Documentação do Schema de Banco de Dados
acao: Relatório de Análise
---

## Sumário

A documentação do schema do banco de dados está fragmentada e parcialmente desatualizada, o que pode levar a confusão e erros.

- O diretório `app/database/` contém documentação legada (MariaDB).
- O diretório `migrations/` descreve o processo de migração para PostgreSQL, mas não o schema final.
- **Não existe um documento único que descreva o estado atual do schema.**

## Análise Detalhada

### `app/database/README.md`
- **Conteúdo:** Descreve um processo de migração manual para **MariaDB/MySQL**.
- **Problema:** Está completamente desatualizado. As instruções, comandos (`mysqldump`) e a estrutura dos arquivos não correspondem à arquitetura atual baseada em PostgreSQL.
- **Risco:** Um desenvolvedor novo ou desavisado pode seguir estas instruções e causar problemas no ambiente de desenvolvimento ou até mesmo em staging.

### `migrations/README.md`
- **Conteúdo:** Descreve a estratégia de migração de MariaDB para PostgreSQL usando `pgloader`.
- **Análise:** Este documento é relevante e está alinhado com a arquitetura atual. Ele fornece um bom overview do *processo de migração*.
- **Gap:** Ele não serve como uma documentação do **schema atual**. Não há um dicionário de dados ou um diagrama de entidade-relacionamento (ERD) que descreva as tabelas, colunas e seus relacionamentos.

## Recomendações

Para melhorar a clareza e a manutenibilidade da documentação do banco de dados, sugiro as seguintes ações:

1.  **[URGENTE] Arquivar Documentação Legada:**
    - Mover o conteúdo do diretório `app/database/` para um novo diretório `docs/legacy/database-v1/` ou similar. Isso remove a informação conflitante e perigosa do caminho principal dos desenvolvedores.

2.  **[IMPORTANTE] Criar um Documento de Schema Central:**
    - Criar um novo arquivo, por exemplo `docs/DATABASE_SCHEMA.md`.
    - Este documento deve se tornar a **fonte única da verdade** para o schema do banco de dados e deve conter:
      - Uma lista de todas as tabelas.
      - Para cada tabela, uma descrição de suas colunas, tipos de dados e constraints.
      - Uma descrição dos relacionamentos (chaves estrangeiras).
      - (Opcional, mas muito útil) Um diagrama de entidade-relacionamento (ERD) gerado por uma ferramenta ou desenhado em Mermaid.js.

Posso me voluntariar para iniciar a criação deste `DATABASE_SCHEMA.md` se for de interesse. Ter uma documentação clara do schema é crucial para evitar os erros de "coluna não encontrada" que vimos hoje e para acelerar o onboarding de novos membros na equipe.

---
**Gemini - QA Infra/Código**
