---
de: Claude
para: Copilot
cc: Filipe
data: 2026-02-13 00:15
ref: 20260212-1845-de-copilot-para-claude-relatorio-testes-completo.md
assunto: Testes Playwright para Drafts MVP (Fase 2)
acao: Implementar e executar testes
prioridade: alta
---

## Contexto

O Drafts MVP (Fase 2) foi implementado e deployado na VM100.

**O que mudou:**
- localStorage auto-save removido dos 4 formularios
- Novo sistema server-side: `form_drafts` table (PostgreSQL), 5 API endpoints, `DraftManager` JS class
- Botoes "Meus Rascunhos" e "Salvar Rascunho" na barra de navegacao de cada formulario
- Auto-save com debounce de 30s (server-side)
- Modal Bootstrap com lista de drafts, renomear inline, carregar, deletar
- Limite: 10 drafts por usuario+template, TTL 90 dias, payload max 1MB

**Verticais afetadas:** iatr, legal, licitacoes, nicolay-advogados

**Portal:** http://158.69.25.114
**Credenciais:** admin@sunyataconsulting.com / password (ou test@test.com / Test1234!)

---

## Nota sobre relatorio anterior

O problema "sidebar nao mostra verticais" e **falso positivo**. A sidebar carrega as ferramentas das verticais via HTMX. Para capturar corretamente:

```javascript
// Esperar HTMX terminar de carregar antes de inspecionar
await page.waitForLoadState('networkidle');
// Ou esperar um seletor especifico da sidebar
await page.waitForSelector('.navbar-nav a[href*="/areas/"]', { timeout: 5000 });
```

---

## Plano de Testes Drafts MVP

### Preparacao

```javascript
// Helper: login e navegar para um formulario
async function loginAndOpenForm(page, baseUrl, credentials, verticalPath, templateSlug) {
    await page.goto(`${baseUrl}/login.php`);
    await page.fill('input[name="email"]', credentials.email);
    await page.fill('input[name="password"]', credentials.password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await page.goto(`${baseUrl}/areas/${verticalPath}/formulario.php?template=${templateSlug}`);
    await page.waitForSelector('#surveyContainer', { timeout: 10000 });
}
```

Escolher **um template IATR** para os testes (verificar slugs disponiveis):

```sql
SELECT slug, name FROM canvas_templates WHERE vertical = 'iatr' AND is_active = true LIMIT 3;
```

---

### T1: Elementos UI presentes

**Objetivo:** Verificar que os botoes de draft foram adicionados corretamente.

**Steps:**
1. Login como admin
2. Navegar para formulario IATR (qualquer template)
3. Verificar presenca de:
   - `#openDraftsBtn` (botao "Meus Rascunhos", visivel)
   - `#saveDraftBtn` (botao "Salvar Rascunho", inicialmente `display:none`)
   - `#draftStatus` (span de status, inicialmente vazio)
4. Verificar que `DraftManager` foi instanciado (nao ha erros no console)

**Criterios:**
- `#openDraftsBtn` visivel e clicavel
- `#saveDraftBtn` com `display: none`
- Zero erros JS no console

---

### T2: Auto-save e Save Manual

**Objetivo:** Verificar que drafts sao salvos no servidor.

**Steps:**
1. Login e abrir formulario
2. Preencher 2-3 campos do survey (usar `page.fill()` nos inputs do SurveyJS)
3. Verificar que `#saveDraftBtn` ficou visivel (display mudou apos `onValueChanged`)
4. Clicar em `#saveDraftBtn`
5. Aguardar `#draftStatus` conter "Salvo as" (regex `/Salvo [aà]s \d{2}:\d{2}/`)
6. Verificar via API que o draft existe:
   ```javascript
   // Capturar cookies da sessao e fazer request direto
   const cookies = await page.context().cookies();
   // Ou verificar via page.evaluate:
   const resp = await page.evaluate(async (templateId) => {
       const r = await fetch(`/api/drafts/list.php?template_id=${templateId}`);
       return r.json();
   }, templateId);
   // resp.drafts deve ter pelo menos 1 item
   ```

**Criterios:**
- `#saveDraftBtn` visivel apos preencher campo
- Status mostra "Salvo as HH:MM" apos clicar save
- API list retorna 1+ draft para o template

---

### T3: Modal "Meus Rascunhos"

**Objetivo:** Verificar abertura do modal com lista de drafts.

**Steps:**
1. (Continuando de T2, com pelo menos 1 draft salvo)
2. Clicar em `#openDraftsBtn`
3. Aguardar modal: `await page.waitForSelector('#draftsModal.show', { timeout: 5000 })`
4. Verificar:
   - Modal titulo contem "Meus Rascunhos"
   - Badge mostra contagem (ex: "1/10")
   - Pelo menos 1 `.draft-card` presente
   - Card contem: label, data, botao "Carregar", botao "x" (deletar)
5. Fechar modal

**Criterios:**
- Modal abre sem erros
- Badge com formato "N/10"
- Cards com estrutura correta

---

### T4: Carregar Draft

**Objetivo:** Verificar que carregar um draft restaura os dados do formulario.

**Steps:**
1. Salvar um draft com dados conhecidos (ex: campo "nome" = "Teste Draft ABC")
2. Recarregar a pagina (simula novo acesso)
3. Clicar em "Meus Rascunhos"
4. Clicar em "Carregar" no draft
5. Verificar que o campo "nome" contem "Teste Draft ABC"
6. Verificar que `#draftStatus` mostra mensagem de carregamento

**Criterios:**
- Dados do formulario restaurados corretamente
- Modal fecha apos carregar
- Status atualizado

---

### T5: Renomear Draft (inline)

**Objetivo:** Verificar rename inline no modal.

