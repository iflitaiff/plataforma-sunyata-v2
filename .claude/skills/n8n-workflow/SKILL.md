---
name: n8n-workflow
description: Safely view or update N8N workflows via API. Use when user says /n8n-workflow or needs to modify N8N workflows.
argument-hint: [action: list|get|update] [workflow-id]
disable-model-invocation: true
---

# N8N Workflow Skill

Interact with N8N workflows via the REST API safely. Encodes all restrictions and patterns.

## Prerequisites
- SSH tunnel to N8N must be active: `systemctl --user status sunyata-tunnels`
- If tunnel is down: `systemctl --user restart sunyata-tunnels`

## API Access
```bash
N8N_URL="http://localhost:5678/api/v1"
N8N_KEY="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI3NmIyY2JjZS1lOGY5LTRkOWYtOGZjMi1mZDEwZDFjMTU0ZTYiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwianRpIjoiZWU1OWVjODYtZDE3Yy00OWQ0LTg1NzUtNzZmZGE4ODc0MWQ2IiwiaWF0IjoxNzcxNjI2NDY2fQ.-NrWdfSkZKOGmVRqvGtnIISaNrnep6ZTxXvrZsMzNik"
```

## Actions

### List all workflows
```bash
curl -s "$N8N_URL/workflows" -H "X-N8N-API-KEY: $N8N_KEY" | python3 -c "
import json,sys
for w in json.load(sys.stdin)['data']:
    print(f\"{w['id']} | {'ACTIVE' if w.get('active') else 'INACTIVE':8} | {len(w.get('nodes',[]))} nodes | {w['name']}\")
"
```

### Get workflow details
```bash
curl -s "$N8N_URL/workflows/WORKFLOW_ID" -H "X-N8N-API-KEY: $N8N_KEY" > /tmp/n8n-workflow.json
```

### Update workflow (SAFE pattern)

**CRITICAL: Always follow this exact sequence:**

1. **GET** the current workflow → save to `/tmp/n8n-workflow-original.json`
2. **Make changes** to the JSON
3. **Clean the payload** — strip everything except: `name`, `nodes`, `connections`, `settings`
4. **Clean settings** — only `executionOrder` is allowed. Strip `binaryMode`, `availableInMCP`, `callerPolicy`
5. **PUT** the cleaned payload

```python
# Clean payload script — save to /tmp/n8n-clean-and-put.py
import json, sys, subprocess

workflow_id = sys.argv[1]
data = json.load(open('/tmp/n8n-workflow.json'))

# Only allowed top-level keys
clean = {
    'name': data['name'],
    'nodes': data['nodes'],
    'connections': data['connections'],
    'settings': {'executionOrder': data.get('settings', {}).get('executionOrder', 'v1')}
}

# Write cleaned payload
with open('/tmp/n8n-workflow-clean.json', 'w') as f:
    json.dump(clean, f)

print(f"Cleaned payload: {len(clean['nodes'])} nodes")
```

Then PUT:
```bash
curl -s -X PUT "$N8N_URL/workflows/WORKFLOW_ID" \
  -H "X-N8N-API-KEY: $N8N_KEY" \
  -H "Content-Type: application/json" \
  -d @/tmp/n8n-workflow-clean.json | python3 -c "
import json,sys
d=json.load(sys.stdin)
print(f\"Updated: {d.get('name')} | Active: {d.get('active')} | Nodes: {len(d.get('nodes',[]))}\")"
```

## Known Workflows

| ID | Name | Status |
|----|------|--------|
| kWX9x3IteHYZehKC | PNCP Daily Monitor v3 | ACTIVE |
| 4HJSmPLYTNTUnO8y | IATR - Análise de Edital v2 | ACTIVE |
| rWDYKMY0Wav5dMpH | Portal - Send Email | ACTIVE |
| JzfXXdEuOOe7FFf6 | IATR - Análise de Edital v1 | INACTIVE — DO NOT MODIFY |

## TypeVersion Restrictions (MANDATORY)

When creating or modifying nodes, these typeVersions are REQUIRED:

| Node Type | typeVersion |
|-----------|-------------|
| Webhook | 1.1 |
| IF | 1 |
| Respond to Webhook (OK) | 1.5 |
| Respond to Webhook (Error) | 1 |
| HTTP Request | 4.2 |
| Code | 2 |

## Auth Pattern (MANDATORY for new webhooks)

Every exposed webhook MUST have:
1. Webhook node → IF node (validates `X-Auth-Token` header)
2. IF true → process
3. IF false → Respond to Webhook with 401

## Gotchas
- Webhook typeVersion 1.1: POST body fields are at `$json.body.*` (not `$json.*`)
- PUT keeps workflow active if already active. Only activate/deactivate conflicts cause webhook registration errors.
- Email Send node typeVersion 2.1: credential ID `TAZ8C6Oo3qLTak9d`
- Webhook nodes need `webhookId` field for production registration
