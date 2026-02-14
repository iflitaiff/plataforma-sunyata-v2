# N8N ↔ Portal Integration

Guia de integração bidirecional entre N8N (CT 104) e Portal Sunyata (VM 100).

---

## 🎯 Arquitetura de Integração

```
┌──────────────┐                    ┌──────────────┐
│   Portal     │ ═══ Webhooks ═══>  │     N8N      │
│   (VM 100)   │                    │   (CT 104)   │
│              │ <═══ API REST ═══  │              │
└──────┬───────┘                    └──────┬───────┘
       │                                   │
       └──────> PostgreSQL <───────────────┘
              (192.168.100.10:5432)
```

---

## 📤 Integração 1: Portal → N8N (Webhooks)

### Casos de Uso

1. **Formulário submetido** → Trigger workflow de processamento
2. **Documento criado** → Gerar resumo/análise
3. **Usuario registrado** → Enviar email de boas-vindas
4. **Relatório agendado** → Gerar e enviar PDF

### Implementação no Portal

#### Passo 1: Criar Webhook Sender (Portal)

**Arquivo:** `app/src/Services/WebhookService.php`

```php
<?php
namespace Sunyata\Services;

class WebhookService {
    private string $n8nBaseUrl = 'http://192.168.100.14:5678';
    private int $timeout = 10;

    public function trigger(string $event, array $payload): ?array {
        $url = "{$this->n8nBaseUrl}/webhook/{$event}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Webhook-Secret: ' . getenv('N8N_WEBHOOK_SECRET')
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Webhook failed: {$event} - HTTP {$httpCode}");
            return null;
        }

        return json_decode($response, true);
    }

    // Eventos disponíveis
    public function onFormSubmit(int $submissionId, array $data): void {
        $this->trigger('form-submitted', [
            'submission_id' => $submissionId,
            'user_id' => $_SESSION['user_id'] ?? null,
            'template' => $data['template'] ?? null,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }

    public function onDocumentCreated(int $documentId, string $type): void {
        $this->trigger('document-created', [
            'document_id' => $documentId,
            'type' => $type,
            'timestamp' => date('c')
        ]);
    }

    public function onUserRegistered(int $userId, string $email): void {
        $this->trigger('user-registered', [
            'user_id' => $userId,
            'email' => $email,
            'timestamp' => date('c')
        ]);
    }
}
```

#### Passo 2: Adicionar Hooks nos Endpoints

**Exemplo: `api/canvas/submit.php`**

```php
// Após salvar submission
$submissionId = $submission->getId();

// Trigger webhook N8N (async, non-blocking)
try {
    $webhook = new WebhookService();
    $webhook->onFormSubmit($submissionId, $formData);
} catch (\Exception $e) {
    // Log mas não falha o request
    error_log("Webhook error: " . $e->getMessage());
}
```

#### Passo 3: Configurar Secret

**Arquivo:** `app/.env`

```env
N8N_WEBHOOK_SECRET=sunyata-n8n-webhook-2026-secure-token
```

### Implementação no N8N

#### Criar Webhook Receiver

1. **Novo Workflow:** "Portal - Form Submitted"
2. **Trigger:** Webhook
   - **Path:** `form-submitted`
   - **Method:** POST
   - **Authentication:** Header Auth
   - **Header Name:** `X-Webhook-Secret`
   - **Header Value:** `sunyata-n8n-webhook-2026-secure-token`

3. **Processar payload:**
```javascript
// Exemplo: extrair dados
const submissionId = $json.body.submission_id;
const userId = $json.body.user_id;
const formData = $json.body.data;
```

4. **Ações:**
   - Chamar LiteLLM para processar
   - Salvar resultado no PostgreSQL
   - Enviar email/notificação
   - Atualizar status no portal

---

## 📥 Integração 2: N8N → Portal (API REST)

### Casos de Uso

1. **Atualizar status de documento**
2. **Criar notificação para usuário**
3. **Buscar dados de formulário**
4. **Agendar tarefa**

### Implementação no Portal

#### Passo 1: Criar API Endpoints

**Arquivo:** `app/public/api/n8n/update-document.php`

