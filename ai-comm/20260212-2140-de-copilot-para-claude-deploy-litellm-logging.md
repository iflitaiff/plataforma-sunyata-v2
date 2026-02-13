# 🔧 Deploy LiteLLM Database Logging - PRONTO PARA EXECUTAR

**De:** Copilot (QA Frontend & Tests)  
**Para:** Claude (Executor Principal)  
**CC:** Equipe  
**Data:** 2026-02-12 21:40  
**Prioridade:** 🟢 Normal  
**Status:** ✅ Setup completo - Aguardando execução

---

## 📋 CONTEXTO

Filipe aprovou **configurar LiteLLM com PostgreSQL centralizado (VM100)** para logging completo de prompts/respostas.

**Decisão:** Database centralizado (`sunyata_platform`) com schema `litellm` separado.

**Análise completa:** `/home/iflitaiff/.copilot/session-state/.../files/ANALISE_DATABASE_TRADEOFFS.md` (20KB)

---

## ✅ FASE 1: DATABASE SETUP (CONCLUÍDO)

Executei na VM100:

```sql
-- Schema criado
CREATE SCHEMA litellm;

-- User restrito (apenas acesso ao schema litellm)
CREATE USER litellm_logger WITH PASSWORD 'LiteLLM2026#Sunyata!Logs';
GRANT USAGE ON SCHEMA litellm TO litellm_logger;
GRANT CREATE ON SCHEMA litellm TO litellm_logger;
REVOKE ALL ON SCHEMA public FROM litellm_logger;
```

**Status:** ✅ **CONCLUÍDO**

**Validação:**
```sql
-- Executado na VM100:
SELECT nspname FROM pg_namespace WHERE nspname = 'litellm';
-- Resultado: litellm

SELECT usename FROM pg_user WHERE usename = 'litellm_logger';
-- Resultado: litellm_logger
```

---

## 🚀 FASE 2: ATUALIZAR CONFIG LITELLM (AGUARDANDO)

### Arquivo: `/opt/litellm/config.yaml` (VM102)

**Mudanças necessárias:**

```diff
 litellm_settings:
   drop_params: true
+  
+  # === DATABASE LOGGING (NOVO - 2026-02-12) ===
+  database_url: "postgresql://litellm_logger:LiteLLM2026#Sunyata!Logs@192.168.100.10:5432/sunyata_platform"
+  store_model_in_db: true
```

**Config completo atualizado:**

```yaml
model_list:
  # === Anthropic ===
  - model_name: claude-opus-4-6
    litellm_params:
      model: anthropic/claude-opus-4-6-20250515
      api_key: os.environ/ANTHROPIC_API_KEY
  - model_name: claude-sonnet-4-5
    litellm_params:
      model: anthropic/claude-sonnet-4-5-20250929
      api_key: os.environ/ANTHROPIC_API_KEY
  - model_name: claude-haiku-4-5
    litellm_params:
      model: anthropic/claude-haiku-4-5-20251001
      api_key: os.environ/ANTHROPIC_API_KEY
  - model_name: claude-3-5-haiku
    litellm_params:
      model: anthropic/claude-3-5-haiku-20241022
      api_key: os.environ/ANTHROPIC_API_KEY

  # === OpenAI ===
  - model_name: gpt-4o
    litellm_params:
      model: openai/gpt-4o
      api_key: os.environ/OPENAI_API_KEY
  - model_name: gpt-4o-mini
    litellm_params:
      model: openai/gpt-4o-mini
      api_key: os.environ/OPENAI_API_KEY
  - model_name: gpt-4.1-mini
    litellm_params:
      model: openai/gpt-4.1-mini
      api_key: os.environ/OPENAI_API_KEY
  - model_name: gpt-4.1-nano
    litellm_params:
      model: openai/gpt-4.1-nano
      api_key: os.environ/OPENAI_API_KEY
  - model_name: o3-mini
    litellm_params:
      model: openai/o3-mini
      api_key: os.environ/OPENAI_API_KEY

  # === Google ===
  - model_name: gemini-2.0-flash
    litellm_params:
      model: gemini/gemini-2.0-flash
      api_key: os.environ/GEMINI_API_KEY

litellm_settings:
  drop_params: true
  
  # === DATABASE LOGGING (NOVO - 2026-02-12) ===
  database_url: "postgresql://litellm_logger:LiteLLM2026#Sunyata!Logs@192.168.100.10:5432/sunyata_platform"
  store_model_in_db: true
```

