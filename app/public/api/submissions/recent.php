<?php
/**
 * API: Recent Submissions by Canvas
 * Returns last N submissions for a specific canvas (sidebar panel in form.php).
 *
 * Query params:
 *   canvas_id (int) — required
 *   limit     (int) — default 5, max 20
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Services\SubmissionService;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo '<div class="text-center p-3 text-danger">Nao autenticado.</div>';
    exit;
}

$canvasId = (int)($_GET['canvas_id'] ?? 0);
if (!$canvasId) {
    echo '<div class="text-center p-3 text-secondary">Canvas nao especificado.</div>';
    exit;
}

$limit = min((int)($_GET['limit'] ?? 5), 20);
$userId = (int)$_SESSION['user_id'];

$service = new SubmissionService();
$items = $service->getRecentByCanvas($userId, $canvasId, $limit);

if (empty($items)) {
    echo '<div class="text-center p-3 text-secondary">';
    echo '<i class="ti ti-inbox" style="font-size:1.5rem;"></i>';
    echo '<p class="mt-1 mb-0 small">Nenhuma sessao anterior.</p>';
    echo '</div>';
    exit;
}

echo '<div class="list-group list-group-flush">';
foreach ($items as $item) {
    $title = htmlspecialchars($item['title'] ?: 'Sem titulo');
    $date = date('d/m H:i', strtotime($item['created_at']));
    $statusIcon = match($item['status']) {
        'completed' => '<i class="ti ti-circle-check text-success"></i>',
        'error' => '<i class="ti ti-circle-x text-danger"></i>',
        default => '<i class="ti ti-clock text-warning"></i>',
    };

    echo '<div class="list-group-item">';
    echo '<div class="d-flex justify-content-between align-items-start">';
    echo '<div class="flex-grow-1 me-2">';
    echo '<div class="d-flex align-items-center mb-1">';
    echo $statusIcon . ' ';
    echo '<a href="' . BASE_URL . '/canvas/result.php?id=' . $item['id'] . '" class="ms-1 text-truncate" style="max-width:200px;">' . $title . '</a>';
    echo '</div>';
    echo '<small class="text-secondary">' . $date . '</small>';
    echo '</div>';
    echo '<button class="btn btn-sm btn-ghost-primary" onclick="loadSessionData(' . $item['id'] . ')" title="Carregar dados">';
    echo '<i class="ti ti-download"></i>';
    echo '</button>';
    echo '</div>';
    echo '</div>';
}
echo '</div>';
