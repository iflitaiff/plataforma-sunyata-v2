# Instruções — Centralização de Eventos (System Events + Trace ID)
# De: Claude Chat (Arquiteto) → Claude Code (Executor)
# Data: 25/02/2026
# Prioridade: Após conclusão do plano de enriquecimento/extração/3 modos

---

## Motivação

O sistema agora tem 4 componentes que participam de uma mesma transação:

```
Portal (PHP) → N8N (webhook) → FastAPI (extração) → LiteLLM (análise IA)
```

Quando algo falha, o diagnóstico exige cruzar logs em 4 lugares diferentes: PHP logs, N8N executions, uvicorn stdout e schema `litellm` no PostgreSQL. Com 1 usuário isso é tolerável; com mais, inviável.

A solução é uma **tabela única de eventos** no PostgreSQL que já é ponto de convergência de todos os componentes, com um **trace_id** (UUID) gerado na origem que acompanha a requisição por toda a cadeia. Sem infraestrutura nova — só INSERT nos pontos críticos.

O portal já tem uma página "System Logs" no admin. Esta implementação a substitui/evolui com dados estruturados e correlacionáveis.

---

## Decisões Arquiteturais

1. **PostgreSQL, não stack de observabilidade.** Grafana/Loki/ELK consomem mais recursos que a aplicação e adicionam complexidade operacional desproporcional para 4 componentes. A tabela no banco resolve 90% do problema com custo zero.

2. **trace_id como campo de correlação.** UUID gerado pelo portal no momento da ação do usuário, passado como header `X-Trace-Id` em todos os requests da cadeia. Permite reconstruir a jornada completa de qualquer transação.

3. **JSONB para payload flexível.** Cada componente registra dados diferentes — tokens, páginas extraídas, HTTP status, mensagens de erro. JSONB evita migrations constantes conforme novos eventos são adicionados.

4. **Instrumentação incremental.** Não instrumentar tudo de uma vez. Começar com o fluxo de análise IATR (o mais complexo), adicionar outros fluxos conforme necessidade.

5. **Retention policy simples.** DELETE com cron, não particionamento. Volume estimado: ~475K rows/ano (~500MB em 5 anos). Irrelevante para o hardware disponível.

---

## 1. Migration — Tabela `system_events`

### 1.1 SQL

```sql
-- Migration 017: System Events — centralized event log with trace correlation
BEGIN;

CREATE TABLE IF NOT EXISTS system_events (
    id BIGSERIAL PRIMARY KEY,
    trace_id UUID,                          -- correlação entre componentes (mesmo UUID na cadeia inteira)
    source VARCHAR(30) NOT NULL,            -- 'portal', 'n8n', 'fastapi', 'litellm', 'cron'
    event_type VARCHAR(80) NOT NULL,        -- 'iatr.analysis.requested', 'iatr.pdf.extracted', etc.
    severity VARCHAR(10) NOT NULL DEFAULT 'info',  -- 'debug', 'info', 'warning', 'error'
    entity_type VARCHAR(30),                -- 'edital', 'workflow', 'user', 'monitor'
    entity_id VARCHAR(100),                 -- id do edital, id do workflow, email do user, etc.
    summary TEXT,                           -- descrição curta e legível do evento
    payload JSONB,                          -- dados flexíveis (tokens, custos, erros, duração, etc.)
    duration_ms INTEGER,                    -- duração da operação (se aplicável)
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Índice principal: buscar todos os eventos de uma transação
CREATE INDEX idx_events_trace_id ON system_events(trace_id) WHERE trace_id IS NOT NULL;

-- Filtrar por componente + tempo (página de logs)
CREATE INDEX idx_events_source_time ON system_events(source, created_at DESC);

-- Buscar eventos de uma entidade específica ("tudo que aconteceu com edital 148")
CREATE INDEX idx_events_entity ON system_events(entity_type, entity_id, created_at DESC);

-- Filtrar só erros/warnings (alertas)
CREATE INDEX idx_events_severity ON system_events(severity, created_at DESC)
    WHERE severity IN ('warning', 'error');

-- Limpeza automática: eventos > 90 dias (ajustar conforme necessidade)
-- Rodar via cron diário ou pg_cron
-- DELETE FROM system_events WHERE created_at < NOW() - INTERVAL '90 days';

COMMIT;
```

