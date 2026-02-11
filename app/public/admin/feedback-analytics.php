<?php
/**
 * Admin: Analytics de Feedback
 * Dashboard para visualizar e analisar feedback dos usuários
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

// Inicializar stats
$stats = [];
try {
    $result = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM vertical_access_requests
        WHERE status = 'pending'
    ");
    $stats['pending_requests'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['pending_requests'] = 0;
}

// Filtros
$filterCanvas = $_GET['canvas'] ?? '';
$filterRating = $_GET['rating'] ?? '';
$filterDate = $_GET['date'] ?? '';

// Estatísticas Gerais
$totalFeedbacks = $db->fetchOne("SELECT COUNT(*) as count FROM formulario_feedback");
$avgRating = $db->fetchOne("SELECT AVG(rating) as avg FROM formulario_feedback");

$stats_feedback = [
    'total' => $totalFeedbacks['count'] ?? 0,
    'avg_rating' => round($avgRating['avg'] ?? 0, 2)
];

// Rating distribution
$ratingDist = $db->fetchAll("
    SELECT rating, COUNT(*) as count
    FROM formulario_feedback
    GROUP BY rating
    ORDER BY rating DESC
");

// Média por Canvas
$canvasAvg = $db->fetchAll("
    SELECT
        ct.name,
        ct.slug,
        COUNT(ff.id) as total_feedbacks,
        AVG(ff.rating) as avg_rating
    FROM canvas_templates ct
    LEFT JOIN formulario_feedback ff ON ct.id = ff.canvas_id
    GROUP BY ct.id, ct.name, ct.slug
    HAVING total_feedbacks > 0
    ORDER BY avg_rating DESC
");

// Buscar feedbacks com filtros
$where = [];
$params = [];

if ($filterCanvas) {
    $where[] = "ff.canvas_id = :canvas_id";
    $params['canvas_id'] = $filterCanvas;
}

if ($filterRating) {
    $where[] = "ff.rating = :rating";
    $params['rating'] = $filterRating;
}

if ($filterDate) {
    $where[] = "DATE(ff.created_at) = :date";
    $params['date'] = $filterDate;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$feedbacks = $db->fetchAll("
    SELECT
        ff.*,
        u.name as user_name,
        u.email as user_email,
        ct.name as canvas_name,
        ct.slug as canvas_slug
    FROM formulario_feedback ff
    JOIN users u ON ff.user_id = u.id
    JOIN canvas_templates ct ON ff.canvas_id = ct.id
    $whereClause
    ORDER BY ff.created_at DESC
    LIMIT 100
", $params);

// Lista de Canvas para filtro
$canvasList = $db->fetchAll("
    SELECT id, name
    FROM canvas_templates
    WHERE is_active = 1
    ORDER BY name
");

$pageTitle = 'Feedback Analytics';
include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
    .stat-card {
        transition: transform 0.2s;
    }
    .stat-card:hover {
        transform: translateY(-2px);
    }
    .star-rating {
        color: #ffc107;
    }
    .feedback-comment {
        max-width: 400px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-graph-up"></i> Feedback Analytics</h1>
        <a href="canvas-templates.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Voltar para Canvas
        </a>
    </div>

    <!-- Estatísticas Gerais -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card stat-card bg-primary text-white">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 opacity-75">Total de Feedbacks</h6>
                    <h2 class="card-title mb-0"><?= number_format($stats_feedback['total']) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card bg-success text-white">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 opacity-75">Média Geral</h6>
                    <h2 class="card-title mb-0">
                        <span class="star-rating">★</span> <?= $stats_feedback['avg_rating'] ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card stat-card">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Distribuição de Ratings</h6>
                    <div class="d-flex gap-3">
                        <?php foreach ($ratingDist as $dist): ?>
                            <div class="text-center">
                                <div class="star-rating"><?= str_repeat('★', $dist['rating']) ?></div>
                                <small class="text-muted"><?= $dist['count'] ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Média por Canvas -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Média de Rating por Canvas</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Canvas</th>
                            <th>Total Feedbacks</th>
                            <th>Média</th>
                            <th>Visual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($canvasAvg as $ca): ?>
                            <tr>
                                <td><?= htmlspecialchars($ca['name']) ?></td>
                                <td><?= $ca['total_feedbacks'] ?></td>
                                <td>
                                    <span class="star-rating">★</span>
                                    <?= round($ca['avg_rating'], 2) ?>
                                </td>
                                <td>
                                    <div class="progress" style="height: 10px; width: 100px;">
                                        <div class="progress-bar bg-warning" role="progressbar"
                                             style="width: <?= ($ca['avg_rating'] / 5 * 100) ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-filter"></i> Filtros</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Canvas</label>
                    <select name="canvas" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($canvasList as $canvas): ?>
                            <option value="<?= $canvas['id'] ?>"
                                <?= $filterCanvas == $canvas['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($canvas['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Rating</label>
                    <select name="rating" class="form-select">
                        <option value="">Todos</option>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?= $i ?>" <?= $filterRating == $i ? 'selected' : '' ?>>
                                <?= str_repeat('★', $i) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Data</label>
                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Filtrar
                    </button>
                    <a href="feedback-analytics.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Limpar
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de Feedbacks -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-chat-left-text"></i> Feedbacks Recentes (últimos 100)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($feedbacks)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Nenhum feedback encontrado com os filtros selecionados.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Usuário</th>
                                <th>Canvas</th>
                                <th>Rating</th>
                                <th>Comentário</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feedbacks as $fb): ?>
                                <tr>
                                    <td>
                                        <small><?= date('d/m/Y H:i', strtotime($fb['created_at'])) ?></small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($fb['user_name']) ?>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($fb['user_email']) ?></small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($fb['canvas_name']) ?>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($fb['canvas_slug']) ?></small>
                                    </td>
                                    <td>
                                        <span class="star-rating">
                                            <?= str_repeat('★', $fb['rating']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($fb['comentario']): ?>
                                            <span class="feedback-comment"
                                                  title="<?= htmlspecialchars($fb['comentario']) ?>"
                                                  data-bs-toggle="tooltip">
                                                <?= htmlspecialchars($fb['comentario']) ?>
                                            </span>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Inicializar tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
