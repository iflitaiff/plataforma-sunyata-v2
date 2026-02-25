---
name: deploy
description: Deploy changes to VM100 (PHP portal, FastAPI, or composer) or Hostinger (legacy). Use when user says /deploy or asks to deploy.
argument-hint: [target: portal|fastapi|composer|hostinger|all]
disable-model-invocation: true
---

# Deploy Skill

Deploy changes to production servers. Always verify after deploy.

## Targets

### `/deploy portal` — PHP Portal (VM100)
```bash
tools/ssh-cmd.sh vm100 "cd /var/www/sunyata && git pull"
```
**Verify:** `tools/ssh-cmd.sh vm100 "curl -s -o /dev/null -w '%{http_code}' http://localhost/login.php"`
Expected: `200` or `302`

### `/deploy fastapi` — AI Microservice (VM100)
```bash
# Find current process
tools/ssh-cmd.sh vm100 "ps aux | grep 'uvicorn app.main' | grep -v grep"
# Kill old process (replace PID)
tools/ssh-cmd.sh vm100 "kill <PID>"
# Pull latest code
tools/ssh-cmd.sh vm100 "cd /var/www/sunyata && git pull"
# Start new process
tools/ssh-cmd.sh vm100 "cd /var/www/sunyata/services/ai && nohup python3 -m uvicorn app.main:app --host 0.0.0.0 --port 8000 > /tmp/uvicorn.log 2>&1 &"
# Wait and verify
sleep 3
tools/ssh-cmd.sh vm100 "curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/docs"
```
Expected: `200`

### `/deploy composer` — PHP Dependencies (VM100)
```bash
tools/ssh-cmd.sh vm100 "cd /var/www/sunyata/app && composer install --no-dev --optimize-autoloader"
```

### `/deploy hostinger` — Legacy (Hostinger)
Requires specific file path. Copy individual files:
```bash
scp -P 65002 app/public/PATH/file.php u202164171@82.25.72.226:/home/u202164171/domains/sunyataconsulting.com/public_html/plataforma-sunyata/public/PATH/file.php
```

### `/deploy all` — Full Deploy (VM100)
Run in sequence: portal → composer → fastapi. Verify each step before proceeding.

## Rules
- Always `git pull` on VM100 (don't copy files manually unless git is broken)
- FastAPI has NO systemd service — must find PID, kill, restart manually
- After deploy, verify the service responds before reporting success
- If git pull has conflicts, STOP and ask the user