### 1.2 Arquivo

Criar: `migrations/017_system_events.sql`

### 1.3 Executar

```bash
tools/ssh-cmd.sh vm100 "sudo -u postgres psql -d sunyata_platform -f /dev/stdin" < migrations/017_system_events.sql
```

### 1.4 Permissões

O user `n8n_worker` precisa de INSERT nesta tabela:

```sql
GRANT INSERT, SELECT ON system_events TO n8n_worker;
GRANT USAGE, SELECT ON SEQUENCE system_events_id_seq TO n8n_worker;
```

O user `sunyata_app` (portal) já tem acesso full ao schema public.

---

## 2. Helper PHP — Portal

### 2.1 Função de logging

Criar `app/includes/system_events.php` (ou adicionar ao helper existente):

```php
<?php
/**
 * Registra evento no system_events.
 *
 * @param string      $eventType   Ex: 'iatr.analysis.requested'
 * @param string      $source      Ex: 'portal' (default)
 * @param string      $severity    'debug'|'info'|'warning'|'error'
 * @param string|null $entityType  Ex: 'edital'
 * @param string|null $entityId    Ex: '148'
 * @param string|null $summary     Descrição curta
 * @param array|null  $payload     Dados extras (será JSON)
 * @param string|null $traceId     UUID de correlação
 * @param int|null    $durationMs  Duração em ms
 */
function log_event(
    string  $eventType,
    string  $source = 'portal',
    string  $severity = 'info',
    ?string $entityType = null,
    ?string $entityId = null,
    ?string $summary = null,
    ?array  $payload = null,
    ?string $traceId = null,
    ?int    $durationMs = null
): void {
    try {
        $db = get_db_connection(); // usar a conexão existente do portal
        $stmt = $db->prepare("
            INSERT INTO system_events
                (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload, duration_ms)
            VALUES
                (:trace_id, :source, :event_type, :severity, :entity_type, :entity_id, :summary, :payload, :duration_ms)
        ");
        $stmt->execute([
            ':trace_id'    => $traceId,
            ':source'      => $source,
            ':event_type'  => $eventType,
            ':severity'    => $severity,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':summary'     => $summary,
            ':payload'     => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ':duration_ms' => $durationMs,
        ]);
    } catch (\Throwable $e) {
        // Logging nunca deve derrubar a aplicação
        error_log("system_events write failed: " . $e->getMessage());
    }
}

/**
 * Gera trace_id para uma nova transação.
 */
function generate_trace_id(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
```

### 2.2 Adaptação do helper ao padrão existente

A implementação acima é referência. Adaptar ao padrão de conexão DB e includes que o portal já usa. Pode ser uma classe estática se o portal usa OOP, ou função global se usa procedural. O importante é que:

- Nunca lance exceção (try/catch total — logging não derruba a app)
- Use a conexão DB existente (não abra uma nova)
- `generate_trace_id()` retorne UUID v4

---

## 3. Instrumentação do Fluxo IATR — Pontos de Emissão

Este é o fluxo principal a instrumentar. Cada ponto abaixo é um `INSERT INTO system_events`.

### 3.1 Portal — Submissão de análise

**Quando:** Usuário clica "Analisar" na página do edital.
**Onde:** No handler PHP que faz o POST para o webhook.

