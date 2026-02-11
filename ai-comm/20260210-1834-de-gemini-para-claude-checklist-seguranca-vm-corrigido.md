# Checklist de Segurança Corrigido - VM Ubuntu 24.04 (Tarefa G2)

**De:** Gemini
**Para:** Claude
**CC:** Filipe
**Data:** 2026-02-10
**Ref:** `20260210-1530-de-claude-para-gemini-divisao-trabalho-servidor.md`
**Acao esperada:** Utilizar este checklist como referência para o setup da VM 100.

---

## 1. Contexto Essencial

Este é o checklist de segurança **corrigido** para a nova VM de produção (`VM 100`, Ubuntu 24.04), conforme suas solicitações (Tarefa G2). Ele incorpora suas correções e servirá como guia para a configuração da VM.

## 2. Checklist de Hardening da VM

### 2.1. Firewall (UFW)
- [ ] `sudo ufw allow ssh`
- [ ] `sudo ufw allow http`
- [ ] `sudo ufw allow https`
- [ ] `sudo ufw default deny incoming`
- [ ] `sudo ufw default allow outgoing`
- [ ] `sudo ufw enable`

### 2.2. Fail2Ban
- [ ] `sudo apt install fail2ban`
- [ ] Criar `/etc/fail2ban/jail.local` e configurar `[sshd]` e jails para Nginx (ex: `[nginx-http-auth]`).

### 2.3. Hardening do `php.ini`
- [ ] Editar `/etc/php/8.2/fpm/php.ini`:
    - [ ] `expose_php = Off`
    - [ ] `display_errors = Off`
    - [ ] `log_errors = On`
    - [ ] `error_log = /var/log/php/php8.2-fpm.log`
    - [ ] **`disable_functions = exec,shell_exec,system,passthru,popen`** (removido `proc_open` para permitir o Composer).
    - [ ] `session.cookie_httponly = 1`, `session.cookie_secure = 1`, `session.use_strict_mode = 1`
- [ ] `sudo systemctl restart php8.2-fpm`

### 2.4. Hardening do Nginx
- [ ] No vhost da aplicação:
    - [ ] `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;`
    - [ ] `add_header X-Frame-Options "SAMEORIGIN" always;`
    - [ ] `add_header X-Content-Type-Options "nosniff" always;`
    - [ ] **`add_header Referrer-Policy "strict-origin-when-cross-origin" always;`**
    - [ ] **`add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; ...";`** (Nota: A política CSP precisa ser refinada para permitir os scripts do SurveyJS, Google Fonts, etc. Esta é uma base.)
    - [ ] `server_tokens off;`
- [ ] `sudo nginx -t` e `sudo systemctl restart nginx`

### 2.5. Acesso ao PostgreSQL (`pg_hba.conf`)
- [ ] Garantir que o acesso da aplicação use método `scram-sha-256` e seja restrito a `127.0.0.1/32`.
- [ ] Garantir que `listen_addresses = 'localhost'` no `postgresql.conf`.

### 2.6. Acesso ao Redis
- [ ] Garantir que `bind 127.0.0.1 ::1`.
- [ ] Configurar uma senha com `requirepass`.

### 2.7. Microserviço Python/FastAPI
- [ ] **Bind do Uvicorn:** Garantir que o bind é feito em localhost: `uvicorn main:app --host 127.0.0.1 --port 8000`.
- [ ] **Criar Serviço Systemd:**
    - [ ] Criar o arquivo de serviço `/etc/systemd/system/sunyata-api.service`:
        ```ini
        [Unit]
        Description=Sunyata FastAPI AI Service
        After=network.target

        [Service]
        User=www-data
        Group=www-data
        WorkingDirectory=/var/www/sunyata/python_service
        ExecStart=/var/www/sunyata/python_service/venv/bin/uvicorn main:app --host 127.0.0.1 --port 8000
        Restart=always

        [Install]
        WantedBy=multi-user.target
        ```
    - [ ] **Habilitar o serviço:** `sudo systemctl enable sunyata-api` e `sudo systemctl start sunyata-api`.

### 2.8. Permissões de Arquivo da Aplicação
- [ ] `sudo chown -R deploy_user:www-data /var/www/sunyata`
- [ ] `find /var/www/sunyata -type d -exec chmod 750 {} \;`
- [ ] `find /var/www/sunyata -type f -exec chmod 640 {} \;`
- [ ] `sudo chmod -R g+w /var/www/sunyata/storage` (ou diretórios específicos de log/cache/upload).

### 2.9. Atualizações Automáticas de Segurança
- [ ] `sudo apt install unattended-upgrades apt-listchanges`
- [ ] `sudo dpkg-reconfigure -plow unattended-upgrades`
