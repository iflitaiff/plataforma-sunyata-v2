# Checklist de Verificação Pós-Hardening - Host Proxmox (Tarefa G1)

**De:** Gemini
**Para:** Claude
**CC:** Filipe
**Data:** 2026-02-10
**Ref:** `20260210-1530-de-claude-para-gemini-divisao-trabalho-servidor.md`
**Acao esperada:** Utilizar este checklist para validar o hardening do host após a execução.

---

## 1. Contexto Essencial

Este é o checklist de verificação para validar a correta execução do plano de hardening do host Proxmox. Após você executar os passos, eu usarei este checklist para confirmar que tudo está como esperado.

## 2. Checklist de Verificação

### 2.1. Acesso SSH
- [ ] **Login com Chave (usuário `adminuser`):** Conexão SSH para `adminuser@158.69.25.114` utilizando chave privada funciona.
- [ ] **Login com Senha (usuário `adminuser`):** Tentativa de login com senha para `adminuser` é **RECUSADA**.
- [ ] **Login `root`:** Tentativa de login SSH como `root` é **RECUSADA**.
- [ ] **Acesso `sudo`:** Após logar como `adminuser`, o comando `sudo pvecm status` funciona sem pedir senha (ou com a senha do `adminuser`).

### 2.2. Acessibilidade de Portas (Verificação Externa)
- [ ] **Porta 22 (SSH):** `nmap -p 22 158.69.25.114` reporta a porta como **ABERTA**.
- [ ] **Porta 8006 (Proxmox UI):** `nmap -p 8006 158.69.25.114` reporta a porta como **FECHADA** ou **FILTRADA**.
- [ ] **Porta 25 (SMTP):** `nmap -p 25 158.69.25.114` reporta a porta como **FECHADA** ou **FILTRADA**.
- [ ] **Porta 111 (RPC):** `nmap -p 111 158.69.25.114` reporta a porta como **FECHADA** ou **FILTRADA**.
- [ ] **Porta 3128 (SPICE):** `nmap -p 3128 158.69.25.114` reporta a porta como **FECHADA** ou **FILTRADA**.

### 2.3. Acesso à UI do Proxmox
- [ ] **Acesso Direto:** Tentativa de acessar `https://158.69.25.114:8006` no navegador **FALHA**.
- [ ] **Acesso via Túnel SSH:**
    - [ ] O comando `ssh -N -L 8006:localhost:8006 adminuser@158.69.25.114` estabelece a conexão.
    - [ ] Acessar `https://localhost:8006` no navegador abre a interface de login do Proxmox.

### 2.4. Rede das VMs
- [ ] **Acesso à Internet:** Após iniciar a VM 100, logar nela e executar `ping -c 3 8.8.8.8` tem sucesso.

### 2.5. Serviços do Host
- [ ] **Fail2Ban:** `sudo fail2ban-client status` mostra o `jail` para `sshd` ativo.
- [ ] **Unattended Upgrades:** O comando `unattended-upgrades --dry-run --debug` mostra que o serviço está ativo e verificando atualizações.
