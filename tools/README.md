# Sunyata Tools

Scripts utilitários para a equipe multi-agente.

## ssh-cmd.sh — Acesso SSH Unificado

Script centralizado para executar comandos em qualquer servidor OVH sem lidar com escaping SSH, hops duplos ou `qm guest exec`.

### Pré-requisitos

- SSH config com `Host ovh` configurado (ControlMaster recomendado)
- Chave SSH `~/.ssh/id_ed25519_ovh` autorizada no host OVH


### Uso

```bash
# Comando direto
tools/ssh-cmd.sh <target> "comando"

# Arquivo local (enviado via base64 — zero problemas de escaping)
tools/ssh-cmd.sh <target> -f caminho/do/script.ext [args]
```

### Targets

| Target | Servidor | IP | Rota |
|--------|----------|-----|------|
| `host` | OVH Proxmox (Debian 13) | 158.69.25.114 | SSH direto |
| `vm100` | Portal Dev (Ubuntu 24.04) | 192.168.100.10 | SSH hop via host |
| `ct103` | AI Sandbox (LXC)      | 192.168.100.13 | `pct exec` via host      |

### Exemplos

```bash
# Verificar uptime de todos os servers
tools/ssh-cmd.sh host "uptime"
tools/ssh-cmd.sh vm100 "uptime"
tools/ssh-cmd.sh ct103 "uptime"

# Verificar logs do portal
tools/ssh-cmd.sh vm100 "tail -50 /var/www/sunyata/app/logs/php_errors.log"

# Status do LiteLLM
tools/ssh-cmd.sh ct103 "docker ps"

# Consultar banco de dados (SQL direto)
tools/ssh-cmd.sh vm100 -f queries/minha-query.sql

# Rodar script PHP no portal
tools/ssh-cmd.sh vm100 -f scripts/reset-password.php

# Rodar script Python no AI sandbox
tools/ssh-cmd.sh ct103 -f scripts/test-litellm.py
```

### Modo Arquivo (-f)

O modo `-f` resolve o problema de escaping em comandos complexos (ex: bcrypt hashes com `$`, queries SQL com aspas). O script é codificado em base64 localmente, enviado ao target e decodificado lá.

**Detecção automática de interpretador:**

| Extensão | Interpretador |
|----------|--------------|
| `.sh`, `.bash` | bash |
| `.php` | php |
| `.py` | python3 |
| `.sql` | `sudo -u postgres psql sunyata_platform` |
| outros | bash |

### Logging

Todas as execuções são logadas em `logs/ssh-cmd.log` (relativo ao repo).

Formato:
```
2026-02-12 15:49:59 | host  | cmd  | uptime
2026-02-12 15:49:59 | host  | exit | rc=0
2026-02-12 15:50:00 | vm100 | file | scripts/reset.php
2026-02-12 15:50:01 | vm100 | exit | rc=0 file=scripts/reset.php
```

Para customizar o caminho do log:
```bash
SSH_CMD_LOG=/tmp/ssh-cmd.log tools/ssh-cmd.sh vm100 "uptime"
```

### Notas

- O target `ct103` usa `pct exec` que é o método padrão e confiável para interagir com containers LXC no Proxmox.
- O ControlMaster do SSH reutiliza conexões — múltiplas chamadas consecutivas NÃO geram múltiplos handshakes.
- Comandos são truncados a 120 chars no log para evitar linhas gigantes.
