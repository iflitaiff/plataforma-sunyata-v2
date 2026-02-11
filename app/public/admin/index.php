<?php
/**
 * Admin Dashboard - Página Principal
 * Apenas para usuários com access_level = 'admin'
 * Updated: 2025-10-14 19:25
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Core\Settings;
use Sunyata\AI\ClaudeService;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();
$settings = Settings::getInstance();

// Handle settings toggle
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $message = 'Token de segurança inválido';
        $message_type = 'danger';
    } else {
        $action = $_POST['action'];

        if ($action === 'toggle_juridico_approval') {
            $newValue = $settings->toggle('juridico_requires_approval', $_SESSION['user_id']);
            $statusText = $newValue ? 'ATIVADA' : 'DESATIVADA';
            $message = "Aprovação Jurídico {$statusText} com sucesso!";
            $message_type = 'success';
        }
    }
}

// Ler configurações atuais
$juridico_requires_approval = $settings->get('juridico_requires_approval', true);

// Estatísticas
$stats = [];

// Total de usuários
$result = $db->fetchOne("SELECT COUNT(*) as count FROM users");
$stats['total_users'] = $result['count'];

// Usuários por nível
$stats['users_by_level'] = $db->fetchAll("
    SELECT access_level, COUNT(*) as count
    FROM users
    GROUP BY access_level
    ORDER BY count DESC
");

// Usuários por vertical
$stats['users_by_vertical'] = $db->fetchAll("
    SELECT selected_vertical, COUNT(*) as count
    FROM users
    WHERE selected_vertical IS NOT NULL
    GROUP BY selected_vertical
    ORDER BY count DESC
");

// Solicitações pendentes
$result = $db->fetchOne("
    SELECT COUNT(*) as count
    FROM vertical_access_requests
    WHERE status = 'pending'
");
$stats['pending_requests'] = $result['count'];

// Usuários cadastrados nos últimos 7 dias
$result = $db->fetchOne("
    SELECT COUNT(*) as count
    FROM users
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stats['new_users_week'] = $result['count'];

// Últimos logins
$stats['recent_logins'] = $db->fetchAll("
    SELECT u.id, u.name, u.email, u.access_level, u.last_login
    FROM users u
    WHERE u.last_login IS NOT NULL
    ORDER BY u.last_login DESC
    LIMIT 10
");

// Estatísticas API Claude (mês atual) - ENHANCED
try {
    // Stats básicas
    $apiStats = $db->fetchOne("
        SELECT
            COUNT(*) as total_prompts,
            COALESCE(SUM(tokens_input), 0) as total_tokens_input,
            COALESCE(SUM(tokens_output), 0) as total_tokens_output,
            COALESCE(SUM(tokens_total), 0) as total_tokens,
            COALESCE(SUM(cost_usd), 0) as total_cost,
            COALESCE(AVG(response_time_ms), 0) as avg_response_time
        FROM prompt_history
        WHERE status = 'success'
        AND MONTH(created_at) = MONTH(NOW())
        AND YEAR(created_at) = YEAR(NOW())
    ");

    $stats['api_month'] = [
        'total_prompts' => $apiStats['total_prompts'] ?? 0,
        'total_tokens_input' => $apiStats['total_tokens_input'] ?? 0,
        'total_tokens_output' => $apiStats['total_tokens_output'] ?? 0,
        'total_tokens' => $apiStats['total_tokens'] ?? 0,
        'total_cost' => $apiStats['total_cost'] ?? 0,
        'avg_response_time' => $apiStats['avg_response_time'] ?? 0,
        'avg_cost_per_prompt' => $apiStats['total_prompts'] > 0 ? ($apiStats['total_cost'] / $apiStats['total_prompts']) : 0
    ];

    // Custo hoje
    $todayCost = $db->fetchOne("
        SELECT COALESCE(SUM(cost_usd), 0) as cost FROM prompt_history
        WHERE status = 'success' AND DATE(created_at) = CURDATE()
    ");
    $stats['api_today'] = $todayCost['cost'] ?? 0;

    // Custo ontem (para comparação de trend)
    $yesterdayCost = $db->fetchOne("
        SELECT COALESCE(SUM(cost_usd), 0) as cost FROM prompt_history
        WHERE status = 'success' AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ");
    $stats['api_yesterday'] = $yesterdayCost['cost'] ?? 0;

    // Breakdown por modelo
    $stats['api_by_model'] = $db->fetchAll("
        SELECT
            COALESCE(claude_model, 'Unknown') as model,
            COUNT(*) as count,
            COALESCE(SUM(cost_usd), 0) as cost,
            COALESCE(SUM(tokens_total), 0) as tokens
        FROM prompt_history
        WHERE status = 'success'
        AND MONTH(created_at) = MONTH(NOW())
        AND YEAR(created_at) = YEAR(NOW())
        GROUP BY claude_model
        ORDER BY cost DESC
    ");

    // Breakdown por vertical
    $stats['api_by_vertical'] = $db->fetchAll("
        SELECT
            vertical,
            COUNT(*) as count,
            COALESCE(SUM(cost_usd), 0) as cost,
            COALESCE(SUM(tokens_total), 0) as tokens
        FROM prompt_history
        WHERE status = 'success'
        AND MONTH(created_at) = MONTH(NOW())
        AND YEAR(created_at) = YEAR(NOW())
        GROUP BY vertical
        ORDER BY cost DESC
    ");

    // Top 5 usuários
    $stats['api_top_users'] = $db->fetchAll("
        SELECT
            u.id,
            u.name,
            u.email,
            COUNT(ph.id) as prompts_count,
            COALESCE(SUM(ph.cost_usd), 0) as total_cost,
            COALESCE(SUM(ph.tokens_total), 0) as total_tokens
        FROM prompt_history ph
        INNER JOIN users u ON ph.user_id = u.id
        WHERE ph.status = 'success'
        AND MONTH(ph.created_at) = MONTH(NOW())
        AND YEAR(ph.created_at) = YEAR(NOW())
        GROUP BY u.id, u.name, u.email
        ORDER BY total_cost DESC
        LIMIT 5
    ");

    // Trend últimos 7 dias
    $stats['api_7day_trend'] = $db->fetchAll("
        SELECT
            DATE(created_at) as date,
            COUNT(*) as prompts,
            COALESCE(SUM(cost_usd), 0) as cost
        FROM prompt_history
        WHERE status = 'success'
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");

    // Taxa de erro
    $errorStats = $db->fetchOne("
        SELECT
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count
        FROM prompt_history
        WHERE MONTH(created_at) = MONTH(NOW())
        AND YEAR(created_at) = YEAR(NOW())
    ");
    $stats['api_error_rate'] = $errorStats['total_requests'] > 0
        ? ($errorStats['error_count'] / $errorStats['total_requests']) * 100
        : 0;
    $stats['api_error_count'] = $errorStats['error_count'] ?? 0;

} catch (Exception $e) {
    error_log('Error fetching API stats: ' . $e->getMessage());
    $stats['api_month'] = ['total_prompts' => 0, 'total_tokens_input' => 0, 'total_tokens_output' => 0, 'total_tokens' => 0, 'total_cost' => 0, 'avg_response_time' => 0, 'avg_cost_per_prompt' => 0];
    $stats['api_today'] = 0;
    $stats['api_yesterday'] = 0;
    $stats['api_by_model'] = [];
    $stats['api_by_vertical'] = [];
    $stats['api_top_users'] = [];
    $stats['api_7day_trend'] = [];
    $stats['api_error_rate'] = 0;
    $stats['api_error_count'] = 0;
}

$pageTitle = 'Admin Dashboard';

// Include responsive header
include __DIR__ . '/../../src/views/admin-header.php';
?>
                <h1 class="mb-4">Dashboard de Administração</h1>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
                        <?= sanitize_output($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Quick Settings -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-sliders"></i> Configurações Rápidas</h5>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="mb-1">⚖️ Aprovação Vertical Jurídico</h6>
                                <p class="mb-0 text-muted small">
                                    <?php if ($juridico_requires_approval): ?>
                                        <span class="badge bg-warning">ATIVA</span> Usuários precisam de aprovação admin para acessar Jurídico
                                    <?php else: ?>
                                        <span class="badge bg-success">DESATIVADA</span> Usuários acessam Jurídico diretamente após onboarding
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="toggle_juridico_approval">
                                    <button type="submit" class="btn btn-<?= $juridico_requires_approval ? 'success' : 'warning' ?>">
                                        <i class="bi bi-toggle-<?= $juridico_requires_approval ? 'on' : 'off' ?>"></i>
                                        <?= $juridico_requires_approval ? 'Desativar Aprovação' : 'Ativar Aprovação' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-xl-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Total de Usuários</h6>
                                        <h2 class="mb-0"><?= $stats['total_users'] ?></h2>
                                    </div>
                                    <div class="text-primary" style="font-size: 2.5rem;">
                                        <i class="bi bi-people"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Novos (7 dias)</h6>
                                        <h2 class="mb-0"><?= $stats['new_users_week'] ?></h2>
                                    </div>
                                    <div class="text-success" style="font-size: 2.5rem;">
                                        <i class="bi bi-person-plus"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Solicitações Pendentes</h6>
                                        <h2 class="mb-0"><?= $stats['pending_requests'] ?></h2>
                                    </div>
                                    <div class="text-warning" style="font-size: 2.5rem;">
                                        <i class="bi bi-clock-history"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Verticais Ativas</h6>
                                        <h2 class="mb-0"><?= count($stats['users_by_vertical']) ?></h2>
                                    </div>
                                    <div class="text-info" style="font-size: 2.5rem;">
                                        <i class="bi bi-grid"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ENHANCED API Dashboard -->
                <?php
                $costLimit = 10.00; // USD limite mensal
                $costPercent = ($stats['api_month']['total_cost'] / $costLimit) * 100;
                $alertClass = $costPercent > 80 ? 'danger' : ($costPercent > 50 ? 'warning' : 'success');
                $moneyRemaining = $costLimit - $stats['api_month']['total_cost'];
                $trendPercent = $stats['api_yesterday'] > 0 ? (($stats['api_today'] - $stats['api_yesterday']) / $stats['api_yesterday']) * 100 : 0;
                ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card border-<?= $alertClass ?>">
                            <div class="card-header bg-<?= $alertClass ?> text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-robot"></i> Uso API Claude - Mês Atual (Enhanced)</h5>
                                <a href="<?= BASE_URL ?>/admin/api-export.php?month=current" class="btn btn-light btn-sm">
                                    <i class="bi bi-download"></i> Exportar CSV
                                </a>
                            </div>
                            <div class="card-body">
                                <!-- Main Stats -->
                                <div class="row mb-4">
                                    <div class="col-lg-2 col-md-4 col-6 mb-3">
                                        <h6 class="text-muted small">Prompts</h6>
                                        <h3 class="mb-0"><?= number_format($stats['api_month']['total_prompts']) ?></h3>
                                    </div>
                                    <div class="col-lg-2 col-md-4 col-6 mb-3">
                                        <h6 class="text-muted small">Tokens (In)</h6>
                                        <h3 class="mb-0"><?= number_format($stats['api_month']['total_tokens_input']) ?></h3>
                                    </div>
                                    <div class="col-lg-2 col-md-4 col-6 mb-3">
                                        <h6 class="text-muted small">Tokens (Out)</h6>
                                        <h3 class="mb-0"><?= number_format($stats['api_month']['total_tokens_output']) ?></h3>
                                    </div>
                                    <div class="col-lg-2 col-md-4 col-6 mb-3">
                                        <h6 class="text-muted small">Custo Mês</h6>
                                        <h3 class="mb-0">$<?= number_format($stats['api_month']['total_cost'], 3) ?></h3>
                                        <small class="text-muted">de $<?= number_format($costLimit, 2) ?></small>
                                    </div>
                                    <div class="col-lg-2 col-md-4 col-6 mb-3">
                                        <h6 class="text-muted small">Custo Hoje</h6>
                                        <h3 class="mb-0">$<?= number_format($stats['api_today'], 4) ?></h3>
                                        <small class="<?= $trendPercent > 0 ? 'text-danger' : 'text-success' ?>">
                                            <i class="bi bi-arrow-<?= $trendPercent > 0 ? 'up' : 'down' ?>"></i>
                                            <?= number_format(abs($trendPercent), 1) ?>% vs ontem
                                        </small>
                                    </div>
                                    <div class="col-lg-2 col-md-4 col-6 mb-3">
                                        <h6 class="text-muted small">Custo/Prompt</h6>
                                        <h3 class="mb-0">$<?= number_format($stats['api_month']['avg_cost_per_prompt'], 5) ?></h3>
                                        <small class="text-muted">média</small>
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div class="progress mb-3" style="height: 25px;">
                                    <div class="progress-bar bg-<?= $alertClass ?>" role="progressbar"
                                         style="width: <?= min($costPercent, 100) ?>%"
                                         aria-valuenow="<?= $costPercent ?>" aria-valuemin="0" aria-valuemax="100">
                                        <?= number_format($costPercent, 1) ?>% usado | $<?= number_format($moneyRemaining, 2) ?> restante
                                    </div>
                                </div>

                                <!-- Alerts -->
                                <?php if ($costPercent > 80): ?>
                                    <div class="alert alert-danger">
                                        <strong>⚠️ CRÍTICO!</strong> Uso acima de 80%. Restam apenas $<?= number_format($moneyRemaining, 2) ?>.
                                        <?php if ($stats['api_error_count'] > 0): ?>
                                            | <strong><?= $stats['api_error_count'] ?> erros</strong> detectados (<?= number_format($stats['api_error_rate'], 1) ?>%).
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($costPercent > 50): ?>
                                    <div class="alert alert-warning">
                                        <strong>⚡ ATENÇÃO:</strong> Uso acima de 50%. Restam $<?= number_format($moneyRemaining, 2) ?>.
                                        <?php if ($stats['api_error_count'] > 0): ?>
                                            | <?= $stats['api_error_count'] ?> erros (<?= number_format($stats['api_error_rate'], 1) ?>%).
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($stats['api_error_count'] > 0): ?>
                                    <div class="alert alert-info">
                                        <strong><i class="bi bi-info-circle"></i> Status:</strong>
                                        <?= $stats['api_error_count'] ?> erros detectados (<?= number_format($stats['api_error_rate'], 1) ?>%).
                                    </div>
                                <?php endif; ?>

                                <hr class="my-4">

                                <!-- Breakdowns Row -->
                                <div class="row">
                                    <!-- Breakdown por Modelo -->
                                    <div class="col-md-4 mb-3">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-cpu"></i> Por Modelo</h6>
                                        <?php if (empty($stats['api_by_model'])): ?>
                                            <p class="text-muted small">Nenhum dado disponível</p>
                                        <?php else: ?>
                                            <?php $totalCostModels = array_sum(array_column($stats['api_by_model'], 'cost')); ?>
                                            <?php foreach ($stats['api_by_model'] as $model): ?>
                                                <?php
                                                $modelPercent = $totalCostModels > 0 ? ($model['cost'] / $totalCostModels) * 100 : 0;
                                                $modelName = str_replace('claude-', '', $model['model']);
                                                $modelName = ucwords(str_replace('-', ' ', $modelName));
                                                ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between small mb-1">
                                                        <span><?= sanitize_output($modelName) ?></span>
                                                        <span class="fw-bold">$<?= number_format($model['cost'], 4) ?> (<?= number_format($modelPercent, 1) ?>%)</span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-primary" style="width: <?= $modelPercent ?>%"></div>
                                                    </div>
                                                    <small class="text-muted"><?= number_format($model['count']) ?> prompts, <?= number_format($model['tokens']) ?> tokens</small>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Breakdown por Vertical -->
                                    <div class="col-md-4 mb-3">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-grid-3x3"></i> Por Vertical</h6>
                                        <?php if (empty($stats['api_by_vertical'])): ?>
                                            <p class="text-muted small">Nenhum dado disponível</p>
                                        <?php else: ?>
                                            <?php $totalCostVerticals = array_sum(array_column($stats['api_by_vertical'], 'cost')); ?>
                                            <?php foreach ($stats['api_by_vertical'] as $vert): ?>
                                                <?php $vertPercent = $totalCostVerticals > 0 ? ($vert['cost'] / $totalCostVerticals) * 100 : 0; ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between small mb-1">
                                                        <span class="text-capitalize"><?= sanitize_output($vert['vertical']) ?></span>
                                                        <span class="fw-bold">$<?= number_format($vert['cost'], 4) ?> (<?= number_format($vertPercent, 1) ?>%)</span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-success" style="width: <?= $vertPercent ?>%"></div>
                                                    </div>
                                                    <small class="text-muted"><?= number_format($vert['count']) ?> prompts, <?= number_format($vert['tokens']) ?> tokens</small>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Top 5 Usuários -->
                                    <div class="col-md-4 mb-3">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-trophy"></i> Top 5 Usuários</h6>
                                        <?php if (empty($stats['api_top_users'])): ?>
                                            <p class="text-muted small">Nenhum dado disponível</p>
                                        <?php else: ?>
                                            <?php $totalCostUsers = array_sum(array_column($stats['api_top_users'], 'total_cost')); ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover mb-0">
                                                    <tbody>
                                                        <?php foreach ($stats['api_top_users'] as $index => $user): ?>
                                                            <?php $userPercent = $totalCostUsers > 0 ? ($user['total_cost'] / $totalCostUsers) * 100 : 0; ?>
                                                            <tr>
                                                                <td class="text-center" style="width: 30px;">
                                                                    <span class="badge bg-<?= $index === 0 ? 'warning' : 'secondary' ?>"><?= $index + 1 ?></span>
                                                                </td>
                                                                <td>
                                                                    <div class="small fw-bold"><?= sanitize_output($user['name']) ?></div>
                                                                    <div class="text-muted" style="font-size: 11px;">
                                                                        <?= $user['prompts_count'] ?> prompts | <?= number_format($user['total_tokens']) ?> tok
                                                                    </div>
                                                                </td>
                                                                <td class="text-end" style="width: 80px;">
                                                                    <div class="fw-bold small">$<?= number_format($user['total_cost'], 3) ?></div>
                                                                    <div class="text-muted" style="font-size: 11px;"><?= number_format($userPercent, 1) ?>%</div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <!-- 7-day Trend + Insights -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-graph-up"></i> Tendência 7 Dias</h6>
                                        <?php if (empty($stats['api_7day_trend'])): ?>
                                            <p class="text-muted">Nenhum dado disponível</p>
                                        <?php else: ?>
                                            <?php $maxCostTrend = max(array_column($stats['api_7day_trend'], 'cost')); ?>
                                            <div class="d-flex align-items-end" style="height: 120px; gap: 4px;">
                                                <?php foreach ($stats['api_7day_trend'] as $day): ?>
                                                    <?php
                                                    $barHeight = $maxCostTrend > 0 ? ($day['cost'] / $maxCostTrend) * 100 : 0;
                                                    $dayLabel = date('d/m', strtotime($day['date']));
                                                    ?>
                                                    <div class="flex-fill text-center">
                                                        <div class="position-relative" style="height: 100px;">
                                                            <div class="bg-primary rounded-top position-absolute bottom-0 w-100"
                                                                 style="height: <?= $barHeight ?>%"
                                                                 title="<?= $dayLabel ?>: $<?= number_format($day['cost'], 4) ?> (<?= $day['prompts'] ?> prompts)">
                                                            </div>
                                                        </div>
                                                        <small class="text-muted" style="font-size: 10px;"><?= $dayLabel ?></small>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="text-center mt-2">
                                                <small class="text-muted">Custo diário nos últimos 7 dias</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-lightbulb"></i> Insights Automáticos</h6>
                                        <ul class="list-unstyled small">
                                            <?php
                                            // Peak day
                                            if (!empty($stats['api_7day_trend'])) {
                                                $peakDay = array_reduce($stats['api_7day_trend'], function($carry, $item) {
                                                    return ($carry === null || $item['cost'] > $carry['cost']) ? $item : $carry;
                                                });
                                                echo '<li class="mb-2"><i class="bi bi-calendar-check text-primary"></i> <strong>Dia de maior uso:</strong> ' . date('d/m/Y', strtotime($peakDay['date'])) . ' ($' . number_format($peakDay['cost'], 4) . ')</li>';
                                            }

                                            // Most used model
                                            if (!empty($stats['api_by_model'])) {
                                                $topModel = $stats['api_by_model'][0];
                                                $modelName = ucwords(str_replace('-', ' ', str_replace('claude-', '', $topModel['model'])));
                                                echo '<li class="mb-2"><i class="bi bi-star text-warning"></i> <strong>Modelo mais usado:</strong> ' . sanitize_output($modelName) . ' (' . number_format($topModel['count']) . ' prompts)</li>';
                                            }

                                            // Error rate status
                                            if ($stats['api_error_rate'] > 10) {
                                                echo '<li class="mb-2"><i class="bi bi-exclamation-triangle text-danger"></i> <strong>Taxa de erro alta:</strong> ' . number_format($stats['api_error_rate'], 1) . '% - Investigar causas</li>';
                                            } elseif ($stats['api_error_rate'] > 0) {
                                                echo '<li class="mb-2"><i class="bi bi-check-circle text-success"></i> <strong>Taxa de erro normal:</strong> ' . number_format($stats['api_error_rate'], 1) . '%</li>';
                                            } else {
                                                echo '<li class="mb-2"><i class="bi bi-check-circle text-success"></i> <strong>Sem erros</strong> registrados neste mês</li>';
                                            }

                                            // Budget status
                                            $daysInMonth = date('t');
                                            $dayOfMonth = date('j');
                                            $expectedSpend = ($costLimit / $daysInMonth) * $dayOfMonth;
                                            $actualSpend = $stats['api_month']['total_cost'];
                                            if ($actualSpend > $expectedSpend * 1.2) {
                                                echo '<li class="mb-2"><i class="bi bi-exclamation-circle text-danger"></i> <strong>Acima do ritmo:</strong> Gastando ' . number_format((($actualSpend / $expectedSpend) - 1) * 100, 0) . '% mais que o esperado</li>';
                                            } elseif ($actualSpend < $expectedSpend * 0.8) {
                                                echo '<li class="mb-2"><i class="bi bi-piggy-bank text-success"></i> <strong>Economizando:</strong> ' . number_format((1 - ($actualSpend / $expectedSpend)) * 100, 0) . '% abaixo do esperado</li>';
                                            } else {
                                                echo '<li class="mb-2"><i class="bi bi-check-circle text-info"></i> <strong>No ritmo:</strong> Gasto dentro do esperado para o dia ' . $dayOfMonth . '/' . $daysInMonth . '</li>';
                                            }
                                            ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row g-4 mb-4">
                    <!-- Users by Level -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Usuários por Nível de Acesso</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($stats['users_by_level'] as $level): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-capitalize"><?= $level['access_level'] ?></span>
                                            <strong><?= $level['count'] ?></strong>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar" role="progressbar"
                                                 style="width: <?= ($level['count'] / $stats['total_users']) * 100 ?>%">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Users by Vertical -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Usuários por Vertical</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($stats['users_by_vertical'])): ?>
                                    <p class="text-muted">Nenhum usuário com vertical definida ainda.</p>
                                <?php else: ?>
                                    <?php foreach ($stats['users_by_vertical'] as $vertical): ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="text-capitalize"><?= $vertical['selected_vertical'] ?></span>
                                                <strong><?= $vertical['count'] ?></strong>
                                            </div>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar bg-success" role="progressbar"
                                                     style="width: <?= ($vertical['count'] / $stats['total_users']) * 100 ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Últimos Acessos</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Usuário</th>
                                        <th class="d-none d-md-table-cell">Email</th>
                                        <th>Nível</th>
                                        <th class="d-none d-sm-table-cell">Último Acesso</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['recent_logins'] as $user): ?>
                                        <tr>
                                            <td>
                                                <?= sanitize_output($user['name']) ?>
                                                <div class="d-md-none small text-muted"><?= sanitize_output($user['email']) ?></div>
                                            </td>
                                            <td class="d-none d-md-table-cell"><?= sanitize_output($user['email']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $user['access_level'] === 'admin' ? 'danger' : 'secondary' ?>">
                                                    <?= $user['access_level'] ?>
                                                </span>
                                            </td>
                                            <td class="d-none d-sm-table-cell"><?= date('d/m/Y H:i', strtotime($user['last_login'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
