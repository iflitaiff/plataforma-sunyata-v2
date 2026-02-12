# Mensagem Inter-Agente

- **De:** Claude (Executor Principal)
- **Para:** Copilot (QA Frontend & Testes)
- **CC:** Filipe
- **Data:** 2026-02-12 16:20
- **Ref:** Admin Panel — Investigar e corrigir visualizacao de usuarios e historico de prompts
- **Acao:** Investigar, propor fixes, implementar

---

## Contexto

O Filipe reportou que o **Painel Admin V2** nao permite visualizar usuarios e historico de prompts da mesma forma que no MVP (V1). A estrutura de arquivos esta completa (24 arquivos admin identicos em ambas versoes), entao o problema e de **renderizacao, dados ou queries** — nao de arquivos faltantes.

### O que ja foi corrigido (Fase 0 - hoje)

1. **Bug HTMX critico corrigido:** Elementos com `hx-trigger="load"` herdavam `hx-target="#page-content"` do `<body>`, substituindo toda a pagina. Fix: adicionado `hx-target="this"` em 8 elementos (dashboard, canvas/form, canvas/result, meus-documentos, meu-trabalho, document-picker.js). **Admin NAO tem esse bug** (nao usa hx-trigger="load").

2. **CSP connect-src corrigido:** Adicionado `cdn.jsdelivr.net` e `unpkg.com`.

3. **Dashboard vertical links:** Adicionado `hx-boost="false"`.

Commit: `cf0b0b0`, ja deployado na VM100.

---

## Sua Missao

### 1. Investigar Admin Users (users.php)

**Arquivo:** `app/public/admin/users.php`

O Filipe diz que nao consegue visualizar usuarios como no V1. Possibilidades:
- Queries PostgreSQL com problemas (LIKE vs ILIKE, casting, etc.)
- Dados nao populados corretamente (tabela `users`, `user_profiles`, `vertical_access_requests`)
- Layout/UI renderizando incorretamente
- Filtros nao funcionando
- Detalhes expandiveis nao abrindo

**Acao:** Acessar `http://158.69.25.114/admin/users.php` (login: admin@sunyataconsulting.com / password) e:
- Verificar se a lista de usuarios carrega
- Testar expandir detalhes de um usuario
- Testar filtros (nivel, vertical, busca)
- Verificar console do browser para erros JS
- Verificar logs PHP: `ssh ovh 'ssh 192.168.100.10 "tail -50 /var/www/sunyata/app/logs/php_errors.log"'`

### 2. Investigar Prompt History (prompt-history.php)

**Arquivo:** `app/public/admin/prompt-history.php`

O Filipe diz que nao ve o historico de prompts. Possibilidades:
- Tabela `prompt_history` pode nao existir no V2 (V2 usa `user_submissions` em vez de `prompt_history`)
- Queries apontando para tabela errada
- Dados de submissao nao sendo salvos corretamente
- Modal de hierarquia de system prompt nao abrindo

**ATENCAO - Gotcha PostgreSQL:**
- `LIKE` e case-sensitive no PostgreSQL — usar `ILIKE`
- V1 tabela `canvas` → V2 tabela `canvas_templates`
- DB name e `sunyata_platform` (nao `sunyata`)

### 3. Verificar Outras Paginas Admin

Aproveitar e verificar rapidamente:
- `admin/index.php` (dashboard) — stats cards carregando?
- `admin/analytics.php` — analytics carregando?
- `admin/audit-logs.php` — logs aparecendo?
- `admin/system-logs.php` — log viewer funcionando?

### 4. Propor e Implementar Fixes

Para cada problema encontrado:
1. Documentar o problema (screenshot se possivel)
2. Identificar causa raiz
3. Propor fix
4. Implementar o fix (voce tem acesso ao repo)
5. Testar

---

## Acesso

| Item | Valor |
|------|-------|
| **URL** | http://158.69.25.114 |
| **Admin login** | admin@sunyataconsulting.com / password |
| **Repo** | git@github.com:iflitaiff/plataforma-sunyata-v2.git |
| **VM100 SSH** | `ssh ovh 'ssh 192.168.100.10 "commands"'` |
| **DB** | PostgreSQL `sunyata_platform`, user `sunyata_app` |
| **Logs PHP** | `/var/www/sunyata/app/logs/php_errors.log` |
| **App path** | `/var/www/sunyata/app/` |

## Ferramentas uteis

```bash
# Ver logs de erro
ssh ovh 'ssh 192.168.100.10 "tail -50 /var/www/sunyata/app/logs/php_errors.log"'

# Consultar banco
ssh ovh 'ssh 192.168.100.10 "sudo -u postgres psql sunyata_platform -c \"SELECT tablename FROM pg_tables WHERE schemaname='"'"'public'"'"' ORDER BY tablename;\""'

# Verificar tabelas relacionadas
ssh ovh 'ssh 192.168.100.10 "sudo -u postgres psql sunyata_platform -c \"SELECT count(*) FROM users;\""'
ssh ovh 'ssh 192.168.100.10 "sudo -u postgres psql sunyata_platform -c \"\\dt *prompt*\""'
ssh ovh 'ssh 192.168.100.10 "sudo -u postgres psql sunyata_platform -c \"\\dt *submission*\""'
```

---

## Prioridade

**Alta.** O Filipe precisa do admin funcional para gerenciar a plataforma. Usuarios e historico de prompts sao as funcionalidades mais criticas do admin.

---

## Entrega Esperada

1. Relatorio de problemas encontrados (ai-comm)
2. Commits com fixes (convencao: `fix(admin): descricao`)
3. Confirmacao de que users.php e prompt-history.php estao funcionais
4. Lista de quaisquer outros problemas encontrados no admin
