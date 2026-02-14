# N8N → LiteLLM Integration

Guia de integração entre N8N (CT 104) e LiteLLM (CT 103).

---

## 🎯 Objetivo

Permitir que workflows N8N usem LiteLLM para tarefas de IA (geração de texto, análise, resumos, embeddings, etc.).

---

## 🔧 Configuração no N8N

### Credenciais LiteLLM

1. **Acessar N8N:** http://localhost:5678 (via tunnel)
2. **Credentials → Add Credential → HTTP Header Auth**
   - **Name:** `LiteLLM API Key`
   - **Header Name:** `Authorization`
   - **Header Value:** `Bearer sk-sunyata-ec909e5420572a4c8c496822a9459bcdec1391c7`

### Criar Nó HTTP Request (Template)

**Endpoint:** `POST http://192.168.100.13:4000/v1/chat/completions`

**Headers:**
```json
{
  "Content-Type": "application/json",
  "Authorization": "Bearer sk-sunyata-ec909e5420572a4c8c496822a9459bcdec1391c7"
}
```

**Body (JSON):**
```json
{
  "model": "claude-sonnet-4-5",
  "messages": [
    {
      "role": "user",
      "content": "{{ $json.prompt }}"
    }
  ],
  "temperature": 0.7,
  "max_tokens": 2000
}
```

**Response:**
```json
{
  "id": "chatcmpl-xxx",
  "object": "chat.completion",
  "created": 1234567890,
  "model": "claude-sonnet-4-5",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "Resposta da IA..."
      },
      "finish_reason": "stop"
    }
  ]
}
```

**Extrair resposta:**
```javascript
{{ $json.choices[0].message.content }}
```

---

## 📊 Modelos Disponíveis

| Modelo | ID | Uso Recomendado |
|--------|-----|-----------------|
| Claude Opus 4.6 | `claude-opus-4-6` | Tarefas complexas, raciocínio |
| Claude Sonnet 4.5 | `claude-sonnet-4-5` | Balanceado (recomendado) |
| Claude Haiku 4.5 | `claude-haiku-4-5` | Rápido, barato |
| GPT-4o | `gpt-4o` | Multimodal |
| GPT-4o-mini | `gpt-4o-mini` | Rápido, barato |
| o3-mini | `o3-mini` | Raciocínio avançado |

**Ver todos:** `GET http://192.168.100.13:4000/v1/models`

---

## 🎯 Casos de Uso

### 1. Resumo de Edital (PNCP Monitor)

**Workflow:**
```
Webhook (novo edital)
  → HTTP Request (buscar PDF)
  → Extrair texto
  → LiteLLM (resumir)
  → PostgreSQL (salvar resumo)
  → Email (notificar)
```

**Prompt:**
```
Resuma este edital de licitação:
- Objeto principal
- Valor estimado
- Prazo de entrega
- Requisitos críticos

Edital:
{{ $json.texto_edital }}
```

### 2. Análise de Sentimento (Feedback)

**Workflow:**
```
PostgreSQL (buscar feedback)
  → LiteLLM (analisar sentimento)
  → PostgreSQL (atualizar sentiment_score)
```

**Prompt:**
```
Analise o sentimento deste feedback (positivo/neutro/negativo) e retorne apenas uma palavra:

{{ $json.feedback_text }}
```

### 3. Geração de Documentos

**Workflow:**
```
Webhook (formulário submetido)
  → PostgreSQL (buscar dados)
  → LiteLLM (gerar parecer)
  → Google Drive (salvar PDF)
  → Email (enviar)
```

---

## 🔐 Segurança

### Master Key vs Virtual Keys

**Atualmente:** N8N usa master key diretamente

**Recomendado (futuro):**
1. Criar virtual key específica para N8N no LiteLLM UI
2. Limitar modelos/quotas
3. Logs isolados

**Criar virtual key:**
```bash
# Via LiteLLM UI (http://localhost:4000/ui)
# Settings → Keys → Add Key
# Name: n8n-workflows
# Models: claude-sonnet-4-5, gpt-4o-mini
# Budget: $100/month
```

---

## 📈 Monitoramento

### Ver Logs LiteLLM

```bash
# Via ssh-cmd.sh
./tools/ssh-cmd.sh ct103 "docker logs litellm --tail 50 -f"

# Ver uso por key
# LiteLLM UI → Analytics → Usage by Key
```

### Métricas importantes

- **Latência:** tempo de resposta (alvo: <5s)
- **Tokens:** input + output (custo)
- **Erros:** rate limit, timeout, 401

---

## 🧪 Testar Integração

### Via N8N Manual Trigger

1. Criar workflow simples:
   - **Manual Trigger**
   - **HTTP Request** → LiteLLM
   - **Set** → extrair resposta

2. Executar e verificar logs

### Via curl (debug)

```bash
curl -X POST http://192.168.100.13:4000/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer sk-sunyata-ec909e5420572a4c8c496822a9459bcdec1391c7" \
  -d '{
    "model": "claude-sonnet-4-5",
    "messages": [{"role": "user", "content": "Olá!"}],
    "max_tokens": 100
  }'
```

---

## 🚨 Troubleshooting

### Erro 401 (Unauthorized)

- Verificar API key (deve começar com `sk-`)
- Header: `Authorization: Bearer <key>`

### Erro 404 (Model Not Found)

- Listar modelos disponíveis: `GET /v1/models`
- Usar ID exato (ex: `claude-sonnet-4-5`, não `sonnet`)

### Timeout

- Aumentar timeout no nó HTTP Request (default: 30s)
- Usar modelo mais rápido (haiku, gpt-4o-mini)
- Reduzir `max_tokens`

---

**Versão:** 1.0
**Data:** 2026-02-13
