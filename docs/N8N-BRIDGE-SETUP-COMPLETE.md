# N8N Bridge - Configuração Completa para Claude Chat

## ✅ CONFIGURAÇÃO CONCLUÍDA

### 🔐 Credenciais e URLs

```bash
# URL Base (HTTPS válido com Let's Encrypt)
BASE_URL=https://158-69-25-114.sslip.io

# Token de Autenticação (header: X-Auth-Token)
AUTH_TOKEN=daea65de34f273e6755a5ebe9c1bd254243b7b54cc5cd716a9de870c1db6145c

# Proxmox API Token
PROXMOX_TOKEN_ID=bridge@pve!n8n-bridge
PROXMOX_SECRET=c50cb2e6-e05e-48b4-8acf-8354f0ff1b14
```

---

## 🎯 Como o Claude Chat Vai Usar

### Exemplo de Uso no Claude Chat (claude.ai)

Você pode instruir o Claude Chat da seguinte forma:

**Prompt inicial para configurar:**
```
Salve nas suas memórias as seguintes informações para acesso ao servidor OVH:

URL Base: https://158-69-25-114.sslip.io
Auth Token: daea65de34f273e6755a5ebe9c1bd254243b7b54cc5cd716a9de870c1db6145c

Quando eu pedir "status do servidor" ou "listar VMs", use web_fetch com:
- URL: https://158-69-25-114.sslip.io/webhook/[endpoint]
- Header: X-Auth-Token: daea65de34f273e6755a5ebe9c1bd254243b7b54cc5cd716a9de870c1db6145c
```

**Exemplos de comandos:**
```
"Verifique o status do servidor"
→ Claude Chat fará: web_fetch("https://158-69-25-114.sslip.io/webhook/health")

"Liste as VMs do Proxmox"
→ web_fetch("https://158-69-25-114.sslip.io/webhook/proxmox/vms", headers: {"X-Auth-Token": "..."})

"Qual o status do Docker?"
→ web_fetch("https://158-69-25-114.sslip.io/webhook/docker/status", headers: {"X-Auth-Token": "..."})
```

---

## 📋 Workflows Criados

### ✅ 1. Health Check
- **URL:** `GET https://158-69-25-114.sslip.io/webhook/health`
- **Auth:** Não requer
- **Response:**
```json
{
  "status": "ok",
  "timestamp": "2026-02-19T...",
  "service": "n8n-bridge",
  "version": "1.0",
  "uptime": 12345
}
```

### ✅ 2. List Endpoints
- **URL:** `GET https://158-69-25-114.sslip.io/webhook/endpoints`
- **Auth:** Requer X-Auth-Token
- **Response:** Lista de todos os endpoints disponíveis

---

## 🔧 Workflows Pendentes (Para Criar no N8N)

Os JSONs dos workflows estão em `/tmp/n8n-workflows/`

### Como Importar no N8N:

1. **Acesse N8N:**
   - URL local (com SSH tunnel ativo): http://localhost:5678
   - Login: `sunyata-admin` / `N8n-Sunyata-2026!`

2. **Importar Workflow:**
   - Clique em "+" (New Workflow)
   - Menu "..." → "Import from File"
   - Selecione o JSON
   - Clique em "Save" e depois "Activate"

### 📦 Workflows a Criar:

#### 3. Proxmox Status
```json
GET /webhook/proxmox/status

Node 1: Webhook (path: proxmox/status)
Node 2: IF (Check X-Auth-Token)
Node 3: HTTP Request:
  - Method: GET
  - URL: https://localhost:8006/api2/json/cluster/status
  - Headers:
    - Authorization: PVEAPIToken=bridge@pve!n8n-bridge=c50cb2e6-e05e-48b4-8acf-8354f0ff1b14
  - SSL: Ignore Certificate Errors = true
Node 4: Respond to Webhook
```

#### 4. List VMs
```json
GET /webhook/proxmox/vms

Node 1: Webhook (path: proxmox/vms)
Node 2: IF (Check X-Auth-Token)
Node 3: HTTP Request (Get VMs):
  - URL: https://localhost:8006/api2/json/nodes/anatta/qemu
  - Headers: Authorization (Proxmox token)
Node 4: HTTP Request (Get Containers):
  - URL: https://localhost:8006/api2/json/nodes/anatta/lxc
  - Headers: Authorization (Proxmox token)
Node 5: Merge (Combine results)
Node 6: Code (Format response):
  const vms = $input.all().filter(i => i.json.type === 'qemu');
  const cts = $input.all().filter(i => i.json.type === 'lxc');
  return [{
    json: {
      success: true,
      vms: vms,
      containers: cts,
      total: vms.length + cts.length
    }
  }];
Node 7: Respond to Webhook
```

#### 5. Docker Status
```json
GET /webhook/docker/status

Node 1: Webhook (path: docker/status)
Node 2: IF (Check X-Auth-Token)
Node 3: Execute Command (SSH to host):
  - Command: docker ps --format '{"name":"{{.Names}}","status":"{{.Status}}","image":"{{.Image}}"}'
  - Host: 192.168.100.14 (CT104) or localhost
Node 4: Code (Parse JSON lines)
Node 5: Respond to Webhook
```

