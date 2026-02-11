<?php
/**
 * Admin - Gerenciamento de Usuários (Enhanced)
 * REQ 5: Dados do usuário no admin + detalhes expandíveis
 * Updated: 2026-02-09
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Admin\UserDeletionService;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();
$deletionService = new UserDeletionService();

// Handle user deletion
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $message = 'Token de segurança inválido';
        $message_type = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $result = $deletionService->deleteUser($userId, $_SESSION['user_id']);

        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Carregar verticais disponíveis de config/verticals.php (filtro dinâmico)
$verticalsConfig = require __DIR__ . '/../../config/verticals.php';

// Filtros
$filter_level = $_GET['level'] ?? '';
$filter_vertical = $_GET['vertical'] ?? '';
$search = $_GET['search'] ?? '';

// Query base — JOIN com user_profiles para org
$sql = "SELECT u.id, u.name, u.email, u.access_level, u.selected_vertical,
               u.completed_onboarding, u.created_at, u.last_login,
               up.organization, up.phone, up.position, up.organization_size, up.area
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE 1=1";

$params = [];

if ($filter_level) {
    $sql .= " AND u.access_level = :level";
    $params['level'] = $filter_level;
}

if ($filter_vertical) {
    $sql .= " AND u.selected_vertical = :vertical";
    $params['vertical'] = $filter_vertical;
}

if ($search) {
    $sql .= " AND (u.name LIKE :search_name OR u.email LIKE :search_email)";
    $params['search_name'] = "%$search%";
    $params['search_email'] = "%$search%";
}

$sql .= " ORDER BY u.created_at DESC";

$users = $db->fetchAll($sql, $params);

// Bulk usage query — contar prompts dos últimos 30 dias para todos os users listados (evita N+1)
$userIds = array_column($users, 'id');
$usageCounts = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $usageRows = $db->fetchAll(
        "SELECT user_id, COUNT(*) as cnt
         FROM prompt_history
         WHERE user_id IN ($placeholders)
           AND status = 'success'
           AND created_at >= NOW() - INTERVAL '30 days'
         GROUP BY user_id",
        array_values($userIds)
    );
    foreach ($usageRows as $row) {
        $usageCounts[$row['user_id']] = $row['cnt'];
    }
}

// Buscar últimos access_requests por user (para detalhes expandíveis)
$accessRequests = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $arRows = $db->fetchAll(
        "SELECT var1.*
         FROM vertical_access_requests var1
         INNER JOIN (
             SELECT user_id, MAX(id) as max_id
             FROM vertical_access_requests
             GROUP BY user_id
         ) var2 ON var1.id = var2.max_id
         WHERE var1.user_id IN ($placeholders)",
        array_values($userIds)
    );
    foreach ($arRows as $row) {
        $accessRequests[$row['user_id']] = $row;
    }
}

// Estatísticas
$stats = [];
$totalResult = $db->fetchOne("SELECT COUNT(*) as total FROM users");
$stats['total'] = $totalResult['total'];
$stats['pending_requests'] = $db->fetchOne("SELECT COUNT(*) as count FROM vertical_access_requests WHERE status = 'pending'")['count'];

$pageTitle = 'Usuários - Admin';

// Include responsive header
include __DIR__ . '/../../src/views/admin-header.php';
?>
                <h1 class="mb-4">Gerenciamento de Usuários</h1>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
                        <?= sanitize_output($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="alert alert-info">
                    <strong>Total de Usuários:</strong> <?= $stats['total'] ?>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Pesquisar</label>
                                <input type="text" class="form-control" name="search" value="<?= sanitize_output($search) ?>" placeholder="Nome ou email">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nível</label>
                                <select class="form-select" name="level">
                                    <option value="">Todos</option>
                                    <option value="guest" <?= $filter_level === 'guest' ? 'selected' : '' ?>>Guest</option>
                                    <option value="student" <?= $filter_level === 'student' ? 'selected' : '' ?>>Student</option>
                                    <option value="client" <?= $filter_level === 'client' ? 'selected' : '' ?>>Client</option>
                                    <option value="admin" <?= $filter_level === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Vertical</label>
                                <select class="form-select" name="vertical">
                                    <option value="">Todas</option>
                                    <?php foreach ($verticalsConfig as $slug => $vConfig): ?>
                                        <?php if ($vConfig['disponivel']): ?>
                                        <option value="<?= $slug ?>" <?= $filter_vertical === $slug ? 'selected' : '' ?>>
                                            <?= sanitize_output($vConfig['nome']) ?>
                                        </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">Filtrar</button>
                                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary">Limpar</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="d-none d-lg-table-cell">ID</th>
                                        <th>Nome</th>
                                        <th class="d-none d-md-table-cell">Email</th>
                                        <th>Nível</th>
                                        <th class="d-none d-sm-table-cell">Vertical</th>
                                        <th class="d-none d-lg-table-cell">Org</th>
                                        <th class="d-none d-xl-table-cell">Cadastro</th>
                                        <th class="text-center">Uso 30d</th>
                                        <th class="text-center">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td class="d-none d-lg-table-cell"><?= $user['id'] ?></td>
                                            <td>
                                                <?= sanitize_output($user['name']) ?>
                                                <div class="d-md-none small text-muted"><?= sanitize_output($user['email']) ?></div>
                                            </td>
                                            <td class="d-none d-md-table-cell"><?= sanitize_output($user['email']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $user['access_level'] === 'admin' ? 'danger' : 'primary' ?>">
                                                    <?= ucfirst($user['access_level']) ?>
                                                </span>
                                            </td>
                                            <td class="d-none d-sm-table-cell">
                                                <?php if ($user['selected_vertical']): ?>
                                                    <span class="badge bg-secondary"><?= sanitize_output($user['selected_vertical']) ?></span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                <small><?= sanitize_output($user['organization'] ?? '-') ?></small>
                                            </td>
                                            <td class="d-none d-xl-table-cell"><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                                            <td class="text-center">
                                                <?php
                                                $usage = $usageCounts[$user['id']] ?? 0;
                                                if ($usage > 0):
                                                ?>
                                                    <span class="badge bg-success"><?= $usage ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button"
                                                        class="btn btn-outline-primary btn-sm"
                                                        data-bs-toggle="collapse"
                                                        data-bs-target="#details-<?= $user['id'] ?>"
                                                        title="Ver detalhes">
                                                    <i class="bi bi-chevron-down"></i>
                                                </button>
                                                <?php if ($user['access_level'] !== 'admin' && $user['id'] != $_SESSION['user_id']): ?>
                                                    <button type="button"
                                                            class="btn btn-danger btn-sm"
                                                            onclick="confirmDelete(<?= $user['id'] ?>, '<?= addslashes($user['name']) ?>')"
                                                            title="Deletar usuário">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <!-- Detalhes expandíveis -->
                                        <tr class="collapse" id="details-<?= $user['id'] ?>">
                                            <td colspan="9" class="bg-light">
                                                <div class="row p-2">
                                                    <!-- Perfil -->
                                                    <div class="col-md-4 mb-2">
                                                        <h6 class="text-primary"><i class="bi bi-person"></i> Perfil</h6>
                                                        <small>
                                                            <strong>Telefone:</strong> <?= sanitize_output($user['phone'] ?? '-') ?><br>
                                                            <strong>Cargo:</strong> <?= sanitize_output($user['position'] ?? '-') ?><br>
                                                            <strong>Organização:</strong> <?= sanitize_output($user['organization'] ?? '-') ?><br>
                                                            <strong>Porte:</strong> <?= sanitize_output($user['organization_size'] ?? '-') ?><br>
                                                            <strong>Área:</strong> <?= sanitize_output($user['area'] ?? '-') ?>
                                                        </small>
                                                    </div>

                                                    <!-- Access Request -->
                                                    <div class="col-md-4 mb-2">
                                                        <h6 class="text-primary"><i class="bi bi-key"></i> Solicitação de Acesso</h6>
                                                        <?php
                                                        $ar = $accessRequests[$user['id']] ?? null;
                                                        if ($ar):
                                                            $arData = json_decode($ar['request_data'] ?? '{}', true) ?: [];
                                                        ?>
                                                        <small>
                                                            <?php if (!empty($arData['profissao'])): ?>
                                                                <strong>Profissão:</strong> <?= sanitize_output($arData['profissao']) ?><br>
                                                            <?php endif; ?>
                                                            <?php if (!empty($arData['oab'])): ?>
                                                                <strong>OAB:</strong> <?= sanitize_output($arData['oab']) ?><br>
                                                            <?php endif; ?>
                                                            <?php if (!empty($arData['escritorio'])): ?>
                                                                <strong>Escritório:</strong> <?= sanitize_output($arData['escritorio']) ?><br>
                                                            <?php endif; ?>
                                                            <?php if (!empty($arData['motivo'])): ?>
                                                                <strong>Motivo:</strong> <?= sanitize_output($arData['motivo']) ?><br>
                                                            <?php endif; ?>
                                                            <strong>Status:</strong>
                                                            <span class="badge bg-<?= $ar['status'] === 'approved' ? 'success' : ($ar['status'] === 'pending' ? 'warning' : 'secondary') ?>">
                                                                <?= ucfirst($ar['status']) ?>
                                                            </span>
                                                        </small>
                                                        <?php else: ?>
                                                        <small class="text-muted">Sem solicitação registrada</small>
                                                        <?php endif; ?>
                                                    </div>

                                                    <!-- Info & Links -->
                                                    <div class="col-md-4 mb-2">
                                                        <h6 class="text-primary"><i class="bi bi-info-circle"></i> Info</h6>
                                                        <small>
                                                            <strong>Onboarding:</strong>
                                                            <?php if ($user['completed_onboarding']): ?>
                                                                <span class="badge bg-success">Completo</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning">Pendente</span>
                                                            <?php endif; ?>
                                                            <br>
                                                            <strong>Último login:</strong> <?= $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : '-' ?><br>
                                                            <strong>Uso (30 dias):</strong> <?= $usageCounts[$user['id']] ?? 0 ?> submissões
                                                        </small>
                                                        <div class="mt-2">
                                                            <a href="<?= BASE_URL ?>/admin/user-report.php?user_id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                                <i class="bi bi-bar-chart-line"></i> Ver Relatório de Uso
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>

<!-- Hidden Form for Deletion -->
<form id="delete-form" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="delete-user-id">
</form>

<script>
function confirmDelete(userId, userName) {
    if (!confirm(`ATENÇÃO!\n\nVocê está prestes a DELETAR permanentemente o usuário:\n\n"${userName}"\n\nEsta ação é IRREVERSÍVEL e irá remover:\n- Conta do usuário\n- Perfil e dados pessoais\n- Solicitações de acesso\n- Histórico de uso\n\nDeseja continuar?`)) {
        return;
    }

    if (!confirm(`CONFIRMAÇÃO FINAL\n\nVocê tem ABSOLUTA CERTEZA que deseja deletar "${userName}"?\n\nEsta é sua última chance de cancelar.\n\nClique OK para DELETAR PERMANENTEMENTE ou Cancelar para abortar.`)) {
        return;
    }

    document.getElementById('delete-user-id').value = userId;
    document.getElementById('delete-form').submit();
}
</script>
</body>
</html>