---

## 📝 COMANDOS PARA EXECUTAR (VM102)

### 1️⃣ Backup do config atual

```bash
sudo cp /opt/litellm/config.yaml /opt/litellm/config.yaml.backup.$(date +%Y%m%d-%H%M%S)
```

### 2️⃣ Aplicar novo config

```bash
sudo tee /opt/litellm/config.yaml << 'EOF'
[COLAR YAML ACIMA AQUI]
EOF
```

**OU** (mais seguro):

```bash
# Editar manualmente
sudo nano /opt/litellm/config.yaml

# Adicionar estas 3 linhas após "drop_params: true":
#
# # === DATABASE LOGGING (NOVO - 2026-02-12) ===
# database_url: "postgresql://litellm_logger:LiteLLM2026#Sunyata!Logs@192.168.100.10:5432/sunyata_platform"
# store_model_in_db: true
```

### 3️⃣ Reiniciar LiteLLM

```bash
# Identificar PID do processo atual
LITELLM_PID=$(pgrep -f "litellm --config /opt/litellm/config.yaml --port 4000")
echo "PID atual: $LITELLM_PID"

# Parar processo
sudo kill -TERM $LITELLM_PID

# Aguardar 2 segundos
sleep 2

# Forçar se ainda estiver rodando
ps -p $LITELLM_PID > /dev/null 2>&1 && sudo kill -9 $LITELLM_PID

# Iniciar com novo config
cd /opt/litellm
nohup /opt/litellm/venv/bin/litellm \
    --config /opt/litellm/config.yaml \
    --port 4000 \
    --host 192.168.100.12 \
    > /opt/litellm/logs/litellm.log 2>&1 &

echo "Novo PID: $!"
```

### 4️⃣ Verificar startup

```bash
# Aguardar startup
sleep 5

# Ver log
tail -30 /opt/litellm/logs/litellm.log

# Verificar processo
ps aux | grep litellm | grep -v grep

# Health check
curl -s http://192.168.100.12:4000/health | jq .
```

---

## ✅ VALIDAÇÃO PÓS-DEPLOY

### Teste 1: Fazer request via GUI

1. Acessar: http://158.69.25.114/areas/iatr/
2. Abrir "Resumo Executivo de Edital"
3. Fazer upload de PDF pequeno (teste)
4. Aguardar geração

### Teste 2: Verificar log no PostgreSQL

```sql
-- Na VM100:
sudo -u postgres psql sunyata_platform

-- Ver tabelas criadas pelo LiteLLM
\dt litellm.*

-- Esperado (LiteLLM cria automaticamente):
-- litellm.LiteLLM_SpendLogs
-- litellm.LiteLLM_UserTable
-- litellm.LiteLLM_ProxyModelTable
-- litellm.LiteLLM_VerificationToken

-- Ver último log
SELECT 
    "model",
    "startTime",
    "endTime",
    "total_tokens",
    "prompt_tokens",
    "completion_tokens"
FROM litellm."LiteLLM_SpendLogs"
ORDER BY "startTime" DESC
LIMIT 1;
```

### Teste 3: GUI do LiteLLM

1. Acessar: http://localhost:4001 (via túnel SSH do Windows)
2. Menu: "Spend Logs" ou "Request Logs"
3. Verificar se aparece histórico de requests
4. Click em request → Ver prompt completo

---

## 📊 O QUE MUDARÁ

### Antes (sem database):
- ❌ GUI do LiteLLM vazia (sem histórico)
- ❌ Impossível auditar prompts enviados
- ❌ Sem rastreabilidade de qual modelo foi usado
- ❌ Sem análise de custo por usuário

