<?php
/**
 * Admin - Histórico de Prompts (Enhanced)
 * Visualizar todos os prompts com system prompt hierarchy e UI melhorada
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Helpers\ClaudeFacade;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

// Parâmetros de filtro
$filters = [
    'user_id' => $_GET['user_id'] ?? null,
    'vertical' => $_GET['vertical'] ?? null,
    'tool_name' => $_GET['tool_name'] ?? null,
    'status' => $_GET['status'] ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to' => $_GET['date_to'] ?? null,
    'search' => $_GET['search'] ?? null
];

// Paginação
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Construir query
$where = [];
$params = [];

if ($filters['user_id']) {
    $where[] = "ph.user_id = :user_id";
    $params['user_id'] = $filters['user_id'];
}

if ($filters['vertical']) {
    $where[] = "ph.vertical = :vertical";
    $params['vertical'] = $filters['vertical'];
}

if ($filters['tool_name']) {
    $where[] = "ph.tool_name LIKE :tool_name";
    $params['tool_name'] = '%' . $filters['tool_name'] . '%';
}

if ($filters['status']) {
    $where[] = "ph.status = :status";
    $params['status'] = $filters['status'];
}

if ($filters['date_from']) {
    $where[] = "ph.created_at::date >= :date_from";
    $params['date_from'] = $filters['date_from'];
}

if ($filters['date_to']) {
    $where[] = "ph.created_at::date <= :date_to";
    $params['date_to'] = $filters['date_to'];
}

if ($filters['search']) {
    $where[] = "(u.name LIKE :search OR u.email LIKE :search)";
    $params['search'] = '%' . $filters['search'] . '%';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Total de registros
$totalQuery = "
    SELECT COUNT(*) as total
    FROM prompt_history ph
    LEFT JOIN users u ON ph.user_id = u.id
    {$whereClause}
";
$totalResult = $db->fetchOne($totalQuery, $params);
$total = $totalResult['total'] ?? 0;
$totalPages = ceil($total / $perPage);

// Buscar histórico com JOIN para canvas_template
$query = "
    SELECT
        ph.*,
        u.name as user_name,
        u.email as user_email,
        ct.id as canvas_template_id,
        ct.name as canvas_template_name,
        ct.form_config as canvas_form_config
    FROM prompt_history ph
    LEFT JOIN users u ON ph.user_id = u.id
    LEFT JOIN canvas_templates ct ON ct.slug = ph.tool_name
    {$whereClause}
    ORDER BY ph.created_at DESC
    LIMIT :limit OFFSET :offset
";

$params['limit'] = $perPage;
$params['offset'] = $offset;

$history = $db->fetchAll($query, $params);

// Enriquecer com system prompt hierarchy
foreach ($history as &$item) {
    // PRIORIDADE: Usar system_prompt_sent (o que foi REALMENTE enviado à API)
    // Fallback: Reconstruir hierarquia atual (para registros antigos sem system_prompt_sent)
    if (!empty($item['system_prompt_sent'])) {
        $item['system_prompt_full'] = $item['system_prompt_sent'];
        $item['system_prompt_source'] = 'database'; // Fonte: banco de dados (histórico real)
    } elseif ($item['canvas_template_id'] && $item['vertical']) {
        try {
            // Fallback: Reconstruir hierarquia atual (pode estar desatualizada!)
            $breakdown = ClaudeFacade::debugSystemPromptHierarchy(
                $item['vertical'],
                $item['canvas_template_id']
            );
            $item['system_prompt_full'] = $breakdown['final_concatenado'];
            $item['system_prompt_breakdown'] = $breakdown;
            $item['system_prompt_source'] = 'reconstructed'; // Fonte: reconstruído (pode divergir)
        } catch (Exception $e) {
            $item['system_prompt_full'] = null;
            $item['system_prompt_breakdown'] = null;
            $item['system_prompt_source'] = 'unavailable';
        }
    }

    // Buscar breakdown atual para comparação (sempre)
    if ($item['canvas_template_id'] && $item['vertical']) {
        try {
            $item['system_prompt_breakdown'] = ClaudeFacade::debugSystemPromptHierarchy(
                $item['vertical'],
                $item['canvas_template_id']
            );

            // Extrair ajSystemPrompt do JSON
            if ($item['canvas_form_config']) {
                $formConfig = json_decode($item['canvas_form_config'], true);
                $item['aj_system_prompt'] = $formConfig['ajSystemPrompt'] ?? null;
            }
        } catch (Exception $e) {
            $item['system_prompt_breakdown'] = null;
        }
    }
}

// Obter listas para filtros
$users = $db->fetchAll("
    SELECT DISTINCT u.id, u.name, u.email
    FROM users u
    INNER JOIN prompt_history ph ON u.id = ph.user_id
    ORDER BY u.name
");

$verticals = $db->fetchAll("
    SELECT DISTINCT vertical
    FROM prompt_history
    WHERE vertical IS NOT NULL
    ORDER BY vertical
");

$tools = $db->fetchAll("
    SELECT DISTINCT tool_name
    FROM prompt_history
    WHERE tool_name IS NOT NULL
    ORDER BY tool_name
");

$pageTitle = 'Histórico de Prompts';

// Include responsive header
include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
/* Melhorias de UI */
.prompt-section-pre {
    white-space: pre-wrap !important;
    word-wrap: break-word !important;
    word-break: break-word !important;
    max-height: 500px;
    overflow-y: auto;
    font-size: 0.85rem;
    background-color: #ffffff;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    padding: 1rem;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.copy-btn {
    opacity: 0.7;
    transition: opacity 0.2s;
}

