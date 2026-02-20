# Claude Chat — Setup ai-comm

Guia para integrar o Claude Chat (Windows) no fluxo ai-comm da Plataforma Sunyata.

## 1. Setup Rápido

### 1.1 Testar conexão SSH ao Hostinger

Os scripts enviam mensagens para o Hostinger (onde roda o monitor-aicomm.sh).

```powershell
ssh -p 65002 u202164171@82.25.72.226 "echo OK"
```

Se falhar, configure a chave SSH do Windows para o Hostinger:
```powershell
type "$env:USERPROFILE\.ssh\id_ed25519.pub" | ssh -p 65002 u202164171@82.25.72.226 "cat >> ~/.ssh/authorized_keys"
```

### 1.2 Configurar Claude Chat

O Claude Chat roda no browser — **não tem acesso ao filesystem**.
O Filipe é o intermediário (copy/paste).

No Claude Chat (claude.ai), adicione o conteúdo de `SYSTEM-PROMPT.md` como:
- **Project Knowledge** (se usar Projects) — colar o conteúdo inteiro
- **Ou** iniciar a sessão colando o conteúdo no início da conversa

## 2. Uso Diário

### Ler mensagens

```powershell
# Listar últimas mensagens
.\read-messages.ps1

# Listar mensagens para claude-chat
.\read-messages.ps1 -For "claude-chat"

# Ler uma mensagem específica
.\read-messages.ps1 -Read "20260219-1500-de-claude-para-claude-chat-review.md"
```

### Enviar mensagem

**Opção A — Interativo:**
```powershell
.\send-message.ps1
# Siga os prompts (destinatário, assunto, conteúdo)
```

**Opção B — De arquivo:**
1. Copie a resposta do Claude Chat para um arquivo `.md`
2. Execute:
```powershell
.\send-message.ps1 -Destination "claude" -Subject "arquitetura-pncp" -File "mensagem.md"
```

**Opção C — Via WSL (alternativa):**
```bash
# No WSL, salvar mensagem e enviar ao Hostinger
scp -P 65002 mensagem.md u202164171@82.25.72.226:/home/u202164171/ai-comm/
```

## 3. Fluxo Típico

```
1. Filipe abre sessão Claude Chat
2. Compartilha contexto/pergunta
3. Claude Chat analisa e responde
4. Se precisar comunicar com outro agente:
   a. Claude Chat formata no padrão ai-comm
   b. Filipe copia a mensagem
   c. Filipe executa send-message.ps1
5. Agente destinatário recebe via monitor-aicomm.sh (email)
6. Resposta volta pelo mesmo canal
```

## 4. Arquivos neste diretório

| Arquivo | Descrição |
|---------|-----------|
| `SYSTEM-PROMPT.md` | Instruções para colar no Claude Chat (Project Knowledge) |
| `send-message.ps1` | Script PowerShell para enviar mensagens |
| `read-messages.ps1` | Script PowerShell para ler mensagens |
| `README.md` | Este arquivo |

## 5. Diferença: Claude Code vs Claude Chat

| | Claude Code | Claude Chat |
|--|-------------|-------------|
| **Ambiente** | WSL/Linux (terminal) | Windows (browser) |
| **Acesso** | SSH direto, Git, filesystem | Via Filipe (copy/paste) |
| **Papel** | Implementa código | Consulta arquitetural |
| **ai-comm** | Automático (scp) | Via scripts PowerShell |
| **ID** | `claude` | `claude-chat` |
