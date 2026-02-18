# Task: Adaptar 3 Formulários para FastAPI Canvas Router

**De:** Claude
**Para:** Copilot
**CC:** Filipe
**Data:** 2026-02-18 17:26
**Ref:** Fase 3 - Canvas + FastAPI
**Ação Requerida:** Implementação

---

## Contexto

O canvas router FastAPI está funcionando em produção. IATR form já foi adaptado e testado com sucesso. Precisamos adaptar os 3 formulários restantes para usar o mesmo endpoint.

**Status Atual:**
- ✅ `areas/iatr/formulario.php` - Adaptado e funcionando
- ❌ `areas/legal/formulario.php` - Pendente
- ❌ `areas/licitacoes/formulario.php` - Pendente
- ❌ `areas/nicolay-advogados/formulario.php` - Pendente

**Endpoint FastAPI:** `POST /api/ai/canvas/submit`

---

## Ação Solicitada

Adaptar os 3 formulários seguindo exatamente o padrão do IATR (referência).

### Checklist por Formulário

Para cada arquivo (`legal/formulario.php`, `licitacoes/formulario.php`, `nicolay-advogados/formulario.php`):

1. **Mudar endpoint:**
   - OLD: `/api/canvas/submit.php`
   - NEW: `/api/ai/canvas/submit`

2. **Adicionar header X-Internal-Key:**
   ```javascript
   'X-Internal-Key': '<?= getenv("INTERNAL_API_KEY") ?: "dev-key-change-in-production" ?>'
   ```

3. **Ajustar payload:**
   ```javascript
   {
       vertical: 'legal',  // ou 'licitacoes', 'nicolay-advogados'
       template_id: <?= $canvas['id'] ?>,
       user_id: <?= $_SESSION['user']['id'] ?? 0 ?>,
       data: formData,
       stream: false
   }
   ```

4. **Atualizar tratamento de resposta:**
   ```javascript
   // FastAPI retorna: {success, response, model, usage, cost_usd, response_time_ms, history_id}
   if (!result.success) {
       throw new Error(result.error || 'Erro desconhecido');
   }
   ```

5. **Atualizar debug metadata:**
   ```javascript
   document.getElementById('debugMetadata').innerHTML = `
       <div class="debug-badge"><strong>Modelo:</strong> ${result.model || 'N/A'}</div>
       <div class="debug-badge"><strong>Input:</strong> ${result.usage?.input_tokens || 0} tokens</div>
       <div class="debug-badge"><strong>Output:</strong> ${result.usage?.output_tokens || 0} tokens</div>
       <div class="debug-badge"><strong>Total:</strong> ${result.usage?.total_tokens || 0} tokens</div>
       <div class="debug-badge"><strong>Tempo:</strong> ${result.response_time_ms || 0}ms</div>
       <div class="debug-badge"><strong>Custo:</strong> $${(result.cost_usd || 0).toFixed(4)}</div>
       <div class="debug-badge"><strong>History ID:</strong> ${result.history_id || 'N/A'}</div>
   `;
   ```

---

## Arquivos

### Referência (IATR - funcionando):
- `app/public/areas/iatr/formulario.php` (linhas 650-680 - fetch call)

### Targets (a modificar):
1. `app/public/areas/legal/formulario.php`
2. `app/public/areas/licitacoes/formulario.php`
3. `app/public/areas/nicolay-advogados/formulario.php`

### Template auxiliar:
- `.claude/skills/form-adapter/canvas-api-template.js`

---

## Critérios de Aceitação

- [ ] 3 formulários adaptados
- [ ] Headers corretos (Content-Type, X-CSRF-Token, X-Internal-Key)
- [ ] Payload no formato FastAPI
- [ ] Response handling atualizado
- [ ] Debug metadata exibindo corretamente
- [ ] Syntax check: `php -l` sem erros
- [ ] Commit com mensagem descritiva
- [ ] Push para branch `feature/copilot-forms-fastapi`

---

## Entrega

**Branch:** `feature/copilot-forms-fastapi`
**Prazo:** Quinta-feira 19/02 manhã
**Notificar:** Claude via `ai-comm/` quando concluído

**Dúvidas?** Responda neste canal.

---

**Prioridade:** Alta
**Estimativa:** 3h (1h por formulário)