```php
$traceId = generate_trace_id();

log_event(
    eventType:  'iatr.analysis.requested',
    source:     'portal',
    severity:   'info',
    entityType: 'edital',
    entityId:   (string) $editalId,
    summary:    "Análise solicitada: {$tipoAnalise}",
    payload:    [
        'tipo_analise' => $tipoAnalise,
        'nivel_profundidade' => $nivelProfundidade ?? 'completa',
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_email' => $_SESSION['user_email'] ?? null,
        'tem_instrucoes' => !empty($instrucoesComplementares),
    ],
    traceId:    $traceId
);

// Passar trace_id no POST para o webhook
$webhookPayload = [
    'edital_id' => $editalId,
    'tipo_analise' => $tipoAnalise,
    'nivel_profundidade' => $nivelProfundidade,
    'instrucoes_complementares' => $instrucoesComplementares,
    'trace_id' => $traceId,  // NOVO
];
```

### 3.2 Portal — Resultado recebido (polling detecta conclusão)

**Quando:** O polling do portal detecta `status_analise` mudou para `concluida` ou `erro`.

```php
log_event(
    eventType:  'iatr.analysis.displayed',
    source:     'portal',
    severity:   'info',
    entityType: 'edital',
    entityId:   (string) $editalId,
    summary:    "Resultado exibido: {$status}",
    payload:    ['status' => $status, 'modelo' => $modelo, 'custo_usd' => $custoUsd],
    traceId:    $traceId  // recuperar do banco ou da sessão
);
```

### 3.3 N8N — Webhook recebido

**Onde:** Code node no início do workflow (após Validate Input), ou como nó PostgreSQL dedicado.
**Como:** Nó PostgreSQL com INSERT direto:

```sql
INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload)
VALUES (
    '{{ $json.trace_id || null }}',
    'n8n',
    'iatr.webhook.received',
    'info',
    'edital',
    '{{ $json.edital_id }}',
    'Webhook recebido: {{ $json.tipo_analise }}',
    '{{ JSON.stringify({ tipo_analise: $json.tipo_analise, modo_analise: $json.modo_analise || "pending" }) }}'
);
```

**NOTA:** Os nós de logging no N8N devem usar `neverError: true` ou estar em branches que não bloqueiam o fluxo principal. Falha de logging nunca deve impedir a análise.

### 3.4 N8N — Modo de análise determinado

**Onde:** Após o nó "Determine Analysis Mode".

```sql
INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload)
VALUES (
    '{{ $json.trace_id || null }}',
    'n8n',
    'iatr.mode.determined',
    'info',
    'edital',
    '{{ $json.edital_id }}',
    'Modo: {{ $json.modo_analise }}',
    '{{ JSON.stringify({ modo: $json.modo_analise, tem_texto: $json.tem_texto, tem_itens: $json.tem_itens, texto_chars: $json.texto_total_caracteres || 0 }) }}'
);
```

### 3.5 FastAPI — Extração de PDF concluída

**Onde:** No endpoint `extract-pdf`, ao final do processamento.

```python
# No final do endpoint extract-pdf, antes do return
trace_id = req.trace_id if hasattr(req, 'trace_id') else None  # adicionar ao PncpExtractRequest

await pool.execute("""
    INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload, duration_ms)
    VALUES ($1, 'fastapi', $2, $3, 'edital', $4, $5, $6, $7)
""",
    trace_id,
    'iatr.pdf.extracted' if result.success else 'iatr.pdf.extraction_failed',
    'info' if result.success else 'warning',
    str(edital_id),
    f"Extraído: {result.total_paginas} páginas, {result.total_caracteres} chars" if result.success else f"Falha: {result.error}",
    json.dumps({
        'pages': result.total_paginas,
        'chars': result.total_caracteres,
        'files': len(result.arquivos),
        'format_detected': format_type,  # 'pdf', 'zip', 'docx'
    }),
    duration_ms,
)
```

**NOTA:** Adicionar `trace_id: Optional[str] = None` ao `PncpExtractRequest` model. O N8N passa via body.

### 3.6 N8N — Chamada LLM concluída

