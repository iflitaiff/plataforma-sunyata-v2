# Plataforma Sunyata v2 - Claude Code

**Ao iniciar sessão:**
1. Verifique mensagens em `ai-comm/` (local) e no Hostinger via SSH
2. Credenciais: `app/config/secrets.php` (VM100) e `config/secrets.php` (Hostinger)

---

## Ecossistema Multi-Agente

| Agente | Papel | Foco |
|--------|-------|------|
| **Claude** | Executor Principal | Implementação, deploy, bugs, features |
| **Claude Chat** | Arquiteto & Consultor | Workflows N8N, pesquisa, consultoria |
| **Gemini** | QA Infra/Código | Segurança, code review, checklists servidor, docs |
| **Codex** | QA Dados/Templates | SurveyJS JSON, schemas, form_config, consistência |
| **Copilot** | QA Frontend & Testes | UI/UX, HTMX, Tabler, testes Playwright, acessibilidade |

### Comunicação (ai-comm)

**Protocolo:** `ai-comm/PROTOCOL.md` (fonte da verdade)
- **Nomes:** `YYYYMMDD-HHMM-de-ORIGEM-para-DESTINO-assunto.md`
- **Monitor:** `monitor-aicomm.sh` v4.1 no Hostinger (cron, email HTML com cores por agente)
- **REGRA:** Ao criar arquivo em `ai-comm/`, copiar para Hostinger imediatamente:
```bash
scp -P 65002 ai-comm/ARQUIVO.md u202164171@82.25.72.226:/home/u202164171/ai-comm/
```

### Git Workflow (multi-agente)
- Agentes trabalham em `feature/*` branches
- Claude faz code review e squash merge para `staging`
- Filipe aprova merge `staging` → `main` via PR

---

## Infraestrutura

### Servidores

| Servidor | Endereço | Função |
|----------|----------|--------|
| **OVH Host** | 158.69.25.114:2222 | Proxmox VE 9.1.4 |
| **VM 100** | 192.168.100.10 | Portal v2 (Nginx, PHP 8.3, PostgreSQL 16, Redis 7) |
| **CT 103** | 192.168.100.13 | LiteLLM Gateway (Docker, port 4000) |
| **CT 104** | 192.168.100.14 | N8N Automation (Docker, port 5678) |
| **Hostinger** | 82.25.72.226:65002 | Produção legada (MariaDB, PHP) |

### Ferramentas de Acesso

**OBRIGATÓRIO usar `ssh-cmd.sh`** (salva tokens, evita problemas de escaping):
```bash
# Comando direto
tools/ssh-cmd.sh vm100 "systemctl status nginx"

# Executar arquivo (.sql→psql, .sh→bash)
tools/ssh-cmd.sh vm100 -f /tmp/script.sql
```
Targets: `host`, `vm100`, `ct103`, `ct104`

**SSH Tunnels** (systemd, auto-start):
- Ports: 8006 (Proxmox), 4000 (LiteLLM), 5678 (N8N)
- Control: `systemctl --user {start|stop|status} sunyata-tunnels`

### Deploy

**VM100 (v2):** `cd /var/www/sunyata/app && git pull`
**Hostinger (legado):** `scp -P 65002 arquivo.php u202164171@82.25.72.226:/home/u202164171/domains/sunyataconsulting.com/public_html/plataforma-sunyata/public/`

---

## Stack v2

| Componente | Tecnologia |
|-----------|-----------|
| **Banco** | PostgreSQL 16 + pgvector |
| **AI Gateway** | LiteLLM (CT103) — 10 modelos (Anthropic, OpenAI, Google) |
| **AI Microservice** | Python 3.12 / FastAPI / uvicorn |
| **Automação** | N8N (CT104) — PNCP Monitor, IATR Análise |
| **Frontend** | Tabler + HTMX + SSE |
| **Formulários** | SurveyJS (tema customizado) |
| **Web Server** | Nginx 1.24 + PHP 8.3 FPM |
| **Cache/Sessões** | Redis 7 |

---

## Documentação de Referência

- `docs/DATABASE.md` — Schema completo (30+ tabelas, colunas, constraints, queries comuns)
- `docs/MIGRATIONS.md` — Changelog de migrations com rollback
- `docs/ROADMAP.md` — Roadmap geral do projeto
- `docs/PROXIMOS-PASSOS.md` — Próximos passos imediatos
- `docs/N8N-PORTAL-INTEGRATION.md` — Integração N8N com portal
- `docs/PRODUCTION-CHECKLIST.md` — Checklist pré-produção

**REGRA:** Consultar `docs/DATABASE.md` antes de qualquer query ou pergunta sobre schema.
**REGRA:** Usar Context7 (MCP) para validar docs de APIs externas (N8N, LiteLLM, FastAPI) — evita erros por documentação desatualizada.

---

## Arquitetura de Configuração

### System Prompt — 4 níveis (CanvasHelper::getCompleteSystemPrompt)
0. `settings.portal_system_prompt` (portal-wide)
1. Vertical system_prompt (`config/verticals.php` + DB override)
2. `canvas_templates.system_prompt` (per-canvas)
3. `ajSystemPrompt` em form_config JSON

### API Params — 4 níveis (ClaudeFacade::getPortalDefaults)
0. `settings.portal_api_params` JSON (portal-wide)
1. `config/verticals.php` (file defaults)
2. `verticals.config` DB column (admin UI override)
3. `canvas_templates.api_params_override` JSON (canvas-level)

