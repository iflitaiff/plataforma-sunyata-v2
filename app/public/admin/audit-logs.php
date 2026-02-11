<?php
/**
 * Admin - Logs de Auditoria
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

// Filtros
$filter_action = $_GET['action'] ?? '';
$limit = 50;
$offset = (int)($_GET['page'] ?? 0) * $limit;

// Query base
$sql = "SELECT a.id, a.user_id, u.name as user_name, u.email, a.action,
               a.entity_type, a.entity_id, a.ip_address, a.details, a.created_at
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE 1=1";

$params = [];

if ($filter_action) {
    $sql .= " AND a.action = :action";
    $params['action'] = $filter_action;
}

$sql .= " ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset";
$params['limit'] = $limit;
$params['offset'] = $offset;

$logs = $db->fetchAll($sql, $params);

// Buscar ações únicas para o filtro
$actions = $db->fetchAll("SELECT DISTINCT action FROM audit_logs ORDER BY action");

$pageTitle = 'Logs de Auditoria - Admin';

// CORRIGIDO: inicializar $stats antes de usar
$stats = [];
$stats['pending_requests'] = $db->fetchOne("SELECT COUNT(*) as count FROM vertical_access_requests WHERE status = 'pending'")['count'];

// Include responsive header
include __DIR__ . '/../../src/views/admin-header.php';
?>
                <h1 class="mb-4">Logs de Auditoria</h1>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Ação</label>
                                <select class="form-select" name="action">
                                    <option value="">Todas</option>
                                    <?php foreach ($actions as $act): ?>
                                        <option value="<?= sanitize_output($act['action']) ?>" <?= $filter_action === $act['action'] ? 'selected' : '' ?>>
                                            <?= sanitize_output($act['action']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">Filtrar</button>
                                <a href="<?= BASE_URL ?>/admin/audit-logs.php" class="btn btn-secondary">Limpar</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Logs Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th class="d-none d-lg-table-cell" style="width: 60px;">ID</th>
                                        <th style="width: 140px;">Data/Hora</th>
                                        <th class="d-none d-md-table-cell" style="width: 200px;">Usuário</th>
                                        <th style="width: 180px;">Ação</th>
                                        <th class="d-none d-xl-table-cell" style="width: 150px;">Entidade</th>
                                        <th class="d-none d-lg-table-cell" style="width: 140px;">IP</th>
                                        <th style="width: 100px;">Detalhes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                Nenhum log encontrado
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td class="d-none d-lg-table-cell"><?= $log['id'] ?></td>
                                                <td>
                                                    <small><?= date('d/m H:i', strtotime($log['created_at'])) ?></small>
                                                    <?php if ($log['user_name']): ?>
                                                        <div class="d-md-none small text-muted"><?= sanitize_output($log['user_name']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-md-table-cell">
                                                    <?php if ($log['user_name']): ?>
                                                        <small>
                                                            <?= sanitize_output($log['user_name']) ?><br>
                                                            <span class="text-muted"><?= sanitize_output($log['email']) ?></span>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Sistema</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code class="small"><?= sanitize_output($log['action']) ?></code></td>
                                                <td class="d-none d-xl-table-cell">
                                                    <?php if ($log['entity_type']): ?>
                                                        <small><?= sanitize_output($log['entity_type']) ?> #<?= $log['entity_id'] ?></small>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-lg-table-cell">
                                                    <small title="<?= sanitize_output($log['ip_address'] ?? '-') ?>">
                                                        <?= sanitize_output($log['ip_address'] ?? '-') ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($log['details']): ?>
                                                        <button class="btn btn-sm btn-outline-primary"
                                                                onclick="showDetails(<?= $log['id'] ?>, <?= htmlspecialchars(json_encode($log['details']), ENT_QUOTES, 'UTF-8') ?>)">
                                                            <i class="bi bi-eye"></i> Ver
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="mt-3">
                    <nav>
                        <ul class="pagination justify-content-center">
                            <?php
                            $current_page = (int)($_GET['page'] ?? 0);
                            if ($current_page > 0):
                            ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $current_page - 1 ?><?= $filter_action ? '&action=' . urlencode($filter_action) : '' ?>">
                                        <span class="d-none d-sm-inline">Anterior</span>
                                        <span class="d-inline d-sm-none">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if (count($logs) === $limit): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $current_page + 1 ?><?= $filter_action ? '&action=' . urlencode($filter_action) : '' ?>">
                                        <span class="d-none d-sm-inline">Próxima</span>
                                        <span class="d-inline d-sm-none">&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>

<!-- Modal for log details -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalhes do Log #<span id="logId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="logDetails" style="background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
function showDetails(logId, details) {
    document.getElementById('logId').textContent = logId;

    // Try to parse as JSON for pretty formatting
    let formattedDetails;
    try {
        const parsed = typeof details === 'string' ? JSON.parse(details) : details;
        formattedDetails = JSON.stringify(parsed, null, 2);
    } catch (e) {
        // If not valid JSON, show as-is
        formattedDetails = details;
    }

    document.getElementById('logDetails').textContent = formattedDetails;

    // Show modal (Bootstrap 5)
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