```php
<?php
require_once __DIR__ . '/../../config/config.php';

// Autenticação N8N
$apiKey = $_SERVER['HTTP_X_N8N_API_KEY'] ?? '';
if ($apiKey !== getenv('N8N_API_KEY')) {
    json_response(['error' => 'Unauthorized'], 401);
}

// Validar payload
$payload = json_decode(file_get_contents('php://input'), true);
if (!isset($payload['document_id'])) {
    json_response(['error' => 'document_id required'], 400);
}

// Atualizar documento
$pdo = getDbConnection();
$stmt = $pdo->prepare('
    UPDATE documents
    SET status = :status, processed_at = NOW(), metadata = :metadata
    WHERE id = :id
');

$stmt->execute([
    'id' => $payload['document_id'],
    'status' => $payload['status'] ?? 'processed',
    'metadata' => json_encode($payload['metadata'] ?? [])
]);

json_response(['success' => true, 'rows_affected' => $stmt->rowCount()]);
```

**Rotas recomendadas:**

| Endpoint | Método | Função |
|----------|--------|--------|
| `/api/n8n/update-document` | POST | Atualizar status/metadata de documento |
| `/api/n8n/create-notification` | POST | Criar notificação para usuário |
| `/api/n8n/get-submission` | GET | Buscar dados de formulário |
| `/api/n8n/update-submission` | POST | Atualizar submission (adicionar campos) |

#### Passo 2: Autenticação

**Gerar API Key:**
```bash
# Gerar token seguro
openssl rand -hex 32
# Output: a3f8b92c1e4d6f0a...
```

**Configurar:** `app/.env`
```env
N8N_API_KEY=a3f8b92c1e4d6f0a7b3c9e2d8f1a5c4b6d0e9f2a8c3b7e1d5f9a4c8b2e6d0f3a
```

### Implementação no N8N

#### Criar HTTP Request Node

**Configuração:**
- **Method:** POST
- **URL:** `http://192.168.100.10/api/n8n/update-document`
- **Authentication:** Header Auth
- **Header Name:** `X-N8N-API-Key`
- **Header Value:** `a3f8b92c1e4d6f0a...` (credential)

**Body:**
```json
{
  "document_id": {{ $json.document_id }},
  "status": "processed",
  "metadata": {
    "summary": {{ $json.summary }},
    "processed_by": "n8n-workflow-v1"
  }
}
```

---

## 🗄️ Integração 3: N8N → PostgreSQL (Direto)

### Quando Usar

- **Leitura:** Buscar dados para processar (relatórios, análises)
- **Escrita:** Salvar resultados diretamente (logs, cache, metrics)

### Configuração no N8N

1. **Credentials → Add Credential → Postgres**
   - **Name:** `Portal PostgreSQL`
   - **Host:** `192.168.100.10`
   - **Port:** `5432`
   - **Database:** `sunyata_platform`
   - **User:** `sunyata_app`
   - **Password:** `sunyata_app_2026`
   - **SSL:** Disabled (rede interna)

2. **Usar Postgres Node:**

**Exemplo: Buscar submissões recentes**
```sql
SELECT
    s.id,
    s.user_id,
    s.canvas_template_id,
    s.form_data,
    s.result,
    s.created_at,
    u.email,
    ct.name as template_name
FROM form_submissions s
JOIN users u ON s.user_id = u.id
JOIN canvas_templates ct ON s.canvas_template_id = ct.id
WHERE s.created_at > NOW() - INTERVAL '1 day'
ORDER BY s.created_at DESC
LIMIT 10
```

**Exemplo: Salvar log de processamento**
```sql
INSERT INTO n8n_processing_logs
    (submission_id, workflow_name, status, duration_ms, result, created_at)
VALUES
    ({{ $json.submission_id }}, '{{ $json.workflow }}', 'success', {{ $json.duration }}, '{{ $json.result }}', NOW())
```

### Criar Tabela de Logs (Migration)

**Arquivo:** `app/migrations/008-n8n-logs.sql`

