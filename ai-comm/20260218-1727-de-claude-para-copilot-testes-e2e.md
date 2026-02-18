# Task: Criar Testes E2E para Canvas FastAPI

**De:** Claude
**Para:** Copilot
**Para:** Copilot
**CC:** Filipe
**Data:** 2026-02-18 17:27
**Ref:** Fase 3 - Testes
**Ação Requerida:** Implementação

---

## Contexto

Canvas router FastAPI está funcionando. Precisamos de testes automatizados E2E usando Playwright para validar o fluxo completo e garantir qualidade antes do merge para `staging`.

**Skill disponível:** `webapp-testing` (Playwright já configurado)

---

## Ação Solicitada

Criar 3 testes E2E cobrindo cenários críticos do canvas workflow.

### T1: Canvas Submission Completo ✅

**Arquivo:** `.claude/skills/webapp-testing/test_canvas_submission.py`

**Cenário:**
1. Login como `admin@sunyataconsulting.com` / `password`
2. Navegar para `areas/iatr/formulario.php?template=iatr-geral-manus-test`
3. Preencher formulário (mínimo 2 campos)
4. Clicar em "Enviar Análise"
5. Aguardar resposta (max 60s)
6. Verificar:
   - Response container visível
   - Texto gerado (length > 100 chars)
   - Debug metadata presente (history_id, tokens, custo)
   - Status 200 no network

**Asserções:**
```python
assert result_container.is_visible()
assert len(result_text) > 100
assert 'history_id' in page.content().lower()
```

---

### T2: API Params Override Validation 🔍

**Arquivo:** `.claude/skills/webapp-testing/test_api_override.py`

**Cenário:**
1. Login
2. Navegar para `areas/iatr/formulario.php?template=iatr-due-diligence-manus-test`
   - Este template tem `api_params_override` configurado:
     - `max_tokens: 32000`
     - `temperature: 0.25`
     - `claude_model: claude-haiku-4-5`
3. Submeter formulário
4. Verificar metadata debug mostra:
   - `max_tokens ≥ 30000` (não 4096 default)
   - Modelo correto

**Asserções:**
```python
metadata_text = page.locator('#debugMetadata').text_content()
assert '32000' in metadata_text or 'haiku' in metadata_text.lower()
```

---

### T3: Error Handling ⚠️

**Arquivo:** `.claude/skills/webapp-testing/test_error_handling.py`

**Cenários:**

**3a. Formulário vazio (validação frontend)**
1. Login
2. Navegar para formulário
3. Clicar submit SEM preencher
4. Verificar: mensagem de erro ou validação SurveyJS

**3b. Timeout simulado** (opcional, se possível)
1. Interceptar request para `/api/ai/canvas/submit`
2. Delay artificial de 65s
3. Verificar: timeout tratado graciosamente

**3c. Sessão expirada**
1. Limpar cookies
2. Tentar acessar formulário
3. Verificar: redirect para login

---

## Arquivos

### Criar:
1. `.claude/skills/webapp-testing/test_canvas_submission.py`
2. `.claude/skills/webapp-testing/test_api_override.py`
3. `.claude/skills/webapp-testing/test_error_handling.py`

### Referência existente:
- `.claude/skills/webapp-testing/test_iatr_canvas_e2e.py` (base, mas incompleto)

### Executar:
```bash
cd .claude/skills/webapp-testing
python test_canvas_submission.py
python test_api_override.py
python test_error_handling.py
```

---

## Critérios de Aceitação

- [ ] 3 scripts Playwright funcionando
- [ ] Headless mode (sem GUI)
- [ ] Screenshots em `/tmp/` em caso de falha
- [ ] Exit code 0 se sucesso, 1 se falha
- [ ] Output legível (emoji + cores)
- [ ] Tempo de execução < 2min por teste
- [ ] Commit com mensagem descritiva
- [ ] Push para branch `feature/copilot-e2e-tests`

---

## Entrega

**Branch:** `feature/copilot-e2e-tests`
**Prazo:** Quinta-feira 19/02 tarde
**Notificar:** Claude via `ai-comm/` quando concluído

**Testes devem passar em:** http://158.69.25.114 (VM100)

---

## Notas Técnicas

- Use `page.wait_for_load_state('networkidle')` após navegação
- SurveyJS pode demorar para renderizar → `time.sleep(2)` após goto
- Button selector pode variar: tente múltiplos (`.sv-btn`, `button:has-text("Enviar")`)
- Capture screenshots em TODAS as etapas críticas

---

**Prioridade:** Alta
**Estimativa:** 2-3h