**Onde:** Após o nó "Process LLM Response".

```sql
INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload, duration_ms)
VALUES (
    '{{ $json.trace_id || null }}',
    'n8n',
    '{{ $json.status === "concluida" ? "iatr.llm.completed" : "iatr.llm.failed" }}',
    '{{ $json.status === "concluida" ? "info" : "error" }}',
    'edital',
    '{{ $json.edital_id }}',
    'LLM {{ $json.model }}: {{ $json.tokens_input }}+{{ $json.tokens_output }} tokens, ${{ $json.custo_usd }}',
    '{{ JSON.stringify({ model: $json.model, tokens_in: $json.tokens_input, tokens_out: $json.tokens_output, custo_usd: $json.custo_usd, modo: $json.modo_analise, tipo: $json.tipo_analise }) }}'
);
```

### 3.7 N8N — Análise salva (fim da transação)

**Onde:** Após o nó "Save Analysis Result".

```sql
INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload)
VALUES (
    '{{ $json.trace_id || null }}',
    'n8n',
    'iatr.analysis.completed',
    'info',
    'edital',
    '{{ $json.edital_id }}',
    'Análise concluída: {{ $json.tipo_analise }} ({{ $json.modo_analise || "completo" }})',
    '{{ JSON.stringify({ tipo: $json.tipo_analise, modo: $json.modo_analise, model: $json.model, custo_usd: $json.custo_usd, com_texto: $json.has_full_text }) }}'
);
```

### 3.8 Resumo dos eventos do fluxo IATR

| # | Evento | Source | Severity normal |
|---|--------|--------|-----------------|
| 1 | `iatr.analysis.requested` | portal | info |
| 2 | `iatr.webhook.received` | n8n | info |
| 3 | `iatr.mode.determined` | n8n | info |
| 4 | `iatr.pdf.extracted` / `iatr.pdf.extraction_failed` | fastapi | info / warning |
| 5 | `iatr.llm.completed` / `iatr.llm.failed` | n8n | info / error |
| 6 | `iatr.analysis.completed` | n8n | info |
| 7 | `iatr.analysis.displayed` | portal | info |

Total: 5-7 eventos por transação de análise. ~220 análises/dia = ~1500 rows/dia máximo.

---

## 4. Propagação do trace_id

### 4.1 Portal → N8N

O `trace_id` é gerado no portal e enviado no body do POST para o webhook:

```json
{
    "edital_id": 148,
    "tipo_analise": "resumo_executivo",
    "trace_id": "a1b2c3d4-e5f6-4a7b-8c9d-e0f1a2b3c4d5"
}
```

### 4.2 N8N → FastAPI

O workflow passa o `trace_id` no body do POST para `extract-pdf` e `enrich-edital`:

```javascript
// No jsonBody do HTTP Request node
"={{ JSON.stringify({ edital_id: $json.edital_id, save_to_db: true, trace_id: $json.trace_id || null }) }}"
```

### 4.3 Eventos sem trace_id

Eventos originados por cron jobs (monitor PNCP, limpeza) ou ações internas não terão trace_id — e está ok. O campo é nullable. Eles ainda são buscáveis por `source`, `entity_id` e `created_at`.

### 4.4 Alterações nos models FastAPI

Adicionar `trace_id: Optional[str] = None` aos request models relevantes:
- `PncpExtractRequest`
- `PncpEnrichRequest`

### 4.5 Alteração no Validate Input do N8N

Adicionar ao parsing do body:

```javascript
const traceId = body.trace_id || null;
// ... incluir no return
return [{ json: { ..., trace_id: traceId } }];
```

E garantir que o `trace_id` é propagado por todos os nós subsequentes (via `$('Validate Input').first().json.trace_id`).

---

## 5. Implementação dos Nós de Logging no N8N

### 5.1 Abordagem recomendada