### Regras Importantes
- Config keys: `claude_model`, `temperature`, `max_tokens`, `top_p`
- ClaudeService keys: `model`, `temperature`, `max_tokens`, `top_p`
- `ClaudeFacade::translateConfigKeys()` faz o mapeamento
- `temperature` e `top_p` NÃO coexistem na API Claude 4.x
- `system_prompt` bloqueado em canvas overrides (gerido pela hierarquia de 4 níveis)
- `Settings::set()` com `data_type='json'`: passar PHP array, NÃO JSON string

### Caminhos de IA (dois pipelines)
1. **Canvas:** Portal → FastAPI → LiteLLM → resposta no formulário
2. **N8N:** Portal → N8N webhook → LiteLLM → resultado no banco

---

## Banco de Dados

- **V2:** PostgreSQL 16, db `sunyata_platform`, user `sunyata_app`
- **V1 (legado):** MariaDB, db `u202164171_sunyata`
- **IMPORTANTE:** V2 usa JSONB `config` em vez de colunas individuais
  - V1: `nome`, `icone`, `descricao`, `disponivel`
  - V2: `name`, `config->>'icon'`, `is_active`
- **Sempre usar Services** (VerticalService, CanvasService) em vez de queries diretas
- Canvas↔Vertical: many-to-many via `canvas_vertical_assignments` (Phase 3.5)

---

## N8N Workflows (CT104)

### PNCP Daily Monitor v3 (ID: kWX9x3IteHYZehKC)
- Webhook: GET `/webhook/pncp/monitor`
- Coleta editais da API PNCP (6 UFs, 17 keywords) → tabela `pncp_editais`
- Email HTML para `claudesunyata@gmail.com` com links via deep-link resolver `/edital.php`

### IATR Análise de Edital (ID: JzfXXdEuOOe7FFf6)
- Webhook: POST `/webhook/iatr/analisar` (body: `{"edital_id": N}`)
- Auth: header `X-Auth-Token`
- Flow: Validate → Mark In Progress → Build Prompt → LiteLLM → Save Result
- Portal chama via proxy `/api/pncp/trigger-analise.php` (não direto do browser)

### Portal - Send Email (ID: rWDYKMY0Wav5dMpH)
- Webhook: POST `/webhook/portal/send-email`
- Body: `{to, subject, html, attachment_name, attachment_base64}`
- Genérico — usado pelo portal para enviar emails com anexos (PDF de análise, etc.)
- SMTP único: credencial `TAZ8C6Oo3qLTak9d` (não duplicar PHPMailer no portal)

### Configurações para novos workflows
- **PostgreSQL:** credential `sunyata-pg-001` (n8n_worker@192.168.100.10)
- **LiteLLM:** `http://192.168.100.13:4000/v1/chat/completions`, Bearer auth
- **Webhook auth:** header `X-Auth-Token`

### Restrições N8N (obrigatórias)

**typeVersions fixas:**
| Node | typeVersion |
|------|-------------|
| Webhook | 1.1 |
| IF | 1 |
| Respond to Webhook (OK) | 1.5 |
| Respond to Webhook (Error) | 1 |
| HTTP Request | 4.2 |
| Code | 2 |

**Auth pattern obrigatório:** Todo webhook exposto → IF node (valida `X-Auth-Token`) → branch true/false (401 no false)

**Code node sandbox:** Disponível: `$input`, `$json`, `$('NodeName')`, `Date`, `Math`, `JSON`, `Array`, `Object`. **Proibido:** `require`, `process`, `fs`, `Buffer`

**PostgreSQL no N8N:** Resultados são objetos (não arrays). `GRANT USAGE ON SCHEMA public` necessário para PG16

**LiteLLM:** Chamar direto CT103 (`192.168.100.13:4000`), formato OpenAI: `choices[0].message.content`

---

## Instruções por Agente

### Restrições Gerais (todos exceto Claude)
- NÃO instalar pacotes no ambiente local
- NÃO modificar arquivos de produção diretamente
- Comunicar via `ai-comm/` para coordenar

### Gemini (QA)
- Revisar código antes/depois de deploy
- Validar templates JSON contra schema
- Criar cenários de teste
```bash
# Consultar banco V1 (Hostinger)
ssh -p 65002 u202164171@82.25.72.226 "/usr/bin/mariadb u202164171_sunyata -e 'QUERY'"
```

### Codex (QA Dados)
- Validar form_config JSON, promptInstructionMap
- Verificar consistência de schemas entre templates

### Copilot (QA Frontend)
- Testar UI/UX, acessibilidade
- Testes Playwright

---

## Convenções

- **Arquivos:** kebab-case (ex: `user-service.php`)
- **Commits:** Conventional Commits com scope: `feat(portal):`, `fix(n8n):`, `docs(iatr):`, `infra(nginx):`, `refactor(api):`
- **Comunicação AI:** Markdown em `ai-comm/`
- **PostgreSQL:** usar `ILIKE` (não `LIKE`) para buscas case-insensitive

---

## Development Mode

- **`.env`:** `APP_ENV=development` desabilita whitelist de DB e rate limiting
- **Antes de produção:** seguir `docs/PRODUCTION-CHECKLIST.md`

---

**Versão:** 2.1
**Última atualização:** 2026-02-20
**Mantido por:** Claude + equipe multi-agente
