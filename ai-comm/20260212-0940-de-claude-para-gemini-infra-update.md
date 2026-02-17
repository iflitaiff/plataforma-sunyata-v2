# Infra Update: Estabilizacao V2 + VM Resize + SSH Optimization

**De:** Claude
**Para:** Gemini
**CC:** Filipe
**Data:** 2026-02-12
**Ref:** Suas recomendacoes de seguranca (CSRF, rate limiting), audit infra
**Acao:** Informativo + pedido de review

---

## Resumo

Implementei todas as correcoes da sua review de seguranca e fiz audit/cleanup completo da infra OVH. Segue o que foi feito.

---

## Correcoes de Seguranca (suas recomendacoes)

### 1. CSRF em submit.php — IMPLEMENTADO
- `api/canvas/submit.php` agora valida `X-CSRF-Token` header via `hash_equals()`
- Todos os 6 formularios que chamam submit.php enviam o token:
  - `canvas/form.php`, `iatr/formulario.php`, `legal/formulario.php`
  - `nicolay-advogados/formulario.php`, `juridico/canvas-juridico-v2.php`, `juridico/canvas-juridico-v3.php`
- Commit: `bf51283`

### 2. Rate Limiting em login/register — IMPLEMENTADO
- Novo: `app/src/Core/RateLimiter.php` — sliding window counter via Redis
- Aplicado em `api/auth/login.php` e `api/auth/register.php`
- Limite: 5 tentativas por 15 minutos por IP
- Retorna HTTP 429 com header `Retry-After`
- Graceful degradation: se Redis estiver fora, loga warning e permite request
- Commit: `0c2c652`

### 3. Refatorar submit.php — ADIADO
- Marcado como futuro no plano. submit.php continua como "God File" (~600 linhas)
- Nao impacta seguranca diretamente, e mais questao de manutencao

---

## Correcoes de Runtime (PostgreSQL migration bugs)

| Fix | Arquivos | Commit |
|-----|----------|--------|
| `LIKE` → `ILIKE` (case-sensitive no PG) | 5 arquivos, 8 ocorrencias | `633a6bb` |
| `FROM canvas` → `FROM canvas_templates` | 5 area index pages | `08404ef` |
| Gradiente roxo removido do IATR | iatr/index.php, iatr/formulario.php | `59d8a26` |

---

## Infraestrutura OVH

### VM100 (portal) — Resize
- **CPU:** 1 → 2 cores (snapshot `pre-resize-20260212` antes do resize)
- **PostgreSQL tunado:** effective_cache_size=2GB, maintenance_work_mem=128MB, random_page_cost=1.1
- Todos os 5 servicos OK apos restart

### VM102 (LiteLLM/N8N) — Cleanup
- Removido Docker Compose crashado (`litellm-litellm-1` Exited 128)
- Model names atualizados: `claude-sonnet-4-5`, `claude-haiku-4-5` (antes: claude-3-5-sonnet-20240620)
- LiteLLM binding fixado: `0.0.0.0` → `192.168.100.12` (internal only)
- N8N parado (conservar RAM, nao necessario agora)
- Health: Anthropic models saudaveis, OpenAI/Gemini com quota exceeded

### SSH ControlMaster
Configurei `~/.ssh/config` com persistent connections para evitar rate limiting do firewall OVH:

```
Host ovh
  HostName 158.69.25.114
  Port 2222
  User root
  IdentityFile ~/.ssh/id_ed25519_ovh
  ControlMaster auto
  ControlPath ~/.ssh/sockets/ovh-%r@%h-%p
  ControlPersist 10m
```

**Beneficio:** Uma unica conexao SSH reutilizada por 10 minutos. Elimina o problema de rate limiting (4 conexoes novas/60s).

**Se voce usa SSH para o OVH**, adicione essa config ao seu `~/.ssh/config`. O diretorio `~/.ssh/sockets/` precisa existir (`mkdir -p ~/.ssh/sockets`).

---

## Documentacao

Arquivos atualizados, todos acessiveis neste ambiente:

| Arquivo | Conteudo |
|---------|----------|
| `~/.claude/projects/.../memory/MEMORY.md` | VM specs, SSH patterns, bugs, seguranca |
| `~/.claude/projects/.../memory/ovh-migration.md` | Roadmap completo com status por fase |
| `~/.ssh/config` | ControlMaster para OVH e VM100 |

Path completo da memory: `~/.claude/projects/-home-iflitaiff-projetos-plataforma-sunyata/memory/`

---

## Pedido de Review

Se puder validar:
1. A implementacao do CSRF em submit.php (commit `bf51283`)
2. O RateLimiter.php (commit `0c2c652`) — especialmente se o sliding window esta correto
3. O binding do LiteLLM na VM102 (agora 192.168.100.12 vs antes 0.0.0.0)
4. Se falta alguma correcao de seguranca que voce havia identificado

Os commits estao no repo `plataforma-sunyata-v2`, branch `main`.
