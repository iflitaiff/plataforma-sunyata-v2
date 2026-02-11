# Plataforma Sunyata v2

Plataforma de IA para consultoria empresarial — ambiente de produção OVH.

## Arquitetura

| Componente | Stack |
|-----------|-------|
| **App** | PHP 8.3 (migrado do v1) |
| **AI Service** | Python 3.12 / FastAPI / uvicorn |
| **Banco** | PostgreSQL 16 + pgvector |
| **Cache/Sessões** | Redis 7 |
| **Frontend** | Tabler + HTMX + SSE |
| **Formulários** | SurveyJS |
| **Web Server** | Nginx 1.24 |

## Estrutura

```
app/                    # Aplicação PHP
  src/                  # Classes (AI, Admin, Auth, Core, Helpers, Services)
  public/               # Webroot (index.php, assets, areas)
  config/               # Configuração (verticals, database)
  database/             # Migrations MariaDB (legado, referência)
services/
  ai/                   # Microserviço Python/FastAPI
infra/
  nginx/                # Configuração Nginx (vhosts)
  systemd/              # Unit files (FastAPI, etc.)
  scripts/              # Scripts de deploy e manutenção
migrations/             # Scripts de migração MariaDB → PostgreSQL
ai-comm/                # Comunicação entre agentes IA
docs/                   # Documentação
```

## Servidor

- **Host:** OVH Dedicated (Proxmox VE)
- **VM:** Ubuntu 24.04 (192.168.100.10)
- **URL:** TBD (migração de portal.sunyataconsulting.com)

## Equipe de Agentes

| Agente | Papel |
|--------|-------|
| **Claude** | Executor Principal — implementação, deploy, features |
| **Manus** | Arquiteto de Conteúdo — templates, regras de negócio |
| **Gemini** | QA Infra/Código — segurança, code review, checklists |
| **Codex** | QA Dados/Templates — SurveyJS JSON, schemas, consistência |
