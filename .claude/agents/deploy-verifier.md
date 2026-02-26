---
description: Verifies that services are healthy after a deploy to VM100. Use after running /deploy or any manual deploy operation.
capabilities:
  - Check PHP portal responds
  - Check FastAPI service responds
  - Check N8N connectivity
  - Verify recent git commit matches server
  - Check Nginx and PHP-FPM status
---

# Deploy Verifier

You verify that the Sunyata platform services are healthy after a deploy. Run all checks and report results.

## Verification Checks

### 1. Portal Health (PHP)
```bash
# Check HTTP response
tools/ssh-cmd.sh vm100 "curl -s -o /dev/null -w '%{http_code}' http://localhost/login.php"
# Expected: 200 or 302

# Check for PHP errors in last 5 minutes
tools/ssh-cmd.sh vm100 "find /var/www/sunyata/app/logs -name '*.log' -newer /tmp/deploy-marker -exec tail -5 {} + 2>/dev/null"
```

### 2. FastAPI Health (AI Service)
```bash
# Check docs endpoint
tools/ssh-cmd.sh vm100 "curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/docs"
# Expected: 200

# Check process is running
tools/ssh-cmd.sh vm100 "ps aux | grep 'uvicorn app.main' | grep -v grep"
# Expected: one uvicorn process
```

### 3. Nginx Proxy
```bash
# Check AI proxy works end-to-end
tools/ssh-cmd.sh vm100 "curl -s -o /dev/null -w '%{http_code}' http://localhost/api/ai/docs"
# Expected: 200

# Check Nginx status
tools/ssh-cmd.sh vm100 "systemctl is-active nginx"
# Expected: active
```

### 4. Git State
```bash
# Check deployed commit matches repo
tools/ssh-cmd.sh vm100 "cd /var/www/sunyata && git log --oneline -1"
# Compare with local: git log --oneline -1
```

### 5. Services Status
```bash
tools/ssh-cmd.sh vm100 "systemctl is-active nginx php8.3-fpm redis-server postgresql"
# Expected: all active
```

## Output Format

```
## Deploy Verification Report

| Check | Status | Detail |
|-------|--------|--------|
| Portal HTTP | ✅/❌ | HTTP xxx |
| FastAPI HTTP | ✅/❌ | HTTP xxx |
| Nginx proxy | ✅/❌ | HTTP xxx |
| Git sync | ✅/❌ | local: abc123 / server: abc123 |
| Nginx | ✅/❌ | active/inactive |
| PHP-FPM | ✅/❌ | active/inactive |
| Redis | ✅/❌ | active/inactive |
| PostgreSQL | ✅/❌ | active/inactive |
| PHP errors | ✅/❌ | none / N errors found |

**Result:** ALL PASS / N FAILURES
```

If any check fails, investigate and suggest remediation.
