# SSH Tunnels - Sunyata Infrastructure

Documentação do sistema de túneis SSH persistentes para acesso aos serviços da infraestrutura Sunyata no OVH.

---

## 🎯 O Que São os Tunnels?

Túneis SSH que conectam seu WSL local aos serviços rodando no servidor OVH, permitindo acesso via `localhost`.

### Serviços Expostos

| Serviço | Porta Local | Destino | Descrição |
|---------|-------------|---------|-----------|
| **Proxmox UI** | 8006 | `ovh:8006` | Interface web do Proxmox VE |
| **LiteLLM** | 4000 | `192.168.100.13:4000` | API Gateway de IA (CT 103) |
| **N8N** | 5678 | `192.168.100.14:5678` | Automação/Workflows (CT 104) |

---

## 🚀 Uso Rápido

### Comandos Básicos

```bash
# Iniciar tunnels
systemctl --user start sunyata-tunnels

# Parar tunnels
systemctl --user stop sunyata-tunnels

# Reiniciar tunnels
systemctl --user restart sunyata-tunnels

# Ver status
systemctl --user status sunyata-tunnels

# Ver logs em tempo real
journalctl --user -u sunyata-tunnels -f
```

### Ou via script direto

```bash
~/bin/sunyata-tunnels.sh start
~/bin/sunyata-tunnels.sh stop
~/bin/sunyata-tunnels.sh status
~/bin/sunyata-tunnels.sh restart
```

---

## 🔧 Configuração Inicial (já feito)

### 1. Instalar autossh

```bash
sudo apt-get update && sudo apt-get install -y autossh
```

### 2. Arquivos criados

- **Script:** `~/bin/sunyata-tunnels.sh`
- **Service:** `~/.config/systemd/user/sunyata-tunnels.service`
- **Logs:** `~/.cache/sunyata-tunnels.log`

### 3. Habilitar auto-start

```bash
systemctl --user daemon-reload
systemctl --user enable sunyata-tunnels.service
systemctl --user start sunyata-tunnels.service
```

---

## 📊 Verificar Status

```bash
# Status detalhado
~/bin/sunyata-tunnels.sh status

# Saída exemplo:
# ✅ Tunnels RUNNING (PID: 12345)
#
# Port Status:
#   ✅ 8006 (Proxmox UI) - listening
#   ✅ 4000 (LiteLLM) - listening
#   ✅ 5678 (N8N) - listening
#
# Access:
#   - Proxmox: http://localhost:8006
#   - LiteLLM: http://localhost:4000
#   - N8N:     http://localhost:5678
```

### Verificar portas manualmente

```bash
ss -tlnp | grep -E ':(8006|4000|5678)'
```

---

## 🔍 Troubleshooting

### Problema: Tunnels não iniciam

**Verificar logs:**
```bash
journalctl --user -u sunyata-tunnels -n 50
# OU
tail -50 ~/.cache/sunyata-tunnels.log
```

**Testar SSH manualmente:**
```bash
ssh -N -L 8006:localhost:8006 ovh
```

Se falhar, verificar:
- `~/.ssh/config` tem entrada `Host ovh`
- Chave SSH está correta
- Servidor OVH está acessível

### Problema: Tunnels caem frequentemente

**autossh reconecta automaticamente**. Mas se cair muito:

1. Verificar latência: `ping 158.69.25.114`
2. Verificar SSH config (`ControlMaster` pode ajudar)
3. Aumentar `ServerAliveInterval` no script

### Problema: Porta já em uso

```bash
# Descobrir quem está usando a porta
sudo lsof -i :8006

# Matar processo se necessário
kill <PID>

# Reiniciar tunnels
systemctl --user restart sunyata-tunnels
```

---

## 🛠️ Personalização

### Adicionar nova porta

Editar `~/bin/sunyata-tunnels.sh`, adicionar na linha do `autossh`:

```bash
-L PORTA_LOCAL:DESTINO:PORTA_REMOTA \
```

Exemplo (adicionar Redis na 6379):
```bash
autossh -M 0 -N -f \
    ... \
    -L 6379:192.168.100.10:6379 \
    ovh
```

### Mudar parâmetros de reconexão

No script, ajustar:
- `ServerAliveInterval`: intervalo de keepalive (default: 30s)
- `ServerAliveCountMax`: tentativas antes de desconectar (default: 3)
- `RestartSec` (no service): tempo entre restarts (default: 10s)

---

## 📋 Manutenção

### Reiniciar systemd user daemon

Se modificar o `.service`:
```bash
systemctl --user daemon-reload
systemctl --user restart sunyata-tunnels
```

### Limpar logs antigos

```bash
# Ver tamanho do log
du -h ~/.cache/sunyata-tunnels.log

# Truncar se muito grande
: > ~/.cache/sunyata-tunnels.log
```

### Desabilitar auto-start

```bash
systemctl --user disable sunyata-tunnels.service
```

---

## 🔐 Segurança

### O que está exposto?

- **Portas locais** (127.0.0.1) - apenas acessíveis no seu WSL
- **Não expõe** nada para a rede externa
- **Requer** autenticação SSH (chave privada)

### Credenciais de acesso

Após acessar via tunnel:

| Serviço | URL | Login | Senha |
|---------|-----|-------|-------|
| Proxmox | http://localhost:8006 | root | (arquivo `~/.ovcloud`) |
| LiteLLM | http://localhost:4000/ui | admin | admin123 |
| N8N | http://localhost:5678 | sunyata-admin | N8n-Sunyata-2026! |

---

## 🎓 Como Funciona?

```
┌─────────────┐           SSH Tunnel          ┌──────────────┐
│  WSL Local  │ ════════════════════════════> │  OVH Server  │
│             │                                │              │
│ :8006 ───────────────────────────────────────> :8006       │ Proxmox
│ :4000 ────────────────────────> CT103:4000 │              │ LiteLLM
│ :5678 ────────────────────────> CT104:5678 │              │ N8N
└─────────────┘                                └──────────────┘
```

**autossh** monitora a conexão e reconecta automaticamente se cair.

**systemd** gerencia o processo, inicia automaticamente no boot, e reinicia se falhar.

---

## 📝 Changelog

- **2026-02-13**: Criação inicial - systemd service + autossh
- **2026-02-13**: Separação N8N (CT 104) - porta 5678 atualizada

---

**Mantido por:** Claude (Executor Principal)
**Versão:** 1.0
