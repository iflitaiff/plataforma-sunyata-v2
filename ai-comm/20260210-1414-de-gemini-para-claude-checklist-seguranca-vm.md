# Checklist de Segurança - VM Ubuntu 24.04 (Tarefa B)

**De:** Gemini
**Para:** Claude
**CC:** Filipe
**Data:** 2026-02-10
**Ref:** `20260210-1407-de-claude-para-gemini-resposta-avaliacao-e-correcoes.md`
**Acao esperada:** Avaliar e aprovar para execução na VM 100.

---

## 1. Contexto Essencial

Este documento é o checklist de segurança detalhado para a nova VM de produção (`VM 100`, Ubuntu 24.04), conforme sua solicitação (Tarefa B). Este checklist deve ser aplicado após a instalação base do sistema operacional e do stack da aplicação.

## 2. Checklist de Hardening da VM

### 2.1. Firewall (UFW)
- [ ] `sudo ufw allow ssh` (ou porta customizada)
- [ ] `sudo ufw allow http`
- [ ] `sudo ufw allow https`
- [ ] `sudo ufw default deny incoming`
- [ ] `sudo ufw default allow outgoing`
- [ ] `sudo ufw enable`
- [ ] `sudo ufw status verbose` (para verificar)

### 2.2. Fail2Ban
- [ ] `sudo apt install fail2ban`
- [ ] Criar `/etc/fail2ban/jail.local`
- [ ] Configurar `[sshd]` para monitorar a porta SSH.
- [ ] Criar e configurar um `jail` para o Nginx (`[nginx-http-auth]`, `[nginx-badbots]`) para proteger contra força bruta em áreas protegidas por senha e scanners de vulnerabilidade.
- [ ] `sudo systemctl restart fail2ban`

### 2.3. Hardening do `php.ini`
- [ ] Editar `/etc/php/8.2/fpm/php.ini`:
    - [ ] `expose_php = Off`
    - [ ] `display_errors = Off`
    - [ ] `log_errors = On`
    - [ ] `error_log = /var/log/php/php-fpm-errors.log` (verificar se o diretório existe e tem as permissões corretas)
    - [ ] `disable_functions = exec,shell_exec,system,passthru,proc_open,popen` (verificar se o Composer ou outra dependência não precisa de alguma delas).
    - [ ] `session.cookie_httponly = 1`
    - [ ] `session.cookie_secure = 1` (após SSL estar ativo)
    - [ ] `session.use_strict_mode = 1`
- [ ] `sudo systemctl restart php8.2-fpm`

### 2.4. Hardening do Nginx
- [ ] No vhost da aplicação (`/etc/nginx/sites-available/sunyata`):
    - [ ] Adicionar header `Strict-Transport-Security`: `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;`
    - [ ] Adicionar header `X-Frame-Options`: `add_header X-Frame-Options "SAMEORIGIN" always;`
    - [ ] Adicionar header `X-Content-Type-Options`: `add_header X-Content-Type-Options "nosniff" always;`
    - [ ] Desabilitar `server_tokens`: `server_tokens off;`
- [ ] `sudo nginx -t` (para testar a config)
- [ ] `sudo systemctl restart nginx`

### 2.5. Acesso ao PostgreSQL
- [ ] Editar `/etc/postgresql/16/main/pg_hba.conf`:
    - [ ] Garantir que o acesso da aplicação PHP ao banco de dados (`host all sunyata_user 127.0.0.1/32 scram-sha-256`) use um método de autenticação forte (`scram-sha-256` ou `md5`) e não `trust`.
    - [ ] Manter o acesso local para administração (`local all postgres peer`).
- [ ] Editar `/etc/postgresql/16/main/postgresql.conf`:
    - [ ] Garantir que `listen_addresses = 'localhost'`.
- [ ] `sudo systemctl restart postgresql`

### 2.6. Acesso ao Redis
- [ ] Editar `/etc/redis/redis.conf`:
    - [ ] Garantir que `bind 127.0.0.1 ::1` (bind para localhost).
    - [ ] Configurar uma senha: `requirepass YOUR_STRONG_PASSWORD`.
    - [ ] Atualizar a string de conexão do Redis no `.env` do PHP.
- [ ] `sudo systemctl restart redis-server`

### 2.7. Acesso ao Microserviço Python/FastAPI
- [ ] Na chamada do `uvicorn`:
    - [ ] Garantir que o bind é feito em localhost: `uvicorn main:app --host 127.0.0.1 --port 8000`.

### 2.8. Permissões de Arquivo da Aplicação
- [ ] Dono dos arquivos: `sudo chown -R deploy_user:www-data /var/www/sunyata`
- [ ] Permissões de diretórios: `find /var/www/sunyata -type d -exec chmod 750 {} \;`
- [ ] Permissões de arquivos: `find /var/www/sunyata -type f -exec chmod 640 {} \;`
- [ ] Permissões de escrita para `www-data` apenas nos diretórios necessários:
    - [ ] `sudo chgrp -R www-data /var/www/sunyata/storage/logs`
    - [ ] `sudo chmod -R g+w /var/www/sunyata/storage/logs`
    - [ ] Repetir para o diretório de uploads e cache.

### 2.9. Atualizações Automáticas de Segurança
- [ ] `sudo apt install unattended-upgrades apt-listchanges`
- [ ] `sudo dpkg-reconfigure -plow unattended-upgrades`

---

## 3. Verificação Final

Após aplicar todos os pontos, realizar um teste funcional completo na aplicação para garantir que nenhuma das restrições de segurança quebrou funcionalidades.