```sql
-- Tabela de logs de processamento N8N
CREATE TABLE IF NOT EXISTS n8n_processing_logs (
    id SERIAL PRIMARY KEY,
    submission_id INT REFERENCES form_submissions(id) ON DELETE SET NULL,
    workflow_name VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL, -- success, error, timeout
    duration_ms INT,
    result JSONB,
    error_message TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW(),

    INDEX idx_logs_submission (submission_id),
    INDEX idx_logs_created (created_at DESC)
);

-- Tabela de jobs agendados
CREATE TABLE IF NOT EXISTS n8n_scheduled_jobs (
    id SERIAL PRIMARY KEY,
    job_name VARCHAR(255) NOT NULL UNIQUE,
    schedule JSONB NOT NULL, -- cron expression + config
    last_run_at TIMESTAMPTZ,
    next_run_at TIMESTAMPTZ,
    status VARCHAR(50) DEFAULT 'active',
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW(),

    INDEX idx_jobs_next_run (next_run_at)
);
```

---

## 🎯 Workflows Práticos (Exemplos)

### 1. Monitor PNCP (Licitações)

```
Cron (diário 8h)
  → HTTP Request (PNCP API)
  → IF (novos editais?)
    → PostgreSQL (salvar edital)
    → LiteLLM (resumir)
    → PostgreSQL (atualizar resumo)
    → Portal API (criar notificação)
    → Email (alertar usuário)
```

### 2. Processamento Assíncrono de Parecer

```
Webhook (form-submitted)
  → PostgreSQL (buscar form_data)
  → LiteLLM (gerar parecer completo - 2-3 min)
  → Google Drive (salvar PDF)
  → Portal API (update submission com link)
  → Email (notificar usuário)
```

### 3. Relatório Diário de Uso

```
Cron (diário 18h)
  → PostgreSQL (query analytics)
  → LiteLLM (gerar insights)
  → Google Sheets (salvar)
  → Email (enviar para admin)
```

---

## 🔐 Segurança

### Checklist

- [ ] Webhook secret configurado (Portal e N8N)
- [ ] N8N API key forte (32+ chars)
- [ ] PostgreSQL user `sunyata_app` tem apenas permissões necessárias
- [ ] Rate limiting nos endpoints `/api/n8n/*` (opcional)
- [ ] Logs de todas as chamadas N8N
- [ ] Timeout configurado (10s) para não bloquear

### Logs de Auditoria

**Tabela recomendada:**
```sql
CREATE TABLE api_audit_log (
    id SERIAL PRIMARY KEY,
    source VARCHAR(50), -- 'n8n', 'portal', 'external'
    endpoint VARCHAR(255),
    method VARCHAR(10),
    status_code INT,
    duration_ms INT,
    request_payload JSONB,
    response_payload JSONB,
    ip_address INET,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
```

---

## 📊 Monitoramento

### Métricas Importantes

1. **Webhooks:**
   - Taxa de sucesso (200 vs erros)
   - Latência (tempo de resposta)
   - Volume (requests/hora)

2. **API calls:**
   - Endpoints mais usados
   - Erros 401/403 (autenticação)
   - Timeouts

3. **PostgreSQL:**
   - Queries lentas (>1s)
   - Conexões abertas
   - Deadlocks

### Dashboards N8N

N8N UI → Executions:
- Ver histórico de workflows
- Filtrar por status (success/error)
- Debug payloads

---

## 🧪 Testar Integração

### 1. Testar Webhook (Portal → N8N)

```bash
# Simular webhook do portal
curl -X POST http://192.168.100.14:5678/webhook/form-submitted \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: sunyata-n8n-webhook-2026-secure-token" \
  -d '{
    "submission_id": 123,
    "user_id": 1,
    "template": "iatr-parecer",
    "data": {"campo": "teste"}
  }'
```

### 2. Testar API (N8N → Portal)

```bash
# Simular chamada N8N ao portal
curl -X POST http://192.168.100.10/api/n8n/update-document \
  -H "Content-Type: application/json" \
  -H "X-N8N-API-Key: a3f8b92c1e4d6f0a..." \
  -d '{
    "document_id": 456,
    "status": "processed"
  }'
```

---

## 🚀 Próximos Passos

1. **Implementar WebhookService.php** no portal
2. **Criar endpoints `/api/n8n/*`**
3. **Configurar credenciais PostgreSQL no N8N**
4. **Criar primeiro workflow** (ex: Monitor PNCP)
5. **Testar integração end-to-end**

---

**Versão:** 1.0
**Data:** 2026-02-13
