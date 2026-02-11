# Migrações do Banco de Dados

## 📋 Migrations Disponíveis

### 001_vertical_system.sql
**Data:** 2025-10-09
**Status:** Pronta para aplicar

**O que faz:**
1. ✅ Adiciona campos `selected_vertical`, `completed_onboarding`, `is_demo` na tabela `users`
2. ✅ Cria tabela `user_profiles` (dados do onboarding)
3. ✅ Cria tabela `vertical_access_requests` (solicitações de acesso a verticais)
4. ✅ Cria tabela `tool_access_logs` (analytics de ferramentas)
5. ✅ Cria tabela `tool_versions` (controle de versões de ferramentas)
6. ✅ Atualiza ENUMs de verticais em `contracts` e `prompt_dictionary`
7. ✅ Popula `tool_versions` com ferramentas existentes
8. ✅ Cria view `v_tool_access_stats` para facilitar consultas

**Novas Verticais:**
- `docencia` (Docência)
- `pesquisa` (Pesquisa)
- `ifrj_alunos` (IFRJ - Alunos)
- `juridico` (Jurídico)
- `vendas` (Vendas)
- `marketing` (Marketing)
- `licitacoes` (Licitações)
- `rh` (Recursos Humanos)
- `geral` (Geral)

---

## 🚀 Como Aplicar uma Migration

### Opção 1: Via Script PHP (Recomendado)

```bash
# No diretório raiz do projeto
php scripts/apply-migration.php 001
```

O script vai:
1. Verificar se a migration existe
2. Mostrar preview do que será executado
3. Pedir confirmação
4. Aplicar em uma transação (rollback automático se der erro)
5. Registrar no histórico de migrations

### Opção 2: Via MySQL CLI

```bash
# Conectar no MySQL
mysql -u SEU_USUARIO -p SEU_BANCO_DE_DADOS

# Executar migration
source config/migrations/001_vertical_system.sql
```

### Opção 3: Via phpMyAdmin (Hostinger)

1. Acesse phpMyAdmin no painel Hostinger
2. Selecione seu banco de dados
3. Vá em "SQL"
4. Cole o conteúdo do arquivo `001_vertical_system.sql`
5. Clique em "Executar"

---

## ⚠️ IMPORTANTE: Backup Antes de Aplicar

**SEMPRE faça backup do banco antes de aplicar migrations!**

### Via Hostinger:
1. Painel Hostinger → Databases → phpMyAdmin
2. Selecione o banco
3. Clique em "Export"
4. Escolha "Quick" ou "Custom"
5. Baixe o arquivo SQL

### Via SSH:
```bash
mysqldump -u USUARIO -p BANCO_DE_DADOS > backup_$(date +%Y%m%d_%H%M%S).sql
```

---

## 📊 Verificar Migrations Aplicadas

```sql
SELECT * FROM migrations ORDER BY applied_at DESC;
```

---

## 🔄 Rollback (Desfazer Migration)

Se precisar reverter, você precisará:
1. Restaurar o backup do banco
2. OU criar uma migration de rollback manualmente

**Exemplo de rollback da 001:**

```sql
-- Remover tabelas criadas
DROP TABLE IF EXISTS tool_versions;
DROP TABLE IF EXISTS tool_access_logs;
DROP TABLE IF EXISTS vertical_access_requests;
DROP TABLE IF EXISTS user_profiles;
DROP VIEW IF EXISTS v_tool_access_stats;

-- Remover colunas adicionadas
ALTER TABLE users
  DROP COLUMN selected_vertical,
  DROP COLUMN completed_onboarding,
  DROP COLUMN is_demo;

-- Reverter ENUMs (voltar para valores antigos)
ALTER TABLE contracts
  MODIFY COLUMN vertical ENUM('sales', 'marketing', 'customer_service', 'hr', 'general') NOT NULL;

ALTER TABLE prompt_dictionary
  MODIFY COLUMN vertical ENUM('sales', 'marketing', 'customer_service', 'hr', 'general') NOT NULL;
```

---

## 🧪 Testar Migration Localmente Primeiro

Se você tem ambiente local:

```bash
# 1. Fazer backup
mysqldump -u root -p plataforma_sunyata > backup_antes_migration.sql

# 2. Aplicar migration
php scripts/apply-migration.php 001

# 3. Testar sistema

# 4. Se der problema, restaurar backup
mysql -u root -p plataforma_sunyata < backup_antes_migration.sql
```

---

## 📝 Criar Nova Migration

1. Criar arquivo numerado: `config/migrations/002_nome_descritivo.sql`
2. Seguir estrutura:

```sql
-- =====================================================
-- MIGRATION 002: Nome Descritivo
-- Data: YYYY-MM-DD
-- Descrição: O que esta migration faz
-- =====================================================

-- Seus comandos SQL aqui

-- Verificação final
SELECT 'Migration 002 completed!' as status;
```

3. Testar localmente
4. Documentar neste README

---

## ❓ FAQ

**Q: E se a migration falhar no meio?**
A: O script PHP usa transação. Se falhar, faz rollback automático.

**Q: Posso aplicar a mesma migration duas vezes?**
A: Sim, mas a segunda vez vai pular comandos que já existem (ex: tabelas já criadas).

**Q: Preciso aplicar as migrations em ordem?**
A: Sim! Sempre aplique na ordem numérica (001, 002, 003...).

**Q: Como sei se uma migration já foi aplicada?**
A: Consulte a tabela `migrations` ou use o script que verifica automaticamente.

---

## 📚 Referências

- [MySQL ALTER TABLE](https://dev.mysql.com/doc/refman/8.0/en/alter-table.html)
- [MySQL Transactions](https://dev.mysql.com/doc/refman/8.0/en/commit.html)
- [PHP PDO](https://www.php.net/manual/en/book.pdo.php)