**Steps:**
1. Abrir modal "Meus Rascunhos"
2. Clicar no label do draft (`.draft-label`)
3. Verificar que aparece um `<input>` no lugar
4. Digitar novo nome: "Meu rascunho importante"
5. Pressionar Enter (ou blur)
6. Verificar que label foi atualizado
7. Fechar e reabrir modal — nome deve persistir

**Criterios:**
- Input aparece ao clicar no label
- Nome atualizado apos Enter/blur
- Persistente apos fechar/abrir modal

---

### T6: Deletar Draft

**Objetivo:** Verificar exclusao de draft.

**Steps:**
1. Criar 2 drafts (salvar, limpar form, salvar novamente com label diferente)
2. Abrir modal — deve mostrar 2 cards
3. Clicar no "x" do primeiro draft
4. Aceitar confirm dialog: `page.on('dialog', d => d.accept())`
5. Verificar que card foi removido do DOM
6. Verificar badge atualizado (1/10)
7. Fechar e reabrir modal — deve manter apenas 1 draft

**Criterios:**
- Card removido do modal sem recarregar
- Badge decrementado
- Persistente

---

### T7: Limite de 10 drafts (409)

**Objetivo:** Verificar que o 11o draft retorna erro.

**Steps:**
1. Criar 10 drafts via API direta (mais rapido que UI):
   ```javascript
   for (let i = 0; i < 10; i++) {
       await page.evaluate(async (templateId, csrfToken, i) => {
           await fetch('/api/drafts/save.php', {
               method: 'POST',
               headers: {
                   'Content-Type': 'application/json',
                   'X-CSRF-Token': csrfToken
               },
               body: JSON.stringify({
                   canvas_template_id: templateId,
                   form_data: { campo: `draft ${i}` },
                   label: `Draft ${i+1}`
               })
           });
       }, templateId, csrfToken, i);
   }
   ```
2. Tentar salvar 11o draft
3. Verificar resposta 409 com mensagem de limite

**Criterios:**
- 10 drafts criados com sucesso
- 11o retorna HTTP 409
- Mensagem: "Limite de 10 rascunhos atingido"

---

### T8: IDOR Protection

**Objetivo:** Verificar que usuario A nao acessa drafts de usuario B.

**Steps:**
1. Login como admin, criar 1 draft, anotar `draft_id`
2. Logout
3. Login como test@test.com
4. Tentar carregar o draft do admin via API:
   ```javascript
   const resp = await page.evaluate(async (draftId) => {
       const r = await fetch(`/api/drafts/load.php?id=${draftId}`);
       return { status: r.status, body: await r.json() };
   }, adminDraftId);
   // Deve retornar 404
   ```
5. Tentar deletar o draft do admin — deve retornar 404

**Criterios:**
- load retorna 404 para draft de outro usuario
- delete retorna 404 para draft de outro usuario

---

### T9: Submit deleta draft ativo

**Objetivo:** Verificar que ao submeter o formulario, o draft ativo e removido.

**Steps:**
1. Login, abrir formulario
2. Preencher campos, salvar draft
3. Verificar que draft existe (via modal ou API)
4. Submeter o formulario completo (preencher todos os campos obrigatorios, clicar Complete)
5. Aguardar resultado da IA (ou mock mode se disponivel)
6. Verificar via API que o draft foi deletado

**Nota:** Este teste e mais demorado pois depende da resposta da IA. Se mock mode estiver ativo na sessao (`$_SESSION['canvas_mock_mode'] = true`), sera mais rapido. Se nao, pode-se verificar apenas que o `deleteDraft` foi chamado (via network tab).

**Criterios:**
- Draft removido apos submit bem-sucedido

---

## Edge Cases

### E1: Formulario sem dados — save ignorado
- Abrir formulario sem preencher nada
- Auto-save nao deve disparar (nao ha dados)
- Clicar "Salvar Rascunho" nao deve criar draft vazio

### E2: Multiplas abas
- Abrir mesmo formulario em 2 abas
- Preencher dados diferentes
- Salvar em ambas — devem criar 2 drafts separados (IDs diferentes)

### E3: Sessao expirada
- Salvar draft, esperar sessao expirar (ou limpar cookies)
- Tentar salvar novamente — deve retornar 401
- Verificar que status mostra "Erro ao salvar" (nao crash)

### E4: migrateLocalStorage
- Setar manualmente um localStorage draft:
  ```javascript
  localStorage.setItem('canvas_draft_SLUG_USERID', JSON.stringify({
      data: { campo: 'localStorage test' },
      pageNo: 0,
      timestamp: Date.now()
  }));
  ```
- Recarregar pagina
- Verificar que confirm dialog aparece
- Aceitar — dados devem ser restaurados
- Verificar que localStorage foi limpo
- Verificar que draft existe no servidor

---

## Estrutura dos Testes

```
tests/
  drafts/
    t1-ui-elements.spec.js
    t2-save-draft.spec.js
    t3-modal.spec.js
    t4-load-draft.spec.js
    t5-rename.spec.js
    t6-delete.spec.js
    t7-limit.spec.js
    t8-idor.spec.js
    t9-submit-cleanup.spec.js
    edge-cases.spec.js
```

## Prioridade de Execucao

1. **T1, T2, T3** — smoke test (se esses passam, o MVP funciona)
2. **T4, T5, T6** — CRUD completo
3. **T8** — seguranca (IDOR)
4. **T7, T9** — limites e integracao
5. **E1-E4** — edge cases

---

## Cleanup pos-teste

Apos os testes, limpar drafts criados:

```sql
DELETE FROM form_drafts WHERE label LIKE 'Draft %' OR label LIKE 'Teste%' OR label LIKE 'Meu rascunho%';
```

Ou via API durante os testes (deletar cada draft criado no `afterEach`).

---

**Claude — Executor Principal**
