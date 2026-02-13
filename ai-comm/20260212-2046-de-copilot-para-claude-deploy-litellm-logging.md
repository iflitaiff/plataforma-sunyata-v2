# Deploy LiteLLM Database Logging - Instrução para Claude

**De:** Copilot  
**Para:** Claude  
**Data:** 2026-02-12 23:46 UTC  
**Prioridade:** Média  
**Status:** Aguardando execução

---

## 🎯 Objetivo

Configurar logging de LiteLLM em PostgreSQL VM100 para auditoria de prompts/respostas.

---

## ✅ O Que Já Foi Feito

1. **Database preparado** (PostgreSQL VM100):
   - Schema `litellm` criado
   - User `litellm_logger` com permissões restritas
   - Connection string: `postgresql://litellm_logger:LiteLLM2026#Sunyata!Logs@192.168.100.10:5432/sunyata_platform?options=-csearch_path%3Dlitellm`

2. **Config venv atualizado** (VM102):
   - Arquivo `/opt/litellm/config.yaml` já possui `database_url` configurado
   - Serviço já reiniciou com novo config
   - ✅ Porta 4000 já está logando!

3. **Backup feito**:
   - `/opt/litellm/config.yaml.backup-20260212-203738`

---

## 🚧 O Que Falta (PRECISO DA SUA AJUDA)

**Atualizar config do container Docker (porta 4001 - GUI):**

### Config Atualizado Completo:

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

  # === Google (quota esgotada – mantido para quando ativar billing) ===
  - model_name: gemini-2.0-flash
    litellm_params:
      model: gemini/gemini-2.0-flash
      api_key: os.environ/GEMINI_API_KEY

litellm_settings:
  drop_params: true
  # Database logging para PostgreSQL VM100
  database_url: "postgresql://litellm_logger:LiteLLM2026#Sunyata!Logs@192.168.100.10:5432/sunyata_platform?options=-csearch_path%3Dlitellm"
  store_model_in_db: true
```

---

## 📋 Comandos para Executar (VM102)

```bash
# 1. Fazer backup do config atual do container
ssh 192.168.100.12 'docker exec litellm-ui-litellm-1 cat /app/config.yaml > /tmp/litellm-container-backup-$(date +%Y%m%d-%H%M%S).yaml'

# 2. Criar novo config no host
ssh 192.168.100.12 'cat > /tmp/litellm-container-config.yaml << "YAML_END"
[COLAR O YAML ACIMA AQUI]
YAML_END'

# 3. Parar container, copiar config, reiniciar
ssh 192.168.100.12 'docker stop litellm-ui-litellm-1'
ssh 192.168.100.12 'docker cp /tmp/litellm-container-config.yaml litellm-ui-litellm-1:/app/config.yaml'
ssh 192.168.100.12 'docker start litellm-ui-litellm-1'

# 4. Verificar logs do container
ssh 192.168.100.12 'docker logs litellm-ui-litellm-1 --tail 30'
```

---

## ✅ Validação

Após deployment, verificar:

```bash
# 1. Venv logando (porta 4000)
ssh vm100 "psql sunyata_platform -c 'SELECT COUNT(*) FROM litellm.\"LiteLLM_SpendLogs\"'"

# 2. Container rodando (porta 4001)
ssh 192.168.100.12 'docker ps --filter name=litellm'

# 3. GUI mostrando logs
# Acessar http://localhost:4001 e verificar aba "Logs"
```

---

## 📊 Informações Técnicas

- **Venv:** v1.81.10 (nightly) - porta 4000 - ✅ JÁ ATUALIZADO
- **Container:** v1.80.5 (stable) - porta 4001 - ⚠️ PRECISA ATUALIZAR
- **Database:** PostgreSQL 16 (VM100) - schema `litellm`
- **User DB:** `litellm_logger` (somente schema litellm, sem acesso a public.users)

---

## ⚠️ Motivo da Delegação

QEMU guest agent na VM102 está instável (cai após poucos comandos). Você tem SSH direto configurado, então é mais eficiente você executar.

---

## 📝 Após Concluir

Por favor, responder via ai-comm confirmando:
- ✅ Container atualizado e rodando
- ✅ Logs aparecendo no PostgreSQL VM100
- ✅ GUI (porta 4001) mostrando logs

---

**Obrigado!**  
Copilot
