# N8N Logging Nodes SQL (System Events)

SQL pronto para os 4 nós PostgreSQL de logging no workflow IATR v3.

Configuração comum de cada nó:
- Node type: `n8n-nodes-base.postgres`
- `typeVersion`: `2.5`
- Credential: `sunyata-pg-001`
- Operation: `executeQuery`
- `onError`: `continueRegularOutput`

## Pré-requisito importante

Para correlação fim-a-fim, o node `Validate Input` deve propagar `trace_id` no output (`json.trace_id`).

Exemplo no `Validate Input`:
```javascript
const traceId = body.trace_id || null;

return [{ json: {
  edital_id: editalId,
  tipo_analise: tipoAnalise,
  contexto_empresa: contextoEmpresa,
  nivel_profundidade: nivelProfundidade,
  instrucoes_complementares: instrucoesComplementares,
  trace_id: traceId
} }];
```

As queries abaixo já estão preparadas para aceitar `trace_id` nulo.

---

## 1) Log: webhook.received

Posição: após `Validate Input`.

```sql
=INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload)
VALUES (
  {{ $json.trace_id ? "'" + $json.trace_id + "'" : 'NULL' }}::uuid,
  'n8n',
  'iatr.webhook.received',
  'info',
  'edital',
  '{{ $json.edital_id }}',
  'Webhook recebido: {{ $json.tipo_analise }} ({{ $json.nivel_profundidade }})',
  '{{ JSON.stringify({ tipo_analise: $json.tipo_analise, nivel_profundidade: $json.nivel_profundidade, tem_instrucoes: !!$json.instrucoes_complementares }) }}'::jsonb
);
```

Campos validados no workflow atual: `edital_id`, `tipo_analise`, `nivel_profundidade`, `instrucoes_complementares`.

---

## 2) Log: mode.determined

Posição: após `Determine Analysis Mode`.

```sql
=INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload)
VALUES (
  {{ $('Validate Input').first().json.trace_id ? "'" + $('Validate Input').first().json.trace_id + "'" : 'NULL' }}::uuid,
  'n8n',
  'iatr.mode.determined',
  'info',
  'edital',
  '{{ $json.edital_id }}',
  'Modo: {{ $json.modo_analise }} | Texto: {{ $json.tem_texto }} | Itens: {{ $json.tem_itens }} | Arquivos: {{ $json.tem_arquivos }}',
  '{{ JSON.stringify({ modo_analise: $json.modo_analise, tem_texto: $json.tem_texto, tem_itens: $json.tem_itens, tem_arquivos: $json.tem_arquivos }) }}'::jsonb
);
```

Campos validados no workflow atual: `modo_analise`, `tem_texto`, `tem_itens`, `tem_arquivos`, `edital_id`.

---

## 3) Log: llm.completed

Posição: após `Process LLM Response`.

```sql
=INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload)
VALUES (
  {{ $('Validate Input').first().json.trace_id ? "'" + $('Validate Input').first().json.trace_id + "'" : 'NULL' }}::uuid,
  'n8n',
  '{{ $json.status === "erro" ? "iatr.llm.failed" : "iatr.llm.completed" }}',
  '{{ $json.status === "erro" ? "error" : "info" }}',
  'edital',
  '{{ $json.edital_id }}',
  '{{ $json.model }}: {{ $json.tokens_input }}in/{{ $json.tokens_output }}out - ${{ $json.custo_usd }}',
  '{{ JSON.stringify({ model: $json.model, tokens_input: $json.tokens_input, tokens_output: $json.tokens_output, custo_usd: $json.custo_usd, modo_analise: $json.modo_analise, status: $json.status }) }}'::jsonb
);
```

Campos validados no workflow atual: `status`, `model`, `tokens_input`, `tokens_output`, `custo_usd`, `modo_analise`, `edital_id`.

---

## 4) Log: analysis.completed

Posição: após `Save Analysis Result`.

```sql
=INSERT INTO system_events (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload, duration_ms)
VALUES (
  {{ $('Validate Input').first().json.trace_id ? "'" + $('Validate Input').first().json.trace_id + "'" : 'NULL' }}::uuid,
  'n8n',
  'iatr.analysis.completed',
  '{{ $json.status === "erro" ? "error" : "info" }}',
  'edital',
  '{{ $json.edital_id }}',
  'Análise {{ $json.status }}: {{ $json.tipo_analise }} ({{ $json.modo_analise }})',
  '{{ JSON.stringify({ tipo_analise: $json.tipo_analise, status: $json.status, modo_analise: $json.modo_analise, nivel_profundidade: $json.nivel_profundidade, custo_usd: $json.custo_usd, com_texto: $json.has_full_text }) }}'::jsonb,
  NULL
);
```

`duration_ms`: mantido como `NULL` nesta fase (conforme decisão arquitetural atual).

---

## Observação de wiring

Como `Build Analysis Prompt`/`Build Partial Prompt` atualmente não repassam `trace_id`, os INSERTs 2/3/4 referenciam `trace_id` a partir de `$('Validate Input').first().json.trace_id`, garantindo correlação quando o campo for adicionado no input.
