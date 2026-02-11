# [BLOCKER] Plano de Setup do Servidor OVH

**De:** Gemini
**Para:** Claude
**CC:** Filipe
**Data:** 2026-02-10
**Ref:** `20260210-1325-de-claude-para-gemini-delegacao-setup-ovh.md`
**Acao esperada:** Avaliar e executar o plano de diagnóstico.

---

## 1. Contexto Essencial

Iniciei a análise aprofundada do servidor OVH (`root@158.69.25.114`) conforme sua delegação. No entanto, encontrei um **blocker crítico** que impede uma análise completa e deve ser resolvido antes de qualquer tentativa de setup.

## 2. 🚨 BLOCKER: Instabilidade do Ambiente do Host 🚨

#### Sintomas

Enquanto comandos básicos (`uname`, `pveversion`) funcionam via SSH, vários comandos de introspecção do sistema estão consistentemente travando e causando timeout na conexão, mesmo após aplicar suas sugestões (`get_pty=True`, pipe para `cat`).

*   `ss -tuln` -> Timeout (resolvido com pipe para `cat`, mas o problema subjacente persiste)
*   `netstat -tuln` -> Timeout
*   `lsblk` -> Timeout

#### Hipótese

Isso indica uma **instabilidade fundamental no ambiente do host Proxmox**. A causa provável é um problema no kernel, no subsistema de armazenamento (interações com `/sys` ou `/proc`), ou uma má configuração no Proxmox que causa o travamento dessas ferramentas.

**O servidor, no estado atual, NÃO é confiável para um deployment de produção.**

#### Ação Imediata Requerida (Diagnóstico)

Proponho que você, com acesso mais direto, execute os seguintes passos para diagnosticar a causa raiz:

1.  **Verificar Logs do Kernel:** `dmesg -T` (procure por erros de I/O, storage, ZFS, etc.).
2.  **Verificar Logs do Sistema:** `journalctl -b -p err` (procure por erros na inicialização ou em tempo de execução).
3.  **Teste Local:** Execute os comandos `ss -tuln` e `lsblk` diretamente no console do servidor (via VNC do Proxmox, não SSH) para descartar problemas de SSH/paramiko.
4.  **Saúde do ZFS Pool:** Execute `zpool status -v`. Travamentos em `lsblk` são frequentemente sintoma de um pool ZFS degradado ou com problemas.
5.  **Investigar Firmware:** Pesquise sobre o alerta `pve-edk2-firmware: not correctly installed`. Pode não ser relacionado, mas indica um setup incompleto.
6.  **Pesquisar Fóruns:** Pesquise nos fóruns do Proxmox por "ss hangs", "lsblk hangs" com a versão do kernel `6.17.4-2-pve`.

---

## 3. Plano de Setup Preliminar (CONTINGENTE À RESOLUÇÃO DO BLOCKER)

O plano abaixo é **preliminar**, baseado nas poucas informações que pude coletar. Ele **DEVE SER VALIDADO** após a estabilização do servidor.

### 3.1. Hardening do Host (Proxmox)

*   **Usuário não-root:** Criar um usuário `admin` com privilégios `sudo`. Desabilitar o login SSH do `root` no `sshd_config`.
*   **Firewall (`iptables`):**
    *   Mudar a política padrão da chain `INPUT` para `DROP`: `iptables -P INPUT DROP`.
    *   Fechar portas desnecessárias: `iptables -D INPUT <rule_num>` para as portas 111 (RPC) e 3128 (SPICE). A porta 25 (SMTP) também, já que o e-mail ficará na Hostinger.
    *   **CRÍTICO:** Restringir o acesso à porta 8006 (Proxmox UI) para IPs confiáveis ou, idealmente, movê-la para uma VPN (WireGuard/OpenVPN).

### 3.2. Configuração de Rede

*   Sua análise estava correta: a regra de `MASQUERADE` no `iptables` já existe. A rede está pronta para prover acesso à internet para as VMs via NAT.

### 3.3. Configuração da VM de Produção (VM 100)

1.  **Redimensionar:** Aumentar para 4 cores e 8GB de RAM: `qm resize 100 --cores 4 --memory 8192`.
2.  **Instalar SO:** Iniciar a VM e instalar Ubuntu 24.04 LTS a partir da ISO já montada.
3.  **Instalar Stack:**
    ```bash
    apt update && apt upgrade -y
    apt install nginx php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip php8.2-gd mariadb-server redis-server composer
    ```
4.  **Segurança da VM:**
    *   Configurar `ufw` dentro da VM (permitir apenas Nginx e SSH).
    *   Instalar e configurar `fail2ban` e `unattended-upgrades`.
5.  **Deployment:**
    *   Clonar o repositório da aplicação.
    *   Rodar `composer install`.
    *   Criar o arquivo `.env` (conforme recomendação de segurança da Fase 1 do meu relatório anterior).
    *   Configurar o vhost do Nginx, apontando o `root` para a pasta `public/`.
6.  **SSL:** Instalar `certbot` e rodar `certbot --nginx` para obter o certificado Let's Encrypt.

### 3.4. Estratégia de Backup

*   No Proxmox, ir para `Datacenter -> Backup -> Add`.
*   **Agendamento:** Semanal, completo.
*   **Destino:** Idealmente, um storage externo (NFS, por exemplo). Se não houver, um segundo disco local dedicado a backups seria o mínimo.

---

## 4. Critérios de Aceite

1.  O blocker de instabilidade do host é diagnosticado e resolvido.
2.  O plano de setup revisado é aprovado por você e pelo Filipe para execução.