.copy-btn:hover {
    opacity: 1;
}

.hierarchy-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

.accordion-button:not(.collapsed) {
    background-color: #e7f1ff;
    color: #0d6efd;
}

.metadata-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.metadata-item {
    padding: 0.75rem;
    background: #f8f9fa;
    border-radius: 0.375rem;
}

.metadata-item strong {
    display: block;
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 0.25rem;
}
</style>

<h1 class="mb-4">📊 Histórico de Prompts</h1>

<!-- Filtros (mantém igual) -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-funnel"></i> Filtros</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <!-- Busca por nome/email -->
            <div class="col-md-3">
                <label for="search" class="form-label">Buscar Usuário</label>
                <input type="text"
                       class="form-control"
                       id="search"
                       name="search"
                       placeholder="Nome ou email..."
                       value="<?= sanitize_output($filters['search'] ?? '') ?>">
            </div>

            <!-- Usuário -->
            <div class="col-md-3">
                <label for="user_id" class="form-label">Usuário</label>
                <select class="form-select" id="user_id" name="user_id">
                    <option value="">Todos</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>"
                                <?= ($filters['user_id'] == $user['id']) ? 'selected' : '' ?>>
                            <?= sanitize_output($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Vertical -->
            <div class="col-md-2">
                <label for="vertical" class="form-label">Vertical</label>
                <select class="form-select" id="vertical" name="vertical">
                    <option value="">Todas</option>
                    <?php foreach ($verticals as $v): ?>
                        <option value="<?= $v['vertical'] ?>"
                                <?= ($filters['vertical'] == $v['vertical']) ? 'selected' : '' ?>>
                            <?= sanitize_output($v['vertical']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Canvas/Tool -->
            <div class="col-md-2">
                <label for="tool_name" class="form-label">Canvas</label>
                <select class="form-select" id="tool_name" name="tool_name">
                    <option value="">Todos</option>
                    <?php foreach ($tools as $tool): ?>
                        <option value="<?= $tool['tool_name'] ?>"
                                <?= ($filters['tool_name'] == $tool['tool_name']) ? 'selected' : '' ?>>
                            <?= sanitize_output($tool['tool_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status -->
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Todos</option>
                    <option value="success" <?= ($filters['status'] == 'success') ? 'selected' : '' ?>>Sucesso</option>
                    <option value="error" <?= ($filters['status'] == 'error') ? 'selected' : '' ?>>Erro</option>
                    <option value="pending" <?= ($filters['status'] == 'pending') ? 'selected' : '' ?>>Pendente</option>
                </select>
            </div>

            <!-- Data início -->
            <div class="col-md-2">
                <label for="date_from" class="form-label">Data Início</label>
                <input type="date"
                       class="form-control"
                       id="date_from"
                       name="date_from"
                       value="<?= sanitize_output($filters['date_from'] ?? '') ?>">
            </div>

            <!-- Data fim -->
            <div class="col-md-2">
                <label for="date_to" class="form-label">Data Fim</label>
                <input type="date"
                       class="form-control"
                       id="date_to"
                       name="date_to"
                       value="<?= sanitize_output($filters['date_to'] ?? '') ?>">
            </div>

            <!-- Botões -->
            <div class="col-md-8 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search"></i> Filtrar
                </button>
                <a href="prompt-history-enhanced.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Limpar
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Estatísticas -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="alert alert-info">
            <strong>Total de registros:</strong> <?= number_format($total) ?> prompts
            <?php if (!empty($filters['user_id']) || !empty($filters['vertical']) || !empty($filters['tool_name'])): ?>
                | <strong>Filtros ativos</strong>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Lista de Histórico -->
<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Histórico (Página <?= $page ?> de <?= max(1, $totalPages) ?>)</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($history)): ?>
            <div class="alert alert-warning m-3">
                Nenhum registro encontrado com os filtros aplicados.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>Usuário</th>
                            <th>Vertical</th>
                            <th>Canvas</th>
                            <th>Status</th>
                            <th>Tokens</th>
                            <th>Custo</th>
                            <th>Data</th>
                            <th style="width: 80px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $item): ?>
                            <tr>
                                <td><code><?= $item['id'] ?></code></td>
                                <td>
                                    <strong><?= sanitize_output($item['user_name'] ?? 'N/A') ?></strong><br>
                                    <small class="text-muted"><?= sanitize_output($item['user_email'] ?? 'N/A') ?></small>
                                </td>
                                <td><span class="badge bg-secondary"><?= sanitize_output($item['vertical']) ?></span></td>
                                <td>
                                    <?= sanitize_output($item['tool_name']) ?>
                                    <?php if ($item['canvas_template_id']): ?>
                                        <br><small class="text-muted">ID: <?= $item['canvas_template_id'] ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['status'] == 'success'): ?>
                                        <span class="badge bg-success">✓ Sucesso</span>
                                    <?php elseif ($item['status'] == 'error'): ?>
                                        <span class="badge bg-danger">✗ Erro</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">⏳ Pendente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>
                                        <?= number_format($item['tokens_total'] ?? 0) ?>
                                        <span class="text-muted">(<?= number_format($item['tokens_input'] ?? 0) ?>↑ / <?= number_format($item['tokens_output'] ?? 0) ?>↓)</span>
                                    </small>
                                </td>
                                <td>
                                    <small>$<?= number_format($item['cost_usd'] ?? 0, 4) ?></small>
                                </td>
                                <td>
                                    <small><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></small>
                                </td>
                                <td>
                                    <button type="button"
                                            class="btn btn-sm btn-primary"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#details-<?= $item['id'] ?>">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <!-- Detalhes expandíveis com ACCORDION -->
                            <tr class="collapse" id="details-<?= $item['id'] ?>">
                                <td colspan="9" class="bg-light p-0">
                                    <div class="accordion" id="accordion-<?= $item['id'] ?>">

                                        <!-- Seção 1: Input Data -->
                                        <div class="accordion-item">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#input-<?= $item['id'] ?>">
                                                    📝 Input Data (Formulário) - <?= number_format(strlen($item['input_data'])) ?> chars
                                                </button>
                                            </h2>
                                            <div id="input-<?= $item['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#accordion-<?= $item['id'] ?>">
                                                <div class="accordion-body">
                                                    <div class="section-header">
                                                        <small class="text-muted">Dados do formulário enviados pelo usuário</small>
                                                        <button class="btn btn-sm btn-outline-secondary copy-btn" onclick="copyToClipboard('input-<?= $item['id'] ?>-content')">
                                                            <i class="bi bi-clipboard"></i> Copy
                                                        </button>
                                                    </div>
                                                    <pre class="prompt-section-pre" id="input-<?= $item['id'] ?>-content"><?= sanitize_output($item['input_data']) ?></pre>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Seção 2: Prompt Sent -->
                                        <div class="accordion-item">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#prompt-<?= $item['id'] ?>">
                                                    🤖 Prompt Enviado ao Claude - <?= number_format(strlen($item['generated_prompt'])) ?> chars
                                                </button>
                                            </h2>
                                            <div id="prompt-<?= $item['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#accordion-<?= $item['id'] ?>">
                                                <div class="accordion-body">
                                                    <div class="section-header">
                                                        <small class="text-muted">Prompt final gerado (user_prompt_template preenchido)</small>
                                                        <button class="btn btn-sm btn-outline-secondary copy-btn" onclick="copyToClipboard('prompt-<?= $item['id'] ?>-content')">
                                                            <i class="bi bi-clipboard"></i> Copy
                                                        </button>
                                                    </div>
                                                    <pre class="prompt-section-pre" id="prompt-<?= $item['id'] ?>-content"><?= sanitize_output($item['generated_prompt']) ?></pre>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Seção 3: System Prompt ENVIADO -->
                                        <?php if (!empty($item['system_prompt_full'])): ?>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#system-<?= $item['id'] ?>">
                                                    ⚙️ System Prompt <?= ($item['system_prompt_source'] ?? '') === 'database' ? '(ENVIADO À API)' : '(Reconstruído)' ?> - <?= number_format(strlen($item['system_prompt_full'])) ?> chars
                                                    <?php if (($item['system_prompt_source'] ?? '') === 'database'): ?>
                                                        <span class="badge bg-success ms-2">Histórico Real</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning ms-2">Reconstruído</span>
                                                    <?php endif; ?>
                                                </button>
                                            </h2>
                                            <div id="system-<?= $item['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#accordion-<?= $item['id'] ?>">
                                                <div class="accordion-body">
                                                    <?php if (($item['system_prompt_source'] ?? '') === 'reconstructed'): ?>
                                                    <div class="alert alert-warning mb-3">
                                                        <i class="bi bi-exclamation-triangle"></i> <strong>Atenção:</strong> Este system prompt foi reconstruído a partir da configuração ATUAL do template. Se o template foi editado após este registro, o valor pode diferir do que foi realmente enviado à API.
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="section-header">
                                                        <small class="text-muted">
                                                            <?= ($item['system_prompt_source'] ?? '') === 'database'
                                                                ? 'System prompt exatamente como foi enviado à API Claude'
                                                                : 'System prompt reconstruído (Vertical + Canvas + ajSystemPrompt)' ?>
                                                        </small>
                                                        <div>
                                                            <?php if ($item['system_prompt_breakdown']): ?>
                                                            <button class="btn btn-sm btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#hierarchy-modal-<?= $item['id'] ?>">
                                                                <i class="bi bi-diagram-3"></i> Ver Breakdown Atual
                                                            </button>
                                                            <?php endif; ?>
                                                            <button class="btn btn-sm btn-outline-secondary copy-btn" onclick="copyToClipboard('system-<?= $item['id'] ?>-content')">
                                                                <i class="bi bi-clipboard"></i> Copy
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <pre class="prompt-section-pre" id="system-<?= $item['id'] ?>-content"><?= sanitize_output($item['system_prompt_full']) ?></pre>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Seção 4: Claude Response -->
                                        <?php if ($item['claude_response']): ?>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#response-<?= $item['id'] ?>">
                                                    💬 Resposta do Claude - <?= number_format(strlen($item['claude_response'])) ?> chars
                                                </button>
                                            </h2>
                                            <div id="response-<?= $item['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#accordion-<?= $item['id'] ?>">
                                                <div class="accordion-body">
                                                    <div class="section-header">
                                                        <small class="text-muted">Resposta gerada pela API Claude</small>
                                                        <button class="btn btn-sm btn-outline-secondary copy-btn" onclick="copyToClipboard('response-<?= $item['id'] ?>-content')">
                                                            <i class="bi bi-clipboard"></i> Copy
                                                        </button>
                                                    </div>
                                                    <pre class="prompt-section-pre" id="response-<?= $item['id'] ?>-content"><?= sanitize_output($item['claude_response']) ?></pre>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Seção 5: Error (se houver) -->
                                        <?php if ($item['error_message']): ?>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed bg-danger text-white" type="button" data-bs-toggle="collapse" data-bs-target="#error-<?= $item['id'] ?>">
                                                    ❌ Mensagem de Erro
                                                </button>
                                            </h2>
                                            <div id="error-<?= $item['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#accordion-<?= $item['id'] ?>">
                                                <div class="accordion-body">
                                                    <div class="alert alert-danger mb-0">
                                                        <?= sanitize_output($item['error_message']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Seção 6: Metadata & Debug -->
                                        <div class="accordion-item">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#metadata-<?= $item['id'] ?>">
                                                    🔍 Metadata & Debug Info
                                                </button>
                                            </h2>
                                            <div id="metadata-<?= $item['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#accordion-<?= $item['id'] ?>">
                                                <div class="accordion-body">
                                                    <div class="metadata-grid">
                                                        <div class="metadata-item">
                                                            <strong>Modelo Claude</strong>
                                                            <code><?= sanitize_output($item['claude_model'] ?? 'N/A') ?></code>
                                                        </div>
                                                        <div class="metadata-item">
                                                            <strong>Temperature</strong>
                                                            <code><?= isset($item['temperature']) ? number_format($item['temperature'], 2) : 'N/A' ?></code>
                                                        </div>
                                                        <div class="metadata-item">
                                                            <strong>Max Tokens</strong>
                                                            <code><?= isset($item['max_tokens']) ? number_format($item['max_tokens']) : 'N/A' ?></code>
                                                        </div>
                                                        <div class="metadata-item">
                                                            <strong>Top P</strong>
                                                            <code><?= isset($item['top_p']) && $item['top_p'] !== null ? number_format($item['top_p'], 2) : 'N/A' ?></code>
                                                        </div>
                                                        <div class="metadata-item">
                                                            <strong>Tempo de Resposta</strong>
                                                            <?= $item['response_time_ms'] ?? 'N/A' ?>ms
                                                        </div>
                                                        <div class="metadata-item">
                                                            <strong>Canvas Template ID</strong>
                                                            <?php if ($item['canvas_template_id']): ?>
                                                                <a href="<?= BASE_URL ?>/admin/canvas-editor.php?id=<?= $item['canvas_template_id'] ?>" target="_blank">
                                                                    #<?= $item['canvas_template_id'] ?> <i class="bi bi-box-arrow-up-right"></i>
                                                                </a>
                                                            <?php else: ?>
                                                                N/A
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="metadata-item">
                                                            <strong>IP Address</strong>
                                                            <?= sanitize_output($item['ip_address'] ?? 'N/A') ?>
                                                        </div>
                                                        <div class="metadata-item">
                                                            <strong>User Agent</strong>
                                                            <small><?= sanitize_output(substr($item['user_agent'] ?? 'N/A', 0, 80)) ?>...</small>
                                                        </div>
                                                        <div class="metadata-item">
                                                            <strong>Timestamp</strong>
                                                            <?= $item['created_at'] ?>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($item['system_prompt_sent'])): ?>
                                                    <div class="mt-3">
                                                        <strong class="d-block mb-2">System Prompt Enviado (<?= number_format(strlen($item['system_prompt_sent'])) ?> chars)</strong>
                                                        <pre class="prompt-section-pre" style="max-height: 200px; font-size: 0.8rem;"><?= sanitize_output(substr($item['system_prompt_sent'], 0, 2000)) ?><?= strlen($item['system_prompt_sent']) > 2000 ? '...[truncado]' : '' ?></pre>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Seção 7: API Debug View (Request + Response JSON) -->
                                        <div class="accordion-item">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#api-debug-<?= $item['id'] ?>">
                                                    📡 API Debug View (Request + Response JSON)
                                                </button>
                                            </h2>
                                            <div id="api-debug-<?= $item['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#accordion-<?= $item['id'] ?>">
                                                <div class="accordion-body">

                                                    <!-- Request JSON -->
                                                    <div class="mb-4">
                                                        <div class="section-header">
                                                            <h6 class="text-primary mb-2">📤 REQUEST enviado para Claude API</h6>
                                                            <button class="btn btn-sm btn-outline-secondary copy-btn" onclick="copyToClipboard('api-request-<?= $item['id'] ?>-content')">
                                                                <i class="bi bi-clipboard"></i> Copy
                                                            </button>
                                                        </div>
                                                        <pre class="prompt-section-pre" id="api-request-<?= $item['id'] ?>-content"><?php
                                                        // Montar Request JSON dinamicamente
                                                        $requestJson = [
                                                            'model' => $item['claude_model'] ?? 'N/A',
                                                            'max_tokens' => $item['max_tokens'] ?? 'N/A',
                                                            'temperature' => $item['temperature'] ?? 'N/A'
                                                        ];

                                                        if ($item['top_p']) {
                                                            $requestJson['top_p'] = $item['top_p'];
                                                        }

                                                        if ($item['system_prompt_sent']) {
                                                            $requestJson['system'] = $item['system_prompt_sent'];
                                                        }

                                                        $requestJson['messages'] = [
                                                            [
                                                                'role' => 'user',
                                                                'content' => $item['generated_prompt']
                                                            ]
                                                        ];

                                                        echo sanitize_output(json_encode($requestJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                                        ?></pre>
                                                    </div>

                                                    <hr>

                                                    <!-- Response JSON -->
                                                    <?php if ($item['claude_response']): ?>
                                                    <div>
                                                        <div class="section-header">
                                                            <h6 class="text-success mb-2">📥 RESPONSE recebido da Claude API</h6>
                                                            <button class="btn btn-sm btn-outline-secondary copy-btn" onclick="copyToClipboard('api-response-<?= $item['id'] ?>-content')">
                                                                <i class="bi bi-clipboard"></i> Copy
                                                            </button>
                                                        </div>
                                                        <pre class="prompt-section-pre" id="api-response-<?= $item['id'] ?>-content"><?php
                                                        // Montar Response JSON dinamicamente
                                                        $responseJson = [
                                                            'id' => 'msg_' . substr(md5($item['id'] . $item['created_at']), 0, 20),
                                                            'type' => 'message',
                                                            'role' => 'assistant',
                                                            'content' => [
                                                                [
                                                                    'type' => 'text',
                                                                    'text' => $item['claude_response']
                                                                ]
                                                            ],
                                                            'model' => $item['claude_model'] ?? 'N/A',
                                                            'stop_reason' => 'end_turn',
                                                            'usage' => [
                                                                'input_tokens' => $item['tokens_input'] ?? 0,
                                                                'output_tokens' => $item['tokens_output'] ?? 0
                                                            ]
                                                        ];

                                                        echo sanitize_output(json_encode($responseJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                                        ?></pre>
                                                    </div>
                                                    <?php endif; ?>

                                                </div>
                                            </div>
                                        </div>

                                    </div><!-- /accordion -->
                                </td>
                            </tr>

                            <!-- Modal: Hierarchy Breakdown -->
                            <?php if (!empty($item['system_prompt_breakdown'])): ?>
                            <div class="modal fade" id="hierarchy-modal-<?= $item['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title">🔍 Breakdown: System Prompt Hierarchy - ID #<?= $item['id'] ?></h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <!-- Nível 1 -->
                                            <div class="mb-4">
                                                <h6 class="text-primary">📍 NÍVEL 1: Vertical (<?= strlen($item['system_prompt_breakdown']['nivel1_vertical']) ?> chars)</h6>
                                                <small class="text-muted">Prompt base da vertical (config/verticals.php + overrides)</small>
                                                <pre class="prompt-section-pre mt-2" style="max-height: 200px;"><?= sanitize_output($item['system_prompt_breakdown']['nivel1_vertical']) ?></pre>
                                            </div>

                                            <!-- Nível 2 -->
                                            <div class="mb-4">
                                                <h6 class="text-success">📍 NÍVEL 2: Canvas Template (<?= strlen($item['system_prompt_breakdown']['nivel2_canvas_template']) ?> chars)</h6>
                                                <small class="text-muted">Prompt específico do canvas (canvas_templates.system_prompt)</small>
                                                <pre class="prompt-section-pre mt-2" style="max-height: 200px;"><?= sanitize_output($item['system_prompt_breakdown']['nivel2_canvas_template']) ?></pre>
                                            </div>

                                            <!-- Nível 3 -->
                                            <div class="mb-4">
                                                <h6 class="text-warning">📍 NÍVEL 3: ajSystemPrompt (<?= strlen($item['system_prompt_breakdown']['nivel3_form_ajSystemPrompt']) ?> chars)</h6>
                                                <small class="text-muted">Instruções do formulário (form_config JSON)</small>
                                                <pre class="prompt-section-pre mt-2" style="max-height: 200px;"><?= sanitize_output($item['system_prompt_breakdown']['nivel3_form_ajSystemPrompt']) ?></pre>
                                            </div>

                                            <!-- Total -->
                                            <div class="alert alert-info">
                                                <strong>Total Concatenado:</strong> <?= number_format(strlen($item['system_prompt_full'])) ?> chars
                                                <span class="badge bg-info ms-2">
                                                    <?= strlen($item['system_prompt_breakdown']['nivel1_vertical']) ?> +
                                                    <?= strlen($item['system_prompt_breakdown']['nivel2_canvas_template']) ?> +
                                                    <?= strlen($item['system_prompt_breakdown']['nivel3_form_ajSystemPrompt']) ?> =
                                                    <?= strlen($item['system_prompt_full']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Paginação (mantém igual) -->
<?php if ($totalPages > 1): ?>
    <nav aria-label="Paginação" class="mt-4">
        <ul class="pagination justify-content-center">
            <!-- Anterior -->
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?><?= http_build_query(array_filter($filters)) ? '&' . http_build_query(array_filter($filters)) : '' ?>">
                    ← Anterior
                </a>
            </li>

            <!-- Páginas -->
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?><?= http_build_query(array_filter($filters)) ? '&' . http_build_query(array_filter($filters)) : '' ?>">
                        <?= $i ?>
                    </a>
                </li>
            <?php endfor; ?>

            <!-- Próxima -->
            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?><?= http_build_query(array_filter($filters)) ? '&' . http_build_query(array_filter($filters)) : '' ?>">
                    Próxima →
                </a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<script>
// Copy to clipboard function
function copyToClipboard(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;

    const text = element.textContent;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            // Feedback visual
            const btn = event.target.closest('button');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check"></i> Copiado!';
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-success');

            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-secondary');
            }, 2000);
        }).catch(err => {
            alert('Erro ao copiar: ' + err);
        });
    } else {
        // Fallback para browsers antigos
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            alert('Texto copiado!');
        } catch (err) {
            alert('Erro ao copiar');
        }

        document.body.removeChild(textarea);
    }
}
</script>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
