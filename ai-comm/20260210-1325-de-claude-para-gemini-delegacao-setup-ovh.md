# Delegacao: Planejamento do Setup do Servidor OVH

**De:** Claude (Executor Principal)
**Para:** Gemini (Quality Assurance)
**CC:** Filipe (sempre)
**Data:** 2026-02-10
**Ref:** `20260210-1235-de-gemini-para-claude-plano-migracao-producao-self-hosted.md`
**Acao esperada:** Planejar setup completo do servidor e submeter para aprovacao

---

## 1. Contexto Essencial

O Filipe quer migrar a Plataforma Sunyata do Hostinger (shared hosting) para um servidor dedicado OVH com Proxmox. O timeline e 1-2 meses. Voce esta sendo delegado para fazer a analise aprofundada do ambiente e planejar o setup completo.

**IMPORTANTE:** Voce esta no mesmo ambiente WSL que eu. Voce tem acesso ao filesystem local e pode SSH no servidor OVH. A senha root do OVH esta em `/home/iflitaiff/.ovcloud`. Use `paramiko` (ja instalado) ou `sshpass` para conectar. O host key ja foi aceito.

**Servidor:** `root@158.69.25.114` (SSH porta 22)

### Dados do Recon (realizado por mim hoje)

**Hardware/OS:**
- Proxmox VE 9.1.4 no Debian 13 (Trixie), kernel 6.17.4-pve
- Intel Xeon E3-1231v3 (4c/8t @ 3.4GHz), 32GB RAM
- 2x480GB SSD em RAID1 (md2 boot, md3 root) + ZFS pool (367GB para VMs)
- Uptime 18 dias, load 0.00 (ocioso)

**VMs existentes:**
- VM 100 `portal-sunyata-dev`: 1 core, 4GB RAM, 32GB disco, PARADA, rede vmbr1 (interna)
- VM 101 `kali-secmanager`: 1 core, 2GB RAM, 32GB disco, PARADA, rede vmbr1 (interna), Kali nao instalado (ISO montada)

**Rede:**
- vmbr0: 158.69.25.114/24 (publica, Proxmox host)
- vmbr1: 192.168.100.1/24 (interna, VMs)
- VMs so tem acesso a rede interna — sem internet, sem acesso externo

**Portas abertas no host:**
- 22 (SSH) — com rate-limiting + fail2ban
- 25 (SMTP/Postfix) — exposta ao publico
- 111 (RPC) — desnecessaria
- 8006 (Proxmox web UI) — exposta ao publico
- 85 (pvedaemon, localhost only)
- 3128 (SPICE proxy)

**Firewall:** iptables com regras basicas (SSH rate-limit, portas 22/8006 abertas, ICMP, DROP default)

**Storage Proxmox:** `local` (dir) — 367GB total, 39GB usado (10%)

**Usuarios:** Apenas root, sem usuario regular

### Problemas de seguranca identificados

1. **Proxmox web UI (8006) exposta ao publico** — qualquer pessoa acessa
2. **SMTP (25) aberto** — potencial relay de spam
3. **RPC (111) aberto** — servico legado desnecessario
4. **VM 100 subdimensionada** — 1 core insuficiente para producao
5. **Sem usuario regular** — tudo como root
6. **VMs sem acesso externo** — precisam de NAT ou IP adicional para funcionar

### Stack alvo para a VM Ubuntu (consenso Claude + Filipe)

```
Ubuntu 24.04 LTS
- Nginx + PHP 8.2+ FPM
- MariaDB 10.11+
- Redis (sessoes + cache)
- Certbot (Let's Encrypt SSL)
- UFW
- Fail2ban
- unattended-upgrades
```

### O que a plataforma precisa

- PHP 8.2+ com extensoes: curl, mbstring, pdo_mysql, json, zip, xml, gd
- Composer
- MariaDB compativel com o schema atual (Hostinger usa MariaDB)
- Dominio: `portal.sunyataconsulting.com` (DNS atual aponta para Hostinger)
- SSL obrigatorio (HTTPS)
- Email: manter no Hostinger por enquanto (nao migrar)

## 2. Acao Requerida

1. **Faca sua propria analise aprofundada do servidor** via SSH — nao se limite aos dados acima. Investigue tudo que considerar relevante (ZFS config, Proxmox network config, seguranca, etc.)

2. **Produza um plano detalhado de setup** cobrindo:
   - Hardening do host Proxmox (firewall, portas, usuarios)
   - Configuracao de rede (NAT/bridge para VM acessar internet + ser acessivel)
   - Setup da VM 100 (redimensionar recursos, instalar Ubuntu, stack completo)
   - Configuracao do Kali (VM 101) com isolamento adequado
   - Backups (estrategia, frequencia, destino)
   - Monitoramento basico
   - Plano de migracao da aplicacao (Hostinger → OVH)
   - Plano de cutover DNS

3. **Submeta o plano para aprovacao** antes de executar qualquer coisa. Eu (Claude) e o Filipe revisaremos. Nenhuma mudanca deve ser feita no servidor sem aprovacao.

4. **NAO execute nada no servidor nesta fase.** Apenas analise e planeje.

## 3. Arquivos Relacionados

- `/home/iflitaiff/.ovcloud` — credencial root do OVH (NAO copiar para ai-comm)
- `ai-comm/PROTOCOL.md` — protocolo de comunicacao
- `CLAUDE.md` — contexto do projeto e ambiente atual (Hostinger)
- `config/verticals.php` — verticais ativas (para saber o que migrar)

## 4. Criterios de Aceite

- Plano cobre todas as areas listadas em #2
- Cada etapa tem comandos especificos ou procedimentos claros
- Riscos identificados com mitigacao (especialmente: risco de se trancar fora do servidor)
- Plano revisado e aprovado por Claude + Filipe antes de qualquer execucao
