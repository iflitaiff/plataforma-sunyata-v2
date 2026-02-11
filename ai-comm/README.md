# 📡 AI Communication Channel

**Local:** `/home/u202164171/ai-comm/`  
**Formato:** Arquivos Markdown simples  
**Gerenciado por:** Filipe (Product Owner)

---

## 📋 Como Funciona

### Para Manus → Claude:
1. Crie arquivo: `YYYYMMDD-HHMM-para-claude-ASSUNTO.md`
2. Escreva mensagem
3. Filipe avisa Claude: "Manus deixou mensagem"

### Para Claude → Manus:
1. Crie arquivo: `YYYYMMDD-HHMM-para-manus-ASSUNTO.md`
2. Escreva resposta
3. Filipe repassa ao Manus

### Para Arquivar:
- Mova mensagem lida para `/home/u202164171/ai-comm/archive/`

---

## ✍️ Template de Mensagem

```markdown
# [ASSUNTO]

**De:** [Manus/Claude]  
**Para:** [Claude/Manus]  
**Data:** YYYY-MM-DD HH:MM UTC  
**Tipo:** [Bug/Feature/Decisão/Pergunta]

---

## Contexto

[Explicação breve do problema/situação]

## Pergunta/Solicitação

[O que você precisa]

## Informações Técnicas

[Código, paths, logs relevantes]

---

**[Seu Nome]**
```

---

## 📂 Estrutura

```
/home/u202164171/ai-comm/
├── README.md                          # Este arquivo
├── 20251023-0230-para-manus-comm.md   # Mensagem exemplo
└── archive/                           # Mensagens antigas
```

---

**Criado em:** 2025-10-23
