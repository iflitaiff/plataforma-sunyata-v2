# Plataforma Sunyata v2 - Claude Code

**IMPORTANTE:** Ao iniciar nova sessão:
1. Verifique mensagens em `ai-comm/` (local)
2. Verifique mensagens em `/home/u202164171/ai-comm/` via SSH (servidor Hostinger)

---

## Ecossistema Multi-Agente

| Agente | Papel | Foco |
|--------|-------|------|
| **Claude** | Executor Principal | Implementação, deploy, correção de bugs, features |
| **Manus** | Arquiteto de Conteúdo | JSONs de templates, regras de negócio, promptInstructionMap |
| **Gemini** | QA Infra/Código | Segurança, code review, checklists de servidor, documentação |
| **Codex** | QA Dados/Templates | SurveyJS JSON, schemas, form_config, consistência |

### Comunicação entre Agentes

**Protocolo:** `ai-comm/PROTOCOL.md`
- **Diretório:** flat, raiz de `ai-comm/`
- **Nomes:** `YYYYMMDD-HHMM-de-ORIGEM-para-DESTINO-assunto.md`
- **Formato:** cabeçalho (De/Para/CC Filipe/Data/Ref/Ação) + seções

---

## Ambiente

| Item | Valor |
|------|-------|
| **Servidor OVH** | 158.69.25.114 (Proxmox) |
| **SSH OVH** | porta 2222, chave `~/.ssh/id_ed25519_ovh` |
| **VM 100** | 192.168.100.10 (Ubuntu 24.04) |
| **Servidor Hostinger** | 82.25.72.226:65002 (legado, produção atual) |
| **GitHub** | git@github.com:iflitaiff/plataforma-sunyata-v2.git |

---

## Stack v2

| Componente | Tecnologia |
|-----------|-----------|
| **Banco** | PostgreSQL 16 + pgvector |
| **AI Microservice** | Python 3.12 / FastAPI / uvicorn |
| **Frontend** | Tabler + HTMX + SSE |
| **Formulários** | SurveyJS (tema customizado) |
| **Web Server** | Nginx 1.24 + PHP 8.3 FPM |
| **Cache/Sessões** | Redis 7 |

---

## Comandos Essenciais

### OVH Host
```bash
ssh -p 2222 -i ~/.ssh/id_ed25519_ovh root@158.69.25.114
```

### OVH VM 100
```bash
# Via host (SSH hop)
ssh -p 2222 -i ~/.ssh/id_ed25519_ovh root@158.69.25.114 "ssh 192.168.100.10"
```

### Hostinger (legado)
```bash
ssh -p 65002 u202164171@82.25.72.226
```

---

## Arquitetura de Configuração

### System Prompt — 4 níveis (CanvasHelper::getCompleteSystemPrompt)
0. `settings.portal_system_prompt` (portal-wide)
1. Vertical system_prompt (config/verticals.php + DB override)
2. `canvas_templates.system_prompt` (per-canvas)
3. `ajSystemPrompt` em form_config JSON

### API Params — 4 níveis (ClaudeFacade::getPortalDefaults)
0. `settings.portal_api_params` JSON (portal-wide)
1. `config/verticals.php` (file defaults)
2. `verticals.config` DB column (admin UI override)
3. `canvas_templates.api_params_override` JSON (canvas-level)

### Regras
- Config keys: `claude_model`, `temperature`, `max_tokens`, `top_p`
- ClaudeService keys: `model`, `temperature`, `max_tokens`, `top_p`
- `ClaudeFacade::translateConfigKeys()` faz o mapeamento
- `temperature` e `top_p` NÃO coexistem na API Claude 4.x

---

## Convenções

- **Arquivos:** kebab-case (ex: `user-service.php`)
- **Commits:** Conventional Commits (feat:, fix:, docs:, chore:)

---

**Versão:** 1.0
**Última atualização:** 2026-02-11