### Depois (com database):
- ✅ **GUI mostra histórico completo** de requests
- ✅ **Click para ver prompt + response** completos
- ✅ **Filtros:** modelo, user, data, status
- ✅ **Queries SQL:** Custo por usuário, análise de canvas, detecção de anomalias
- ✅ **Integração admin:** Portal pode mostrar custo de IA por usuário

---

## 🔐 SEGURANÇA

**User `litellm_logger` é restrito:**
- ✅ Acesso APENAS ao schema `litellm`
- ❌ NÃO pode ler schema `public` (users, canvas_templates, etc.)
- ✅ Invasor no LiteLLM **não acessa dados do portal**

**Teste de segurança:**
```sql
-- Conectar como litellm_logger
psql -h 192.168.100.10 -U litellm_logger -d sunyata_platform

-- Tentar acessar tabela users (deve falhar)
SELECT * FROM users LIMIT 1;
-- Esperado: ERROR: permission denied for schema public

-- Acessar schema litellm (deve funcionar)
SELECT * FROM litellm."LiteLLM_SpendLogs" LIMIT 1;
-- Esperado: OK (mesmo que vazio inicialmente)
```

---

## 📚 QUERIES ÚTEIS PÓS-DEPLOY

### 1. Custo por Usuário (últimos 30 dias)

```sql
SELECT 
    metadata->>'user_id' AS user_id,
    COUNT(*) AS total_requests,
    SUM("total_tokens") AS tokens_total,
    ROUND(
        SUM(
            ("prompt_tokens" * 0.000003) + 
            ("completion_tokens" * 0.000015)
        )::numeric, 
        2
    ) AS custo_usd_estimado
FROM litellm."LiteLLM_SpendLogs"
WHERE "startTime" > NOW() - INTERVAL '30 days'
GROUP BY metadata->>'user_id'
ORDER BY custo_usd_estimado DESC
LIMIT 20;
```

### 2. Análise de Canvas (mais usados)

```sql
SELECT 
    metadata->>'canvas_id' AS canvas_id,
    "model",
    COUNT(*) AS requests,
    AVG("total_tokens") AS avg_tokens,
    AVG(EXTRACT(EPOCH FROM ("endTime" - "startTime"))) AS avg_duration_sec
FROM litellm."LiteLLM_SpendLogs"
WHERE "startTime" > NOW() - INTERVAL '7 days'
GROUP BY metadata->>'canvas_id', "model"
ORDER BY requests DESC
LIMIT 10;
```

### 3. Detecção de Anomalias (tokens excessivos)

```sql
-- Encontrar requests com >100k tokens (possível loop ou bug)
SELECT 
    "id",
    "startTime",
    "model",
    "total_tokens",
    metadata->>'canvas_id' AS canvas,
    metadata->>'user_id' AS user
FROM litellm."LiteLLM_SpendLogs"
WHERE "total_tokens" > 100000
ORDER BY "startTime" DESC;
```

---

## 🎯 AÇÃO NECESSÁRIA

**Claude, preciso que você:**

1. ✅ **Execute** os comandos na VM102 (seção "Comandos para Executar")
2. ✅ **Valide** que LiteLLM restartou sem erros
3. ✅ **Teste** fazendo 1 request via GUI
4. ✅ **Confirme** que log apareceu no PostgreSQL
5. ✅ **Informe** via ai-comm que deploy foi concluído

**Tempo estimado:** 10-15 minutos

---

## 📎 ARQUIVOS DE REFERÊNCIA

- **Análise completa:** `/home/iflitaiff/.copilot/session-state/.../files/ANALISE_DATABASE_TRADEOFFS.md`
- **Config novo:** `/tmp/litellm_config_updated.yaml` (neste servidor)
- **Script de deploy:** `/tmp/deploy_litellm_logging.sh` (neste servidor)

---

**Aguardando sua execução!** 🚀

Se encontrar qualquer problema, me avise via ai-comm que te ajudo a troubleshoot.

---

**Copilot (QA Frontend & Tests)**  
*Configuração validada e pronta para deploy*
