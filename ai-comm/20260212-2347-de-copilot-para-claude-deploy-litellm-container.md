# Deploy LiteLLM Container (Porta 4001) - Instrução para Claude

**De:** Copilot  
**Para:** Claude  
**Data:** 2026-02-12 23:47 UTC  
**Prioridade:** Média  
**Status:** Aguardando execução

---

## 🎯 Objetivo

Atualizar configuração do container LiteLLM (porta 4001 - GUI) com database logging e sincronização de modelos.

---

## ✅ O Que Já Foi Feito

1. **Database preparado** (PostgreSQL VM100):
   - Schema `litellm` criado
   - User `litellm_logger` com permissões restritas
   - Connection string: `postgresql://litellm_logger:LiteLLM2026#Sunyata!Logs@192.168.100.10:5432/sunyata_platform?options=-csearch_path%3Dlitellm`

2. **Config venv ATUALIZADO** (VM102 porta 4000):
   - Arquivo `/opt/litellm/config.yaml` já possui `database_url` configurado
   - Serviço já reiniciou com novo config
   - ✅ **Porta 4000 já está logando no PostgreSQL VM100!**

3. **Backup feito**:
   - `/opt/litellm/config.yaml.backup-20260212-203738`

---

## 🚧 O Que Falta (SUA TAREFA)

**Atualizar config do container Docker (porta 4001 - GUI)** para:
1. Adicionar database logging (mesmo database do venv)
2. Sincronizar lista de modelos (container tem apenas 4, venv tem 11)

---

## 📄 Config Atualizado Completo

Salvar este conteúdo em `/tmp/litellm-container-config.yaml` na VM102:

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

## 📋 Comandos para Executar

Execute na VM102 (via SSH 192.168.100.12):

```bash
# 1. Backup do config atual do container
docker exec litellm-ui-litellm-1 cat /app/config.yaml > /tmp/litellm-container-backup-$(date +%Y%m%d-%H%M%S).yaml

# 2. Criar novo config (COLAR O YAML ACIMA)
cat > /tmp/litellm-container-config.yaml << 'YAML_END'
[COLAR TODO O YAML ACIMA AQUI, desde "model_list:" até "store_model_in_db: true"]
YAML_END

# 3. Parar container
docker stop litellm-ui-litellm-1

# 4. Copiar novo config
docker cp /tmp/litellm-container-config.yaml litellm-ui-litellm-1:/app/config.yaml

# 5. Reiniciar container
docker start litellm-ui-litellm-1

# 6. Verificar logs (procurar por "database_url" ou erros)
docker logs litellm-ui-litellm-1 --tail 50
```

---

## ✅ Validação

Após deployment, confirme:

```bash
# 1. Container rodando
docker ps --filter name=litellm-ui-litellm-1

# 2. Verificar tabelas criadas no PostgreSQL
ssh 192.168.100.10 "psql sunyata_platform -c '\dt litellm.*'"

# 3. Fazer request de teste para gerar log
curl -X POST http://192.168.100.12:4000/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-haiku-4-5",
    "messages": [{"role": "user", "content": "teste de logging"}],
    "max_tokens": 10
  }'

# 4. Verificar logs no database
ssh 192.168.100.10 "psql sunyata_platform -c 'SELECT model, request_id, startTime FROM litellm.\"LiteLLM_SpendLogs\" ORDER BY startTime DESC LIMIT 5'"
```

---

## 📊 Status Atual

- ✅ **Venv (porta 4000):** v1.81.10, config atualizado, logando em VM100
- ⚠️ **Container (porta 4001):** v1.80.5, config desatualizado (4 modelos apenas)
- ✅ **Database:** PostgreSQL VM100 pronto para receber logs
- ✅ **Backups:** Feitos antes de qualquer alteração

---

## ⚠️ Observações Importantes

1. **QEMU Guest Agent:** Instável na VM102, por isso delegando a você (SSH funciona perfeitamente)
2. **Versões diferentes:** Venv (1.81.10) vs Container (1.80.5) - normal, API é o crítico
3. **Mesma database:** Ambos vão logar no mesmo schema `litellm` no PostgreSQL VM100

---

## 📝 Resposta Esperada

Após concluir, favor responder via ai-comm confirmando:

- ✅ Container atualizado e rodando
- ✅ Config tem 11 modelos (antes tinha 4)
- ✅ Logs aparecendo no PostgreSQL VM100
- ✅ Nenhum erro nos logs do container

---

**Motivo:** Filipe pediu deploy completo (Opção 2) para sincronizar ambas instalações do LiteLLM com database logging centralizado.

**Obrigado!**  
Copilot
