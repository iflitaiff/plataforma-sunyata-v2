# N8N Integration - Roadmap de Implementação

Plano pragmático para integrar N8N com LiteLLM e Portal Sunyata.

---

## 🎯 Visão Geral

```
Prioridade 1: N8N → LiteLLM (processamento IA)
Prioridade 2: N8N → PostgreSQL (dados diretos)
Prioridade 3: Portal → N8N (webhooks)
Prioridade 4: N8N → Portal (API REST)
```

---

## 📋 Fase 1: N8N → LiteLLM (1-2 horas)

### Objetivo
Habilitar N8N a usar LiteLLM para tarefas de IA.

### Tarefas

1. **Configurar credenciais no N8N** (5 min)
   - Credentials → HTTP Header Auth
   - Nome: `LiteLLM API Key`
   - Header: `Authorization: Bearer sk-sunyata-ec909...`

2. **Criar workflow de teste** (10 min)
   - Manual Trigger
   - HTTP Request → LiteLLM
   - Prompt: "Resuma este texto em 3 pontos: {input}"
   - Log resultado

3. **Validar modelos disponíveis** (5 min)
   - GET `http://192.168.100.13:4000/v1/models`
   - Anotar IDs dos modelos (claude-sonnet-4-5, gpt-4o-mini, etc.)

### Entregável
✅ N8N consegue chamar LiteLLM e obter respostas

### Documentação
`docs/N8N-LITELLM-INTEGRATION.md`

---

## 📋 Fase 2: N8N → PostgreSQL (30 min)

### Objetivo
Conectar N8N ao banco de dados do portal para ler/escrever dados.

### Tarefas

1. **Configurar credenciais PostgreSQL** (5 min)
   - Credentials → Postgres
   - Host: `192.168.100.10`
   - Database: `sunyata_platform`
   - User: `sunyata_app`
   - Password: `sunyata_app_2026`

2. **Testar leitura** (10 min)
   - Query: `SELECT * FROM users LIMIT 5`
   - Verificar dados retornados

3. **Criar tabela de logs N8N** (15 min)
   - Migration: `migrations/008-n8n-logs.sql`
   - Tabelas: `n8n_processing_logs`, `n8n_scheduled_jobs`
   - Deploy na VM100

### Entregável
✅ N8N pode ler/escrever no PostgreSQL do portal

---

## 📋 Fase 3: Workflow Prático - Monitor PNCP (2-3 horas)

### Objetivo
Criar primeiro workflow real: monitorar editais PNCP e alertar usuários.

### Arquitetura

```
Cron (diário 8h)
  → HTTP Request (PNCP API)
  → IF (novos editais?)
    → PostgreSQL (salvar edital)
    → LiteLLM (resumir edital)
    → PostgreSQL (atualizar resumo)
    → Email (alertar usuário)
```

### Tarefas

1. **Workflow básico** (1h)
   - Cron trigger: diário às 8h
   - HTTP Request → PNCP API (`/pncp/v1/consultas/editais`)
   - Filter: apenas últimas 24h
   - PostgreSQL: INSERT em `pncp_editais` table

2. **Adicionar processamento IA** (30 min)
   - LiteLLM: resumir edital (3-5 bullet points)
   - PostgreSQL: UPDATE `pncp_editais` com resumo

3. **Notificação** (30 min)
   - Email node (Gmail/SMTP)
   - Template: "Novo edital encontrado: {titulo}"
   - Para: usuários com `notify_pncp = true`

4. **Error handling** (30 min)
   - Try/Catch nodes
   - Log erros em `n8n_processing_logs`
   - Retry policy (3x com backoff)

### Entregável
✅ Workflow funcional de monitor PNCP end-to-end

### Valor Gerado
- Automação de monitoramento (economiza 1-2h/dia manual)
- Resumos IA (economiza 15-30 min/edital)
- Alertas em tempo real

---

## 📋 Fase 4: Portal → N8N Webhooks (opcional, 2-3 horas)

### Objetivo
Portal notifica N8N quando eventos importantes acontecem.

### Tarefas

1. **Criar WebhookService.php** (1h)
   - `app/src/Services/WebhookService.php`
   - Métodos: `onFormSubmit()`, `onDocumentCreated()`, `onUserRegistered()`
   - Secret: `N8N_WEBHOOK_SECRET` no `.env`