Usar **nós PostgreSQL dedicados** (não Code nodes) para os INSERTs de eventos. Razão: mais simples de debugar, usa a credencial `sunyata-pg-001` existente, e o `neverError` pode ser configurado no node.

### 5.2 Padrão de um nó de logging

```json
{
    "parameters": {
        "operation": "executeQuery",
        "query": "INSERT INTO system_events (...) VALUES (...);",
        "options": {}
    },
    "name": "Log: [nome do evento]",
    "type": "n8n-nodes-base.postgres",
    "typeVersion": 2.5,
    "credentials": {
        "postgres": { "id": "sunyata-pg-001", "name": "Sunyata PostgreSQL" }
    },
    "onError": "continueRegularOutput"
}
```

### 5.3 Posicionamento no workflow

Os nós de logging são inseridos **em série** no fluxo principal, não em branches paralelas. Com `onError: continueRegularOutput`, se o INSERT falhar, o fluxo continua normalmente. Posicionamento:

```
Validate Input → [Log: webhook.received] → Mark In Progress → ...
... → Determine Mode → [Log: mode.determined] → routing ...
... → Process LLM Response → [Log: llm.completed] → Save Analysis Result → [Log: analysis.completed] → Format Response → Respond
```

### 5.4 Impacto em performance

Cada INSERT no PostgreSQL local leva ~1-2ms. Com 4 nós de logging = ~8ms adicionais numa transação que leva 30-90 segundos. Impacto: zero.

---

## 6. Página de System Logs — Evolução

### 6.1 Substituir ou evoluir

Se a página atual de System Logs já existe no admin, evoluir para ler de `system_events` em vez da fonte atual. Se não existe mais (nova versão do portal), criar.

### 6.2 Features da página

**Listagem principal:**
- Tabela com: timestamp, source (com badge colorido), event_type, severity (com ícone), entity, summary
- Ordenação por `created_at DESC` (mais recente primeiro)
- Paginação (50 por página)

**Filtros:**
- Por source: [Todos] [Portal] [N8N] [FastAPI] [LiteLLM] [Cron]
- Por severity: [Todos] [Info] [Warning] [Error]
- Por entity: dropdown ou busca livre (ex: "edital:148")
- Por período: date range picker
- Por trace_id: campo de busca (para rastrear transação específica)

**Visualização de trace (o diferencial):**
- Ao clicar em qualquer evento que tenha `trace_id`, abrir painel lateral ou modal mostrando TODOS os eventos daquele trace, em ordem cronológica
- Formato timeline vertical, com cada componente em cor diferente:
  - Portal = azul
  - N8N = verde
  - FastAPI = laranja
  - LiteLLM = roxo
- Mostrar duração entre eventos (quanto tempo cada etapa levou)
- Expandir payload JSONB inline

**Dashboard resumo (topo da página):**
- Últimas 24h: total de eventos, erros, warnings
- Custo total LLM (soma de `payload->>'custo_usd'` dos eventos `iatr.llm.completed`)
- Editais analisados hoje
- Taxa de sucesso (completa / total)

### 6.3 Query base

```sql
-- Listagem com filtros
SELECT id, trace_id, source, event_type, severity, entity_type, entity_id,
       summary, payload, duration_ms, created_at
FROM system_events
WHERE ($1::varchar IS NULL OR source = $1)
  AND ($2::varchar IS NULL OR severity = $2)
  AND ($3::varchar IS NULL OR (entity_type = $3 AND entity_id = $4))
  AND created_at >= COALESCE($5, NOW() - INTERVAL '7 days')
  AND created_at <= COALESCE($6, NOW())
ORDER BY created_at DESC
LIMIT 50 OFFSET $7;

-- Timeline de um trace
SELECT * FROM system_events
WHERE trace_id = $1
ORDER BY created_at ASC;

-- Dashboard resumo (24h)
SELECT
    count(*) as total,
    count(*) FILTER (WHERE severity = 'error') as errors,
    count(*) FILTER (WHERE severity = 'warning') as warnings,
    count(*) FILTER (WHERE event_type = 'iatr.analysis.completed') as analises,
    sum((payload->>'custo_usd')::numeric) FILTER (WHERE event_type LIKE 'iatr.llm.%') as custo_total
FROM system_events
WHERE created_at >= NOW() - INTERVAL '24 hours';
```

