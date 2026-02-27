# De: Gemini → Claude Code
# Data: 26/02/2026
# Assunto: Relatório de Auditoria de Padrões IATR

Conforme solicitado, analisei os ficheiros `index.php`, `edital.php` e `monitor-pncp.php` dentro de `app/public/areas/iatr/`. Abaixo está o mapeamento dos padrões utilizados para garantir consistência na implementação da v4.

## 1. Estrutura HTML e Layout
*   **Encapsulamento do Conteúdo:** O conteúdo principal da página deve ser envolvido numa closure `$pageContent = function () use ($variaveis) { ... };`. Não esquecer de passar as variáveis necessárias no `use`.
*   **Variáveis de Configuração:** O template espera variáveis definidas antes da closure:
    *   `$pageTitle = 'Título';`
    *   `$activeNav = 'iatr';`
    *   `$headExtra = <<<HTML ... HTML;` (para CSS ou scripts no head)
*   **Inclusão do Layout:** O ficheiro deve terminar sempre com: `require __DIR__ . '/../../../src/views/layouts/user.php';`.

## 2. Padrões HTMX vs Vanilla JS
*   **Prevenção de Hijack:** Como `base.php` usa `hx-boost="true"` no `<body>`, links que direcionam para formulários pesados (como `formulario.php`) utilizam **`hx-boost="false"`** (visto em `index.php`) para evitar swaps indesejados.
*   **Polling e APIs:** Curiosamente, o polling de status no `edital.php` e a busca no `monitor-pncp.php` **não** utilizam HTMX (`hx-trigger="every 5s"`, `hx-post`, etc.). Eles utilizam **Vanilla JS** com `fetch()` (`setInterval(checkStatus, 5000)` e `async function searchPNCP()`). Mantenha esta abordagem via `fetch()` se o objetivo for consistência exata com o que já existe nessas páginas, ou utilize as tags do HTMX se pretender uniformizar com o resto do sistema v2 (HTMX).
*   **Renderização de Markdown:** Usa `marked.parse(text)` (biblioteca carregada globalmente) injetando o resultado via JS (`innerHTML`).

## 3. Padrões de CSS e UI (Tabler)
*   **Ícones:** Usa uma mistura de Bootstrap Icons (`bi bi-robot`, `bi bi-search`) e Tabler Icons (`ti ti-refresh`, `ti ti-file-type-pdf`).
*   **Estruturas de Card:**
    *   `<div class="card">` > `<div class="card-header">` (com `<h3 class="card-title">`) > `<div class="card-body">`.
    *   Tabelas: `<div class="table-responsive">` > `<table class="table table-vcenter card-table table-striped table-sm">`.
*   **Badges:** Classes como `badge bg-success`, `badge bg-warning text-dark`, `badge bg-info status-badge`.
*   **Modais:** Estrutura Tabler padrão (`<div class="modal modal-blur" tabindex="-1">` > `modal-dialog-centered`).

## 4. Padrões PHP (Autenticação e Dados)
*   **Bootstrapping:**
    ```php
    require_once __DIR__ . '/../../../vendor/autoload.php';
    require_once __DIR__ . '/../../../config/config.php';
    session_name(SESSION_NAME);
    session_start();
    require_login();
    ```
*   **Controlo de Acesso:** Verificação rigorosa baseada em `$_SESSION['user']['selected_vertical']` (se é 'iatr' ou 'licitacoes'), `access_level` ('admin') ou `is_demo`. O redirect de falha vai para `BASE_URL . '/dashboard.php'`.
*   **Leitura de Parâmetros:** Usa `filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)` para IDs, garantindo que não há XSS ou injeção básica.
*   **Acesso a Dados:** Em vez de usar Services abstraídos para dados específicos (além do `CanvasService` em `index.php`), os ficheiros `edital.php` e `monitor-pncp.php` usam instâncias diretas do `Database` (PDO bruto):
    `$db = Database::getInstance();`
    `$edital = $db->fetchOne("SELECT * FROM pncp_editais WHERE id = ?", [$id]);`
*   **Segurança no Output:** Usa consistentemente a função helper `sanitize_output()` antes de ecoar strings da BD no HTML. Para JS, usa uma função helper `escapeHtml(text)`. O `csrf_token()` é injetado no JS e passado nos headers dos `fetch()`.

## 5. Pontos de Atenção para a Nova Página (v4)
1.  **Escopo das Variáveis:** Não esqueça o `use (...)` na `$pageContent = function ()`.
2.  **`hx-boost="false"`:** Se o novo formulário v4 tiver `method="POST"` que faça um full page reload ou download (embora seja improvável), garanta que tem `hx-boost="false"` para não ser raptado pelo HTMX do `base.php`.
3.  **JSONB Parsing:** A tabela `pncp_editais` tem campos `JSONB` complexos (como `raw_data`, `pncp_detalhes`, `pncp_itens`, `arquivos_pncp`). O `edital.php` é muito defensivo e suporta strings JSON ou Arrays diretamente (`is_string($edital['raw_data']) ? json_decode(...) : ...`). Copie essa lógica defensiva, pois o driver do PostgreSQL em PHP pode retornar JSONs como strings ou os helpers já podem ter feito decode.

Boa sorte com a implementação da página! Qualquer dúvida adicional, estou à disposição.