2. **Integrar em endpoints** (1h)
   - `api/canvas/submit.php` → trigger webhook após save
   - `api/documents/create.php` → trigger webhook
   - Error handling: log mas não falha o request

3. **Criar webhook receivers no N8N** (30 min)
   - Workflow: "Portal - Form Submitted"
   - Webhook node → autenticação via secret
   - Processar payload

### Entregável
✅ Portal notifica N8N de eventos importantes

### Quando Implementar
- **Não urgente:** Só se houver necessidade real de processamento assíncrono
- **Alternativa:** N8N pode fazer polling no PostgreSQL (mais simples)

---

## 📋 Fase 5: N8N → Portal API (opcional, 2-3 horas)

### Objetivo
N8N atualiza dados no portal via API REST.

### Tarefas

1. **Criar endpoints `/api/n8n/*`** (1.5h)
   - `update-document.php` - atualizar status/metadata
   - `create-notification.php` - notificar usuário
   - `update-submission.php` - adicionar campos
   - Autenticação: `X-N8N-API-Key` header

2. **Documentar API** (30 min)
   - OpenAPI spec (opcional)
   - Exemplos de uso

3. **Configurar credenciais no N8N** (30 min)
   - HTTP Header Auth: `X-N8N-API-Key`
   - Testar endpoints

### Entregável
✅ N8N pode atualizar dados no portal via API

### Quando Implementar
- **Se:** N8N precisa criar/atualizar dados que não estão no PostgreSQL direto
- **Alternativa:** Usar PostgreSQL direto (mais simples, menos camadas)

---

## 🎯 Recomendação de Prioridade

### Implementar Agora (Alta Prioridade)

1. ✅ **Fase 1:** N8N → LiteLLM (quick win, habilita IA)
2. ✅ **Fase 2:** N8N → PostgreSQL (essencial para workflows)
3. ✅ **Fase 3:** Workflow PNCP (valor imediato, caso de uso real)

**Tempo total:** ~4-6 horas
**ROI:** Alto (automação + IA em workflows reais)

### Implementar Depois (Baixa Prioridade)

4. ⏳ **Fase 4:** Portal → N8N webhooks (se necessário)
5. ⏳ **Fase 5:** N8N → Portal API (se necessário)

**Por quê adiar?**
- **Complexidade adicional** (mais código, mais manutenção)
- **Polling alternativo:** N8N pode ler PostgreSQL periodicamente (mais simples)
- **Menor urgência:** Não há caso de uso imediato que justifique

---

## 🔐 Checklist de Segurança

Antes de colocar em produção:

- [ ] Secrets configurados (`.env`)
  - [ ] `N8N_WEBHOOK_SECRET`
  - [ ] `N8N_API_KEY`
  - [ ] LiteLLM master key
- [ ] PostgreSQL user `sunyata_app` com permissões limitadas
- [ ] Rate limiting em endpoints críticos (opcional)
- [ ] Logs de auditoria habilitados
- [ ] Backups de workflows N8N (export JSON periodicamente)

---

## 📊 Métricas de Sucesso

### Após 1 mês de uso:

1. **Workflows ativos:** 3-5 workflows rodando
2. **Execuções:** 50-100 execuções/dia
3. **Taxa de sucesso:** >95%
4. **Tempo economizado:** 5-10 horas/semana (automação)
5. **Custo LiteLLM:** <$50/mês (monitorar)

---

## 🚀 Próximos Passos Imediatos

### Para começar hoje:

```bash
# 1. Acessar N8N (via tunnel)
# Browser: http://localhost:5678
# Login: sunyata-admin / N8n-Sunyata-2026!

# 2. Configurar credencial LiteLLM
# Credentials → Add → HTTP Header Auth
# Authorization: Bearer sk-sunyata-ec909e5420572a4c8c496822a9459bcdec1391c7

# 3. Criar workflow teste
# New Workflow → Manual Trigger → HTTP Request (LiteLLM) → Execute

# 4. Validar resposta
# Verificar que retorna JSON com choices[0].message.content
```

---

## 📚 Documentação Completa

- **N8N → LiteLLM:** `docs/N8N-LITELLM-INTEGRATION.md`
- **N8N ↔ Portal:** `docs/N8N-PORTAL-INTEGRATION.md`
- **Este roadmap:** `docs/N8N-INTEGRATION-ROADMAP.md`

---

**Versão:** 1.0
**Data:** 2026-02-13
**Mantido por:** Claude (Executor Principal)