#### 6. Execute Command (⚠️ CUIDADO - Security Critical)
```json
POST /webhook/system/exec
Body: {"command": "df -h"}

Node 1: Webhook (path: system/exec, POST)
Node 2: IF (Check X-Auth-Token)
Node 3: Code (Whitelist validation):
  const ALLOWED = ['df -h', 'free -m', 'uptime', 'docker ps', 'systemctl status'];
  const cmd = $json.body.command;
  const allowed = ALLOWED.some(a => cmd === a || cmd.startsWith(a + ' '));
  if (!allowed) {
    throw new Error('Command not in whitelist');
  }
  return [$input.item];
Node 4: Execute Command (SSH)
Node 5: Code (Format response)
Node 6: Respond to Webhook
```

#### 7. VM Actions
```json
POST /webhook/proxmox/vm-action
Body: {"vmid": "100", "type": "qemu", "action": "start"}

Node 1: Webhook (path: proxmox/vm-action, POST)
Node 2: IF (Check X-Auth-Token)
Node 3: IF (Validate action in whitelist: start, stop, shutdown, reboot)
Node 4: HTTP Request:
  - Method: POST
  - URL: https://localhost:8006/api2/json/nodes/anatta/{{$json.body.type}}/{{$json.body.vmid}}/status/{{$json.body.action}}
  - Headers: Authorization (Proxmox token)
Node 5: Respond to Webhook
```

---

## 🔒 Segurança Implementada

### ✅ HTTPS
- Certificado Let's Encrypt válido
- Auto-renovação configurada (expira 2026-05-20)
- Redirect HTTP → HTTPS

### ✅ Rate Limiting
- 10 requisições/minuto por IP (configurado no Nginx)
- Burst de 5 requisições permitido

### ✅ Token Authentication
- Todos os endpoints (exceto /health) requerem X-Auth-Token
- Token: 256 bits, gerado com openssl

### ✅ Path Restriction
- Apenas `/webhook/*` é acessível externamente
- UI do N8N bloqueada (retorna 403)

### ✅ Proxmox API
- Usuário `bridge@pve` com permissões mínimas:
  - PVEAuditor (leitura)
  - PVEVMUser (ações em VMs)
- Token com privilege separation (`--privsep=1`)

---

## 🧪 Testes

### Test 1: Health Check (sem auth)
```bash
curl https://158-69-25-114.sslip.io/webhook/health
# Expected: {"status":"ok",...}
```

### Test 2: List Endpoints (com auth)
```bash
TOKEN="daea65de34f273e6755a5ebe9c1bd254243b7b54cc5cd716a9de870c1db6145c"
curl -H "X-Auth-Token: $TOKEN" https://158-69-25-114.sslip.io/webhook/endpoints
# Expected: Lista de endpoints
```

### Test 3: Sem auth (deve retornar 401)
```bash
curl https://158-69-25-114.sslip.io/webhook/endpoints
# Expected: {"error":"Unauthorized",...}
```

### Test 4: Path bloqueado (deve retornar 403)
```bash
curl https://158-69-25-114.sslip.io/
# Expected: "Access denied..."
```

---

## 📊 Arquitetura

```
Claude Chat (claude.ai)
  ↓ web_fetch(URL, headers: {X-Auth-Token})
  ↓
HTTPS (158-69-25-114.sslip.io:443)
  ↓ Let's Encrypt SSL
  ↓
Nginx on VM100 (192.168.100.10)
  ↓ Rate Limiting (10/min)
  ↓ Proxy /webhook/* only
  ↓
N8N on CT104 (192.168.100.14:5678)
  ↓ Token validation
  ↓ Execute workflow
  ↓
  ├─→ Proxmox API (localhost:8006)
  ├─→ SSH Commands (host/VMs/CTs)
  └─→ Docker commands (CT104)
```

---

## 🔄 Manutenção

### Renovação de Certificado SSL
Automática via Certbot (systemd timer).

Verificar status:
```bash
ssh-cmd.sh vm100 "sudo certbot certificates"
```

Forçar renovação manual (se necessário):
```bash
ssh-cmd.sh vm100 "sudo certbot renew --force-renewal"
```

### Logs

**Nginx (VM100):**
```bash
ssh-cmd.sh vm100 "sudo tail -f /var/log/nginx/access.log"
ssh-cmd.sh vm100 "sudo tail -f /var/log/nginx/error.log"
```

**N8N (CT104):**
```bash
ssh-cmd.sh host "pct exec 104 -- docker logs -f n8n"
```

### Monitoramento

Status dos serviços:
```bash
# Nginx
ssh-cmd.sh vm100 "sudo systemctl status nginx"

# N8N
ssh-cmd.sh host "pct exec 104 -- docker ps"

# Certificado SSL
curl -I https://158-69-25-114.sslip.io 2>&1 | grep -i expire
```

---

## ⚙️ Próximos Passos

1. ✅ **HTTPS configurado** - https://158-69-25-114.sslip.io
2. ✅ **Workflows básicos criados** - Health Check, List Endpoints
3. ⏳ **Criar workflows restantes** - Proxmox Status, List VMs, Docker, etc.
4. ⏳ **Testar cada endpoint**
5. ⏳ **Configurar Claude Chat** - Salvar URL + Token nas memórias

---

## 📞 Suporte

Se houver problemas:

1. Verificar se N8N está rodando: `ssh-cmd.sh host "pct exec 104 -- docker ps"`
2. Verificar logs do Nginx: `ssh-cmd.sh vm100 "sudo tail -50 /var/log/nginx/error.log"`
3. Testar health endpoint: `curl https://158-69-25-114.sslip.io/webhook/health`
4. Verificar firewall: Portas 80 e 443 devem estar abertas no OVH

---

**Documento gerado em:** 2026-02-19
**Versão:** 1.0
**Status:** Infraestrutura HTTPS completa, workflows básicos criados
