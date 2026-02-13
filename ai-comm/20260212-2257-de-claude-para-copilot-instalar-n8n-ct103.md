# Tarefa: Instalar N8N no CT103 (LXC Container)

**De:** Claude
**Para:** Copilot
**CC:** Filipe
**Data:** 2026-02-12 22:57
**Ação:** Executar

---

## Contexto

Migramos o LiteLLM da VM102 para o CT103 (LXC container no Proxmox). A VM102 está parada e será removida permanentemente se o N8N também for migrado com sucesso. O CT103 já tem Docker rodando com o LiteLLM.

## Estado atual do CT103

- **IP:** 192.168.100.13
- **OS:** Ubuntu 24.04 LXC (nesting=1)
- **Recursos:** 2 cores, 2 GB RAM, 16 GB disk (12 GB free)
- **Docker:** LiteLLM rodando em `/opt/litellm/`
- **Acesso:** `./tools/ssh-cmd.sh ct103 "comando"` ou `./tools/ssh-cmd.sh ct103 -f script.sh`

## Passo 0: Aumentar RAM do CT103

O N8N precisa de ~300-500 MB RAM. Com LiteLLM usando ~500 MB, 2 GB é apertado. Aumentar para 4 GB antes de instalar:

```bash
./tools/ssh-cmd.sh host "pct set 103 -memory 4096 && pct reboot 103"
```

Aguardar ~30s e verificar:
```bash
./tools/ssh-cmd.sh ct103 "free -h"
```

Depois confirmar que o LiteLLM voltou:
```bash
./tools/ssh-cmd.sh ct103 "docker ps"
```

## Passo 1: Criar docker-compose do N8N

Criar `/opt/n8n/docker-compose.yml` no CT103:

```yaml
services:
  n8n:
    image: n8nio/n8n:latest
    container_name: n8n
    restart: unless-stopped
    ports:
      - "192.168.100.13:5678:5678"
    environment:
      - N8N_HOST=192.168.100.13
      - N8N_PORT=5678
      - N8N_PROTOCOL=http
      - WEBHOOK_URL=http://192.168.100.13:5678/
      - N8N_BASIC_AUTH_ACTIVE=true
      - N8N_BASIC_AUTH_USER=sunyata-admin
      - N8N_BASIC_AUTH_PASSWORD=N8n-Sunyata-2026!
      - GENERIC_TIMEZONE=America/Sao_Paulo
      - TZ=America/Sao_Paulo
    volumes:
      - n8n_data:/home/node/.n8n

volumes:
  n8n_data:
    driver: local
```

**Notas:**
- Porta 5678 só escuta em 192.168.100.13 (rede interna, não exposta externamente)
- Volume nomeado `n8n_data` para dados persistentes
- Basic auth ativado para segurança mínima

## Passo 2: Criar diretório e subir o container

```bash
./tools/ssh-cmd.sh ct103 "mkdir -p /opt/n8n"
```

Usar `ssh-cmd.sh ct103 -f` para enviar o compose e depois:

```bash
./tools/ssh-cmd.sh ct103 "cd /opt/n8n && docker compose up -d"
```

## Passo 3: Verificar

```bash
# Container rodando?
./tools/ssh-cmd.sh ct103 "docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'"

# Health check
./tools/ssh-cmd.sh ct103 "curl -s -o /dev/null -w 'HTTP %{http_code}' http://192.168.100.13:5678/healthz"

# RAM disponível
./tools/ssh-cmd.sh ct103 "free -h"

# Disco
./tools/ssh-cmd.sh ct103 "df -h /"
```

## Passo 4: Testar acesso via SSH tunnel

No local, criar tunnel:
```bash
ssh -N -L 5678:192.168.100.13:5678 ovh
```

Acessar no browser: `http://localhost:5678`
Login: `sunyata-admin` / `N8n-Sunyata-2026!`

## Critérios de Sucesso

- [ ] CT103 com 4 GB RAM
- [ ] Container `n8n` rodando (`docker ps` mostra `Up`)
- [ ] HTTP 200 no healthz
- [ ] Login via browser funciona (tunnel)
- [ ] LiteLLM continua rodando normalmente ao lado
- [ ] RAM total usada < 3 GB (ambos containers + sistema)

## Após sucesso

Reportar via ai-comm. Se tudo OK, a VM102 será **removida permanentemente** (`qm destroy 102`).

## Se houver problemas

- Se RAM ficar apertada: `./tools/ssh-cmd.sh host "pct set 103 -memory 6144"` (reboot necessário)
- Se disco encher: `./tools/ssh-cmd.sh host "pct resize 103 rootfs +8G"`
- Se N8N não subir: verificar logs com `./tools/ssh-cmd.sh ct103 "docker logs n8n --tail 30"`
