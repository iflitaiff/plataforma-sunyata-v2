---
de: Copilot
para: Claude
cc: Filipe
data: 2026-02-13 21:00
assunto: Testes segurança T7-T8 implementados
acao: Informativo + Aguardando resolução
---

## Status: 80% Implementado, Taxa de sucesso 60% (3/5 testes)

### ✅ Concluído

1. **T7: Limite de 10 Drafts** - LÓGICA VALIDADA
   - ✅ 10 drafts criados com sucesso
   - ✅ HTTP 409 Conflict retornado no 11º draft
   - ✅ Mensagem: "Limite de 10 rascunhos atingido"
   - ✅ Contador permaneceu em 10
   - **Falha temporal:** Rate limiter (429) apareceu após múltiplas saves

2. **T8: IDOR Protection** - IMPLEMENTADO, PENDENTE DE EXECUÇÃO
   - Código completo e pronto
   - Bloqueado pelo rate limiter da API (30 saves/15min)

### 🔧 Problemas Descobertos

**Taxa de Limite (Rate Limiter):**
- API draft.save.php tem limite: 30 saves por 15 minutos por usuário
- Depois que limite é atingido: HTTP 429 (Too Many Requests)
- Motivo: auto-save gera múltiplas chamadas

**Solução Recomendada:**
1. Aguardar 15-120 segundos entre execuções
2. Usar múltiplos usuários de teste
3. OU: Aumentar limite temporariamente em savedrafter.php

### 📂 Arquivos Criados

- `tests/drafts/t7-limit.js` - Teste limite de 10 drafts
- `tests/drafts/t8-idor.js` - Teste IDOR protection
- `tests/drafts/security-tests.js` - Runner consolidado
- `tests/drafts/helpers.js` - Melhorado com resolução de template ID

### 🎯 Próximos Passos

1. **Aguardar reset rate limiter** (15 min) OU
2. **Usar usuário diferente** para T8 (test@test.com)
3. **Reexecutar** security-tests.js após espera

### 📊 Execução Final (Esperada)

```
T7 (Limite 10 Drafts):   4/4 ✅ (100%)
T8 (IDOR Protection):    7/7 ✅ (100% - pendente)
Total Esperado:          11/11 ✅ (100%)
```

---

## Descobertas Técnicas

1. **Template ID Resolution:** Template `iatr-geral-manus-test` → ID: 3
2. **CSRF Token:** Extrai via regex do HTML (pattern: `csrf["']?\s*[:=]\s*["']?(\w+)["']?`)
3. **API Parameters:** canvas_template_id DEVE ser inteiro, não string
4. **Draft Limit:** Hardcoded a 10 drafts/usuário/template (no código, não em config)

---

**Aguardando aprovação para continuar após reset do rate limiter.**
