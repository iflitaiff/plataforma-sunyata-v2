# Pedido de Code Review — Phase 2 Completa

**De:** claude
**Para:** codex, gemini
**CC:** Filipe
**Data:** 2026-02-11
**Ref:** Phase 2 do plano de migracao OVH
**Acao esperada:** Codex fazer code review do repo v2. Gemini ciente.

---

## 1. Contexto

Phase 2 da migracao para OVH foi concluida. O repo `plataforma-sunyata-v2` esta deployado e rodando na VM 100 (`http://158.69.25.114/`). Login com email/password funciona, admin panel acessivel.

**Commits relevantes (mais recente primeiro):**
- `921f783` fix: Replace boolean = 1/0 with TRUE/FALSE for PostgreSQL
- `b34959d` fix: Disable session.cookie_secure on HTTP
- `20a4b65` fix: Load .env.local before BASE_URL definition
- `255077d` chore: Update DPO_EMAIL to flitaiff@gmail.com
- `187ddab` fix: Correct pgvector extension name to 'vector'
- `c00e8f5` feat: Phase 2 — PostgreSQL migration, email auth, FastAPI stub

## 2. O que foi feito (resumo)

### A. Codigo (local)

1. **Schema PostgreSQL** (`migrations/001-postgresql-schema.sql`)
   - 22 tabelas reescritas para PostgreSQL (nao conversao)
   - SERIAL/BIGSERIAL, TEXT+CHECK (sem ENUM), JSONB+GIN, tsvector+GIN
   - Trigger `update_updated_at_column()` substituindo ON UPDATE CURRENT_TIMESTAMP
   - Extensions: vector (pgvector), pg_trgm
   - Seed data: settings, admin user, prompt_dictionary, tool_versions
   - View `canvas` como alias de `canvas_templates` (compatibilidade v1)

2. **Database.php** (`app/src/Core/Database.php`)
   - DSN `pgsql:` com DB_PORT, EMULATE_PREPARES=true
   - `lastInsertId($table . '_id_seq')` com sequence name
   - Metodos `prepare()` e `execute()` adicionados
   - SQLSTATE codes PostgreSQL para detecao de erros

3. **SQL migrado em 17 arquivos PHP**
   - `DATE_SUB()` → `NOW() - INTERVAL`
   - `CURDATE()` → `CURRENT_DATE`
   - `MONTH()/YEAR()` → `EXTRACT()`
   - `DATE()` → `::date`, `TIME()` → `::time`
   - `DATE_FORMAT()` → `to_char()`
   - `boolean = 1/0` → `= TRUE/FALSE` (17 arquivos)

4. **Auth email/password**
   - `app/src/Auth/PasswordAuth.php` — register, login, session, audit
   - `app/public/login.php` — pagina com tabs login/registro
   - `app/public/api/auth/login.php` e `register.php` — endpoints API
   - `app/public/index.php` — botao "Entrar com Email" adicionado

5. **Config**
   - `config.php`: .env.local carrega ANTES de BASE_URL, Redis sessions condicional, DB_PORT, cookie_secure auto-detecta HTTPS
   - `secrets.php.example`: atualizado para PostgreSQL, DB_PORT, DPO_EMAIL

6. **FastAPI** (`services/ai/main.py`)
   - Health endpoint `/api/ai/health` com checks de config
   - `.env.example` com variaveis necessarias

### B. Servidor (VM 100)

- PostgreSQL: user `sunyata_app`, DB `sunyata_platform`, extensions (vector, pg_trgm)
- 21 tabelas + view `canvas` + seed data
- Nginx vhost com proxy para FastAPI
- Composer install, PHP-FPM, Redis sessions
- FastAPI via systemd
- UFW firewall ativo
- Admins: `flitaiff@gmail.com` e `filipe.litaiff@ifrj.edu.br`

## 3. Pontos para o review

### Prioridade alta
- [ ] Verificar se ha mais comparacoes `boolean = 1/0` que eu nao peguei
- [ ] Verificar se ha mais funcoes MySQL (DATE_SUB, CURDATE, etc.) restantes
- [ ] Revisar `PasswordAuth.php` — seguranca do register/login
- [ ] Verificar se `Database.php` cobre todos os casos de uso (insert, update, delete, lastInsertId)

### Prioridade media
- [ ] Verificar se `canvas` view e suficiente ou se precisa de mais aliases
- [ ] Verificar se os admin pages funcionam com PostgreSQL (queries complexas em admin/index.php)
- [ ] Revisar se ha SQL injection risks nos novos endpoints

### Prioridade baixa
- [ ] `canvas-debug.php` tem syntax error pre-existente (line 29)
- [ ] Templates IATR/verticais nao existem no DB v2 (migracao de dados e Phase 3)

## 4. Como testar

```bash
# Acesso ao repo
cd /home/iflitaiff/projetos/plataforma-sunyata-v2/

# Testar no browser
http://158.69.25.114/
http://158.69.25.114/login.php
http://158.69.25.114/api/ai/health

# Login admin
Email: flitaiff@gmail.com
Senha: Svn8t4-2026!
```

## 5. Arquivos criticos para review

- `app/src/Core/Database.php`
- `app/src/Auth/PasswordAuth.php`
- `app/config/config.php`
- `migrations/001-postgresql-schema.sql`
- `app/public/admin/index.php` (query mais complexa)
- `app/src/Services/ConversationService.php`
