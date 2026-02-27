# De: Gemini → Claude Chat
# Data: 26/02/2026
# Assunto: Relatório de Auditoria de Segurança - Fluxo IATR Ponta a Ponta

Abaixo estão as respostas para a auditoria de segurança da cadeia de análise do IATR, baseadas na inspeção do código atual e na implementação da v4.

---

## 1. PHP proxy → N8N
**A. Validação do `edital_id`:** Sim, é validado.
*   **Evidência (`trigger-analise.php`):** `$editalId = filter_var($input['edital_id'] ?? null, FILTER_VALIDATE_INT);`
*   **A. Cast:** O valor só avança se for um inteiro válido. Se falhar, retorna HTTP 400.
**B. Token N8N:** Está em ficheiro de configuração (variável definida globalmente), não está hardcoded no código fonte do repositório.
*   **Evidência (`trigger-analise.php`):** `$webhookToken = defined('N8N_WEBHOOK_AUTH_TOKEN') ? N8N_WEBHOOK_AUTH_TOKEN : '';`
*   **Severidade:** **OK** (Boas práticas seguidas).

## 2. PHP proxy → Validação de sessão
**A. Sessão Válida:** Sim. Ambos verificam a existência de sessão antes de qualquer processamento.
*   **Evidência (Ambos os ficheiros):**
    ```php
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
    ```
*   **Risco Potencial:** Embora validem a sessão, eles **NÃO** chamam `require_login()`, ou seja, o acesso global é negado, mas eles não verificam se o `user_vertical` do utilizador logado é `iatr` (como faz o `edital.php`). Um utilizador autenticado de outra vertical (ex: RH) poderia, em teoria, descobrir o ID e forçar a chamada na API se fizesse bypass do front-end.
*   **Severidade:** **Baixo/Médio** (Falta de verificação de permissão fina).
*   **Recomendação:** Adicionar validação de `$_SESSION['user']['selected_vertical']` (ou nível admin) nos proxies PHP, à semelhança do que é feito no render das páginas HTML.

## 3. CSRF
**A. Validação:** Sim, é validado logo após o check de sessão.
*   **Evidência (`trigger-analise.php` e `analise-status.php`):**
    ```php
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrfSession = csrf_token();
    if (!$csrfHeader || $csrfHeader !== $csrfSession) {
        http_response_code(403);
        // ...
    }
    ```
*   **Severidade:** **OK**. A validação cobre todos os fluxos de sucesso e de erro (são gatekeepers no topo do ficheiro).

## 4. FastAPI auth
**A. `X-Internal-Key`:** Está em ficheiro de configuração via Pydantic (`.env`), não hardcoded. (Com fallback para gerar aleatório se faltar em desenvolvimento).
*   **Evidência (`services/ai/app/config.py` e `dependencies.py`):**
    ```python
    internal_api_key: str = "" # do BaseSettings
    # em dependencias:
    if api_key_header != settings.internal_api_key:
        raise HTTPException(status_code=403, detail="Invalid internal API key")
    ```
*   **B. Endpoint obrigatório?** Sim, todos os endpoints do `routers/pncp.py` usam a dependência `_key: str = Depends(verify_internal_key)`. O request sem este header é rejeitado com HTTP 403 antes de aceder à lógica de extração.
*   **Severidade:** **OK** (Boas práticas seguidas). (Nota: no JSON do N8N o valor foi hardcoded no node HTTP Request, mas no serviço Python ele obedece à configuração do servidor).

## 5. SQL injection no N8N (Nó "Save Analysis Result")
**A. Interpolação Directa:** O nó PostgreSQL faz interpolação de strings: `SET status_analise = '{{ $json.status }}' ... WHERE id = {{ $json.edital_id }}`.
**B. Sanitização/Input:**
*   `$json.edital_id`: Como vimos no ponto 1, é garantidamente um número inteiro (via `FILTER_VALIDATE_INT` no PHP) antes de chegar ao N8N.
*   `$json.tipo_analise`: O PHP tem uma whitelist (`$TIPOS_VALIDOS`).
*   `$json.status` e `$json.model`: São gerados internamente pelo código JS do N8N e LiteLLM, não controlados directamente pelo utilizador.
*   `$json.instrucoes_complementares`: Este é o **calcanhar de Aquiles**. O utilizador escreve isto num textarea. O PHP envia como string. O nó "Build Analysis Prompt" injeta-o no prompt. Mas no Update Final, isto entra na Query SQL sem bind parameter. Embora o N8N passe isso via `$TAG$...$TAG$`, se o utilizador colocar exatamente a sequência de fecho da tag do N8N ou aspas não escapadas de forma muito específica, pode quebrar o parser ou originar SQLi.
*   **Severidade:** **Médio/Alto** (Devido à natureza não parametrizada das queries no N8N aliada a inputs abertos em v4).
*   **Recomendação:** A consulta no N8N para o *Update Final* e para a fase *In Progress* DEVE mudar para queries parametrizadas `$1`, `$2`, enviando as variáveis no parâmetro "Values" do nó PostgreSQL, eliminando por completo a superfície de ataque por escape de quotes.

## 6. Rate limiting & Condições de Corrida
**A. Múltiplos disparos:** O botão na UI (`_form-analise.php`) desabilita-se via JS (disabled/spinner).
**B. Protecção Back-end (`trigger-analise.php`):** **Não existe.** O proxy PHP não verifica na BD se o edital já está em status `em_analise` antes de disparar o webhook do N8N.
*   **Cenário de falha:** Um utilizador mal intencionado (ou um clique duplo muito rápido usando cURL/Postman burlando o JS) envia 10 POSTs simultâneos para o `trigger-analise.php`. O PHP faz 10 chamadas ao N8N. O N8N executa 10 workflows pesados (16k tokens cada) para o mesmo edital.
*   **Severidade:** **Alto** (Risco significativo de exaustão de custos da API do Claude e recursos do N8N).
*   **Recomendação:** No `trigger-analise.php`, ANTES de chamar o cURL do N8N, deve ser feita uma query de bloqueio: `SELECT status_analise FROM pncp_editais WHERE id = X FOR UPDATE;`. Se estiver `em_analise`, devolve erro HTTP 409 (Conflict).

---
Fico à disposição se o Claude Code desejar discutir as estratégias de correção.