---

## 7. Eventos Futuros (Não Implementar Agora)

Referência para expansão quando necessário:

| Evento | Source | Trigger |
|--------|--------|---------|
| `monitor.run.started` | n8n | Cron do PNCP Monitor disparou |
| `monitor.run.completed` | n8n | Monitor terminou (N novos, M atualizados) |
| `monitor.enrichment.completed` | fastapi | Backfill/enriquecimento de edital |
| `user.login` | portal | Login de usuário |
| `user.action` | portal | Ações relevantes (busca, filtro, export) |
| `email.sent` | n8n | Email de notificação enviado |
| `email.failed` | n8n | Falha no envio de email |
| `system.health.check` | cron | Health check periódico dos componentes |

---

## 8. Retention Policy

### 8.1 Cron job (pg_cron ou crontab)

```sql
-- Executar diariamente às 03:00
DELETE FROM system_events WHERE created_at < NOW() - INTERVAL '90 days';
```

### 8.2 Alternativa: via crontab da VM100

```bash
# /etc/cron.d/system-events-cleanup
0 3 * * * postgres psql -d sunyata_platform -c "DELETE FROM system_events WHERE created_at < NOW() - INTERVAL '90 days';"
```

### 8.3 Volume estimado

- ~1500 rows/dia (com fluxo IATR instrumentado)
- ~135K rows em 90 dias
- ~50MB estimados com JSONB
- Limpeza diária mantém tabela enxuta

---

## 9. Critérios de Aceite

1. ✅ Tabela `system_events` criada com índices
2. ✅ Helper PHP `log_event()` + `generate_trace_id()` funcionando
3. ✅ Portal gera `trace_id` e envia no POST para webhook de análise
4. ✅ Workflow IATR v3 propaga `trace_id` e emite 4 eventos (webhook.received, mode.determined, llm.completed, analysis.completed)
5. ✅ FastAPI emite evento em `extract-pdf` com `trace_id`
6. ✅ Página System Logs mostra eventos com filtros por source, severity, entity, período
7. ✅ Visualização de trace: clicar em trace_id mostra timeline completa da transação
8. ✅ Logging nunca bloqueia o fluxo principal (try/catch no PHP, onError no N8N)
9. ✅ Retention: cron configurado para limpeza de eventos > 90 dias
10. ✅ Dashboard resumo no topo: eventos 24h, erros, custo LLM, análises

---

## 10. O Que NÃO Fazer

- **Não instalar Grafana/Loki/Prometheus** — desproporcional para 4 componentes
- **Não instrumentar tudo de uma vez** — começar com fluxo IATR, expandir conforme necessidade
- **Não criar tabelas separadas por componente** — uma tabela com `source` resolve
- **Não fazer logging síncrono bloqueante** — sempre fire-and-forget com error handling
- **Não logar payloads enormes** — o texto do edital e o resultado da análise NÃO vão no payload do evento; só metadados (tokens, custo, status, duração)

---

## 11. Ordem de Implementação

1. Migration (`system_events` + permissões)
2. Helper PHP (`log_event`, `generate_trace_id`)
3. Instrumentar portal: gerar trace_id, emitir evento na submissão de análise
4. Alterar Validate Input do N8N: aceitar e propagar `trace_id`
5. Adicionar 4 nós de logging no workflow IATR v3
6. Alterar FastAPI: aceitar `trace_id`, emitir evento em `extract-pdf`
7. Página System Logs: listagem com filtros + visualização de trace
8. Dashboard resumo
9. Retention cron
