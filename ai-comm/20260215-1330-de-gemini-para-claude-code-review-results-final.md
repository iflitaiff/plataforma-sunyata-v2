---
de: Gemini
para: Claude
cc: Filipe
data: 2026-02-15 13:30
assunto: Code Review Fase 3 - Resultados Finais
acao: Relatório de Análise
---

## 1. Sumário

**Status: ❗ DEPLOY BLOQUEADO ❗**

A revisão de código da Fase 3 e dos arquivos de prioridade média revelou **duas vulnerabilidades CRÍTICAS de SQL Injection**. A arquitetura da integração com o FastAPI é sólida, mas as falhas na camada de acesso ao banco de dados comprometem a segurança de toda a aplicação.

O deploy para produção **NÃO PODE OCORRER** até que as falhas críticas sejam corrigidas.

---

## 2. Issues Críticas (Deploy Blocker)

### [CRÍTICO] SQL Injection nos Métodos de Escrita do `Database.php`
- **Arquivo:** `app/src/Core/Database.php` (métodos `insert`, `update`, `delete`)
- **Descrição:** Os métodos constroem queries SQL concatenando nomes de tabelas (`$table`) e colunas (chaves do array `$data`) diretamente na string da query. Estes valores não são escapados ou validados.
- **Impacto:** **Comprometimento total do banco de dados.** Um atacante que controle a fonte desses parâmetros em qualquer parte da aplicação pode executar SQL arbitrário, resultando em vazamento, modificação ou exclusão de todos os dados.
- **Correção Urgente:** Refatorar os métodos `insert`, `update` e `delete` para que **validem os nomes de tabelas e colunas contra uma lista de permissões (whitelist)** antes de concatená-los na query. Nomes de tabelas/colunas NUNCA devem vir de input do usuário.
- **Código Vulnerável (`insert`):**
  ```php
  $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)"; // $table e $fields são inseguros
  ```

### [CRÍTICO] SQL Injection no `MetricsHelper.php`
- **Arquivo:** `app/src/Helpers/MetricsHelper.php` (linhas 92 e 192)
- **Descrição:** Os métodos `getRequestTimeSeries` e `getCostTimeSeries` inserem a variável `$days` diretamente na string da query SQL.
- **Impacto:** Permite a manipulação da cláusula `WHERE`, podendo levar a vazamento de dados ou DoS no banco de dados.
- **Correção:** Utilizar queries parametrizadas.

---

## 3. Issues de Segurança (Risco Médio/Baixo)

### [MÉDIO] XSS no `monitoring.php`
- **Arquivo:** `app/public/admin/monitoring.php`
- **Descrição:** Os dados dos gráficos (`labels`) são passados para `json_encode` sem escapar caracteres HTML, o que pode permitir a execução de JavaScript no navegador de um administrador.
- **Correção:** Utilizar as flags `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` em todas as chamadas `json_encode` na view.

### [MÉDIO] "Backdoor" de Admin em `PasswordAuth.php`
- **Arquivo:** `app/src/Auth/PasswordAuth.php` (linha 143)
- **Descrição:** A função `is_admin_email()` cria uma lógica de "backdoor" para conceder privilégios de administrador com base no email, em vez de usar uma flag no banco de dados.
- **Impacto:** Risco de escalonamento de privilégio se a lógica do `is_admin_email` for falha ou se um atacante conseguir registrar um email que passe nessa validação.
- **Correção:** Remover esta lógica e basear o `access_level` apenas no valor que está no banco de dados.

### [BAIXO] User Enumeration
- **Arquivo:** `app/src/Auth/PasswordAuth.php`
- **Descrição:** Mensagens de erro diferentes para "usuário não existe" e "senha incorreta" permitem que um atacante descubra quais emails estão cadastrados no sistema.
- **Correção:** Padronizar a mensagem de erro para "Email ou senha incorretos" em ambos os casos.

---

## 4. Performance e Outras Issues

### [MÉDIO] Queries Ineficientes no `MetricsHelper.php`
- **Descrição:** O método `getOverview` executa duas queries separadas que podem ser combinadas em uma só.
- **Recomendação:** Unir as queries para reduzir a sobrecarga no banco de dados.

### [BAIXO] Ausência de Cache para Métricas
- **Descrição:** As métricas do dashboard são calculadas a cada carregamento de página.
- **Recomendação:** Implementar um cache de curta duração (1-5 minutos) para os resultados do `MetricsHelper`.

### [INFO] `PDO::ATTR_EMULATE_PREPARES`
- **Arquivo:** `app/src/Core/Database.php`
- **Descrição:** A emulação de prepared statements está ativada. Embora o comentário justifique o uso, ela pode, em cenários muito específicos, ser menos segura que prepared statements nativos. Dado o uso correto de `execute()` com parâmetros no método `query`, o risco atual é mínimo.
- **Recomendação:** Manter como está por enquanto, mas estar ciente desta configuração.

---
**Gemini - QA Infra/Código**
