<?php
/**
 * Admin - Relatório de Uso por Usuário
 * REQ 10: Visualizar estatísticas de uso detalhadas por usuário
 *
 * TODO: Trocar requireAdminAccess() por requireAdminOrGestor() quando role existir
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Access check (isolado para futura troca por requireAdminOrGestor)
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

// Se não tem user_id, mostrar seletor
if (!$userId) {
    // Buscar usuários que têm uso
    $usersWithUsage = $db->fetchAll("
        SELECT u.id, u.name, u.email, u.selected_vertical, COUNT(ph.id) as total_submissions
        FROM users u
        INNER JOIN prompt_history ph ON u.id = ph.user_id AND ph.status = 'success'
        GROUP BY u.id, u.name, u.email, u.selected_vertical
        ORDER BY total_submissions DESC
    ");

    // Também buscar todos os usuários (para mostrar quem não tem uso)
    $allUsers = $db->fetchAll("
        SELECT u.id, u.name, u.email, u.selected_vertical
        FROM users u
        ORDER BY u.name ASC
    ");

    $stats = [];
    $stats['pending_requests'] = $db->fetchOne("SELECT COUNT(*) as count FROM vertical_access_requests WHERE status = 'pending'")['count'] ?? 0;

    $pageTitle = 'Relatório de Uso';
    include __DIR__ . '/../../src/views/admin-header.php';
    ?>
    <h1 class="mb-4"><i class="bi bi-bar-chart-line"></i> Relatório de Uso</h1>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Selecionar Usuário</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Usuário</label>
                    <select name="user_id" class="form-select" required>
                        <option value="">-- Selecione um usuário --</option>
                        <optgroup label="Com uso registrado">
                            <?php foreach ($usersWithUsage as $u): ?>
                                <option value="<?= $u['id'] ?>">
                                    <?= sanitize_output($u['name']) ?> (<?= sanitize_output($u['email']) ?>) - <?= $u['total_submissions'] ?> submissões
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Todos os usuários">
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= $u['id'] ?>">
                                    <?= sanitize_output($u['name']) ?> (<?= sanitize_output($u['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Ver Relatório
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($usersWithUsage)): ?>
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">Resumo Rápido - Usuários com Uso</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Usuário</th>
                            <th>Vertical</th>
                            <th class="text-end">Submissões</th>
                            <th class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersWithUsage as $u): ?>
                        <tr>
                            <td>
                                <strong><?= sanitize_output($u['name']) ?></strong><br>
                                <small class="text-muted"><?= sanitize_output($u['email']) ?></small>
                            </td>
                            <td><span class="badge bg-secondary"><?= sanitize_output($u['selected_vertical'] ?? '-') ?></span></td>
                            <td class="text-end"><?= number_format($u['total_submissions']) ?></td>
                            <td class="text-end">
                                <a href="?user_id=<?= $u['id'] ?>" class="btn btn-sm btn-primary">
                                    <i class="bi bi-bar-chart-line"></i> Ver
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    include __DIR__ . '/../../src/views/admin-footer.php';
    exit;
}

// ========== RELATÓRIO DO USUÁRIO ==========

// Buscar dados do usuário
$user = $db->fetchOne("
    SELECT u.*, up.phone, up.position, up.organization, up.organization_size, up.area
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE u.id = :uid
", ['uid' => $userId]);

if (!$user) {
    $_SESSION['error'] = 'Usuário não encontrado';
    redirect(BASE_URL . '/admin/user-report.php');
}

// Totais
$totals = $db->fetchOne("
    SELECT COUNT(*) as total_submissions,
           COALESCE(SUM(tokens_total), 0) as total_tokens,
           COALESCE(SUM(cost_usd), 0) as total_cost,
           COALESCE(AVG(response_time_ms), 0) as avg_response_time
    FROM prompt_history
    WHERE user_id = :uid AND status = 'success'
", ['uid' => $userId]);

// Mensal (últimos 6 meses)
$monthly = $db->fetchAll("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
           COUNT(*) as submissions,
           COALESCE(SUM(tokens_total), 0) as tokens,
           COALESCE(SUM(cost_usd), 0) as cost
    FROM prompt_history
    WHERE user_id = :uid AND status = 'success'
      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
", ['uid' => $userId]);

// Top ferramentas
$topTools = $db->fetchAll("
    SELECT ph.tool_name, ct.name as display_name,
           COUNT(*) as uses, COALESCE(SUM(ph.tokens_total), 0) as tokens
    FROM prompt_history ph
    LEFT JOIN canvas_templates ct ON ct.slug = ph.tool_name
    WHERE ph.user_id = :uid AND ph.status = 'success'
    GROUP BY ph.tool_name, ct.name
    ORDER BY uses DESC
    LIMIT 10
", ['uid' => $userId]);

// Atividade recente
$recentActivity = $db->fetchAll("
    SELECT ph.id, ph.tool_name, ph.vertical, ph.tokens_total, ph.cost_usd,
           ph.response_time_ms, ph.status, ph.created_at,
           ct.name as display_name
    FROM prompt_history ph
    LEFT JOIN canvas_templates ct ON ct.slug = ph.tool_name
    WHERE ph.user_id = :uid
    ORDER BY ph.created_at DESC
    LIMIT 20
", ['uid' => $userId]);

$stats = [];
$stats['pending_requests'] = $db->fetchOne("SELECT COUNT(*) as count FROM vertical_access_requests WHERE status = 'pending'")['count'] ?? 0;

$pageTitle = 'Relatório - ' . $user['name'];
include __DIR__ . '/../../src/views/admin-header.php';

// Helper para formatar mês em português
function formatMonth(string $yearMonth): string {
    $months = [
        '01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr',
        '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
        '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'
    ];
    $parts = explode('-', $yearMonth);
    return ($months[$parts[1]] ?? $parts[1]) . '/' . $parts[0];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="bi bi-bar-chart-line"></i> Relatório de Uso</h1>
    <a href="<?= BASE_URL ?>/admin/user-report.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Voltar
    </a>
</div>

<!-- 1. Card do Usuário -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h4><?= sanitize_output($user['name']) ?></h4>
                <p class="text-muted mb-1"><i class="bi bi-envelope"></i> <?= sanitize_output($user['email']) ?></p>
                <?php if (!empty($user['phone'])): ?>
                    <p class="text-muted mb-1"><i class="bi bi-phone"></i> <?= sanitize_output($user['phone']) ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted d-block">Vertical</small>
                        <span class="badge bg-secondary"><?= sanitize_output($user['selected_vertical'] ?? '-') ?></span>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Nível</small>
                        <span class="badge bg-<?= $user['access_level'] === 'admin' ? 'danger' : 'primary' ?>">
                            <?= ucfirst($user['access_level']) ?>
                        </span>
                    </div>
                    <div class="col-6 mt-2">
                        <small class="text-muted d-block">Organização</small>
                        <?= sanitize_output($user['organization'] ?? '-') ?>
                    </div>
                    <div class="col-6 mt-2">
                        <small class="text-muted d-block">Cadastro</small>
                        <?= date('d/m/Y', strtotime($user['created_at'])) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 2. Stat Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3">
        <div class="card stat-card text-center h-100">
            <div class="card-body">
                <h3 class="text-primary"><?= number_format($totals['total_submissions']) ?></h3>
                <small class="text-muted">Submissões</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card stat-card text-center h-100">
            <div class="card-body">
                <h3 class="text-info"><?= number_format($totals['total_tokens']) ?></h3>
                <small class="text-muted">Tokens Total</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card stat-card text-center h-100">
            <div class="card-body">
                <h3 class="text-success">$<?= number_format($totals['total_cost'], 2) ?></h3>
                <small class="text-muted">Custo Total</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card stat-card text-center h-100">
            <div class="card-body">
                <h3 class="text-warning"><?= $totals['avg_response_time'] > 0 ? number_format($totals['avg_response_time'] / 1000, 1) . 's' : '-' ?></h3>
                <small class="text-muted">Tempo Médio</small>
            </div>
        </div>
    </div>
</div>

<?php if ($totals['total_submissions'] > 0): ?>

<!-- 3. Tabela Mensal -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-calendar3"></i> Uso Mensal (últimos 6 meses)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Mês</th>
                        <th class="text-end">Submissões</th>
                        <th class="text-end">Tokens</th>
                        <th class="text-end">Custo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly as $m): ?>
                    <tr>
                        <td><?= formatMonth($m['month']) ?></td>
                        <td class="text-end"><?= number_format($m['submissions']) ?></td>
                        <td class="text-end"><?= number_format($m['tokens']) ?></td>
                        <td class="text-end">$<?= number_format($m['cost'], 4) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthly)): ?>
                    <tr><td colspan="4" class="text-center text-muted">Nenhum uso nos últimos 6 meses</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 4. Top Ferramentas -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-tools"></i> Top Ferramentas</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ferramenta</th>
                        <th class="text-end">Usos</th>
                        <th class="text-end">Tokens</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topTools as $tool): ?>
                    <tr>
                        <td>
                            <?= sanitize_output($tool['display_name'] ?? $tool['tool_name']) ?>
                            <?php if ($tool['display_name'] && $tool['display_name'] !== $tool['tool_name']): ?>
                                <br><small class="text-muted font-monospace"><?= sanitize_output($tool['tool_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format($tool['uses']) ?></td>
                        <td class="text-end"><?= number_format($tool['tokens']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 5. Atividade Recente -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Atividade Recente (últimas 20)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Ferramenta</th>
                        <th>Vertical</th>
                        <th class="text-end">Tokens</th>
                        <th class="text-end">Custo</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentActivity as $a): ?>
                    <tr>
                        <td><small><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></small></td>
                        <td><?= sanitize_output($a['display_name'] ?? $a['tool_name']) ?></td>
                        <td><span class="badge bg-secondary"><?= sanitize_output($a['vertical']) ?></span></td>
                        <td class="text-end"><?= number_format($a['tokens_total'] ?? 0) ?></td>
                        <td class="text-end">$<?= number_format($a['cost_usd'] ?? 0, 4) ?></td>
                        <td>
                            <?php if ($a['status'] === 'success'): ?>
                                <span class="badge bg-success">OK</span>
                            <?php elseif ($a['status'] === 'error'): ?>
                                <span class="badge bg-danger">Erro</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Pendente</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> Este usuário ainda não possui submissões registradas.
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
