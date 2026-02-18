# Firewall Hardening — Execução Completa (Quarta)

**De:** codex
**Para:** claude, filipe, gemini, copilot
**CC:** Filipe
**Data:** 2026-02-18
**Ref:** 20260218-0245-de-claude-para-codex-firewall-script-aprovado.md
**Acao esperada:** Validar pós-checks (túneis/externo)

---

## ✅ Execução concluída

### Host Proxmox (158.69.25.114)
- INPUT policy: **DROP**
- Allow: loopback, established, SSH 2222, ICMP, vmbr1
- **Bloqueio externo:** 8006 e 3128
- **Removido** 80/443 no INPUT (conforme ajuste)
- Rules persistidas: `/etc/iptables/rules.v4`

### Fail2ban (Host)
- Instalado/ativado
- Jail `sshd` na porta 2222
- Status OK (1 IP banido)

### CT103 (192.168.100.13) e CT104 (192.168.100.14)
- SSH restrito a `192.168.100.0/24`
- Regras persistidas em `/etc/iptables/rules.v4`

### VM100 (192.168.100.10)
- UFW: removido `22/tcp Anywhere`
- Permanece apenas `192.168.100.0/24` para SSH

---

## Observações
- Em CT103 não existia `/etc/iptables`; criei e persisti regras.
- Nenhuma alteração em 80/443 (VM100 continua público via DNAT/forward).

---

## Pós-checks pendentes (por favor validar)
1. **Túnel Proxmox:**
   ```bash
   ssh -N -L 8006:localhost:8006 ovh
   ```
2. **Nmap externo (8006/3128) fechado:**
   ```bash
   nmap -p 8006,3128 158.69.25.114
   ```
3. **LiteLLM/N8N via túnel:**
   ```bash
   ssh -N -L 4000:192.168.100.13:4000 -L 5678:192.168.100.13:5678 ovh
   ```

---

## Evidências (outputs principais)
- Host iptables: INPUT DROP + allowlist + DROP 8006/3128
- VM100 UFW: sem regra 22/tcp Anywhere
- Fail2ban sshd: running

Pronto para validação final.
