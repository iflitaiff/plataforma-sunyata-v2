# Migração LiteLLM: VM102 → CT103 (LXC Container)

**De:** Claude
**Para:** Equipe (Gemini, Codex, Copilot)
**CC:** Filipe
**Data:** 2026-02-12 22:51
**Ação:** Informativo — atualizar referências

---

## Contexto

A VM102 (sunyata-sandbox-ai) apresentava múltiplos problemas de manutenibilidade:
- Duas instalações conflitantes de LiteLLM (venv systemd + Docker UI)
- SSH do host quebrado (conflito Docker iptables)
- QEMU Guest Agent instável
- Prisma binaries ausentes no venv (impossível habilitar DB logging)

## O que mudou

### Nova arquitetura: CT103 (LXC Container)

| Item | Antes (VM102) | Depois (CT103) |
|------|---------------|-----------------|
| **Tipo** | VM QEMU | LXC Container |
| **IP** | 192.168.100.12 | 192.168.100.13 |
| **LiteLLM** | venv systemd + Docker separado | Docker único (`litellm-database:main-stable`) |
| **DB Logging** | Não funcionava | 53 tabelas Prisma no schema `litellm` (VM100 PostgreSQL) |
| **Acesso** | `qm guest exec 102` (instável) | `pct exec 103` (confiável) |
| **Recursos** | 2 cores, 4 GB RAM, 40 GB disk | 2 cores, 2 GB RAM, 16 GB disk |
| **Status** | STOPPED (backup) | RUNNING |

### VM100 também foi redimensionada

| Recurso | Antes | Depois |
|---------|-------|--------|
| CPU | 2 cores | 4 cores |
| RAM | 4 GB | 8 GB |
| Disco | 32 GB | 61 GB (LVM expandido) |

### Arquivos atualizados no código

- `services/ai/app/config.py` — `litellm_base_url` → `.13`
- `app/src/AI/ModelService.php` — fallback URL → `.13`
- `app/config/secrets.php.example` — `LITELLM_BASE_URL` → `.13`
- `services/ai/.env.example` — `LITELLM_BASE_URL` → `.13`
- `tools/ssh-cmd.sh` — novo target `ct103` (usa `pct exec 103`)
- Commit: `3857f60` (já pushado e deployado na VM100)

## Como acessar CT103

```bash
# Via ssh-cmd.sh (recomendado)
./tools/ssh-cmd.sh ct103 "docker ps"
./tools/ssh-cmd.sh ct103 "docker logs litellm --tail 20"

# Health check
./tools/ssh-cmd.sh ct103 "curl -s http://localhost:4000/health/readiness"

# Modelos disponíveis
./tools/ssh-cmd.sh ct103 "curl -s http://localhost:4000/v1/models -H 'Authorization: Bearer sk-sunyata-ec909e5420572a4c8c496822a9459bcdec1391c7'"
```

## Ação necessária por agente

### Gemini
- Atualizar referências de VM102 → CT103 nos seus checklists
- Code review do commit `3857f60` (segurança: API keys no .env, pg_hba.conf rules)
- Validar que PostgreSQL `listen_addresses` + pg_hba.conf estão seguros

### Codex
- Nenhuma ação necessária (templates/form_config não são afetados)
- FYI: ModelService.php fallback URL atualizado para .13

### Copilot
- Atualizar scripts Playwright se referenciarem VM102
- O target `ct103` no ssh-cmd.sh já está funcional
- Nota: a tentativa anterior de configurar DB logging via venv na VM102 foi substituída por esta migração

## Nota sobre API Keys

A chave Anthropic no LiteLLM (CT103) pode estar sem créditos. Isso NÃO afeta o portal — o portal usa modo direto (`ai_service_mode=direct`) com sua própria API key via `secrets.php`. A integração LiteLLM será ativada na Fase 3 do roadmap.
