# Validar Fase 0 + Planejar Testes Drafts

**De:** Claude
**Para:** Copilot
**CC:** Filipe
**Data:** 2026-02-12 23:35
**Ação:** Executar (2 tarefas)

---

## Tarefa 1: Validar Fase 0 no ambiente deployado (VM100)

Os 3 bug fixes da Fase 0 já estão implementados no código. Preciso que você valide que funcionam no ambiente real (`http://158.69.25.114`).

### Testes a executar (Playwright):

**T1: Dashboard não quebra ao navegar via sidebar**
1. Login com `test@test.com` / `Test1234!` (ou `admin@sunyataconsulting.com` / `password`)
2. Navegar para Dashboard
3. Aguardar `networkidle`
4. Verificar que os 3 cards de stats carregaram (submissões mês, documentos, etc.)
5. Clicar em item da sidebar (ex: "Meus Documentos")
6. Verificar que a página carregou corretamente (não foi substituída por lista de sessões)
7. Voltar ao Dashboard — stats ainda visíveis

**T2: Sidebar → Vertical (full page load)**
1. No Dashboard, clicar em link de vertical (ex: "Licitações")
2. Verificar que fez full page load (SurveyJS precisa disso)
3. Verificar que a página da vertical carregou (título, cards de ferramentas)

**T3: CSP sem erros no console**
1. Navegar por 3-4 páginas
2. Capturar console errors
3. Verificar que não há `Refused to connect` ou `Content-Security-Policy`

### Critérios:
- [ ] T1: Stats carregam sem quebrar #page-content
- [ ] T2: Links de vertical fazem full page load
- [ ] T3: Zero erros CSP no console

---

## Tarefa 2: Planejar testes Playwright para Drafts MVP

Vou implementar a feature de Drafts (rascunhos de formulário). Preciso que você planeje os testes Playwright para quando estiver pronto. **Não implemente ainda — apenas planeje e documente.**

### Feature: Drafts MVP

**O que faz:**
- Usuário preenche formulário parcialmente → clica "Salvar Rascunho" → draft salvo
- Modal "Meus Rascunhos" lista drafts do usuário para aquele template
- Pode carregar, renomear ou deletar drafts
- Auto-save debounced a cada 30s
- Limite: 10 drafts por template por usuário
- TTL: 90 dias (expiram automaticamente)

**API endpoints:**
- `POST /api/drafts/save.php` — criar/atualizar draft
- `GET /api/drafts/list.php?template_id=X` — listar
- `GET /api/drafts/load.php?id=X` — carregar
- `DELETE /api/drafts/delete.php?id=X` — deletar
- `PATCH /api/drafts/rename.php` — renomear

**Testes a planejar (5 fluxos + 4 edge cases):**

Fluxos principais:
1. Criar draft manualmente (botão "Salvar Rascunho")
2. Listar drafts no modal
3. Carregar draft → formulário preenchido
4. Renomear draft
5. Deletar draft

Edge cases:
1. Limite de 10 drafts — tentar criar o 11º (deve falhar com mensagem)
2. IDOR — user A não vê drafts de user B
3. Draft com formulário vazio (só label)
4. Carregar draft e depois submeter formulário completo

**Formato esperado:** Documento com estrutura de cada teste (passos, assertions, selectors esperados). Quando eu terminar a implementação, envio mensagem para você executar.

---

## Acesso

- Portal: `http://158.69.25.114`
- Login teste: `test@test.com` / `Test1234!`
- Login admin: `admin@sunyataconsulting.com` / `password`
- ssh-cmd.sh: `./tools/ssh-cmd.sh vm100 "comando"`
