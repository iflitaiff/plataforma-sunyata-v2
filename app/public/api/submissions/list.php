<?php
/**
 * API: Submissions List
 * Returns paginated submissions for the current user.
 *
 * Query params:
 *   limit       (int)    — items per page (default 20, max 100)
 *   offset      (int)    — pagination offset
 *   vertical    (string) — filter by vertical slug
 *   canvas_id   (int)    — filter by canvas template
 *   status      (string) — filter by status
 *   period      (string) — 'month' for current month only
 *   is_favorite (1)      — only favorites
 *   parent_id   (int)    — versions of a specific submission
 *   count_only  (1)      — return only the count (for dashboard stats)
 *   format      (string) — 'table' returns HTML table, 'json' (default) returns JSON
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Services\SubmissionService;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nao autenticado']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$service = new SubmissionService();

// Count-only mode (for dashboard stats card)
if (isset($_GET['count_only'])) {
    $period = $_GET['period'] ?? null;
    if ($period === 'month') {
        echo $service->countMonthly($userId);
    } else {
        $result = $service->getUserSubmissions($userId, 0, 0);
        echo $result['total'];
    }
    exit;
}

// Version history mode
if (isset($_GET['parent_id'])) {
    $parentId = (int)$_GET['parent_id'];
    $db = \Sunyata\Core\Database::getInstance();
    $versions = $db->fetchAll("
        SELECT us.id, us.title, us.status, us.created_at
        FROM user_submissions us
        WHERE (us.parent_id = :parent_id OR us.id = :parent_id)
          AND us.user_id = :user_id
        ORDER BY us.created_at ASC
    ", ['parent_id' => $parentId, 'user_id' => $userId]);

    // Return HTML list
    if (empty($versions)) {
        echo '<div class="text-center p-3 text-secondary">Nenhuma versao encontrada.</div>';
        exit;
    }

    echo '<div class="list-group list-group-flush">';
    foreach ($versions as $v) {
        $isActive = ($v['id'] == ($_GET['current'] ?? 0));
        $statusBadge = match($v['status']) {
            'completed' => '<span class="badge bg-success">OK</span>',
            'error' => '<span class="badge bg-danger">Erro</span>',
            default => '<span class="badge bg-warning">' . htmlspecialchars($v['status']) . '</span>',
        };
        $date = date('d/m H:i', strtotime($v['created_at']));
        echo '<a href="' . BASE_URL . '/canvas/result.php?id=' . $v['id'] . '" '
           . 'class="list-group-item list-group-item-action' . ($isActive ? ' active' : '') . '">'
           . '<div class="d-flex justify-content-between align-items-center">'
           . '<span class="text-truncate">' . htmlspecialchars($v['title'] ?: 'Sem titulo') . '</span>'
           . $statusBadge
           . '</div>'
           . '<small class="text-secondary">' . $date . '</small>'
           . '</a>';
    }
    echo '</div>';
    exit;
}

// Main list mode
$limit = min((int)($_GET['limit'] ?? 20), 100);
$offset = (int)($_GET['offset'] ?? 0);

$filters = [];
if (!empty($_GET['vertical'])) $filters['vertical'] = $_GET['vertical'];
if (!empty($_GET['canvas_id'])) $filters['canvas_id'] = (int)$_GET['canvas_id'];
if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
if (!empty($_GET['period'])) $filters['period'] = $_GET['period'];
if (!empty($_GET['is_favorite'])) $filters['is_favorite'] = true;
if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

$result = $service->getUserSubmissions($userId, $limit, $offset, $filters);
$items = $result['items'];
$total = $result['total'];

$format = $_GET['format'] ?? 'json';

// HTML table format (for HTMX loads in dashboard and meu-trabalho)
if ($format === 'table') {
    if (empty($items)) {
        echo '<div class="text-center p-4 text-secondary">';
        echo '<i class="ti ti-inbox" style="font-size:2rem;"></i>';
        echo '<p class="mt-2">Nenhuma submissao encontrada.</p>';
        echo '</div>';
        exit;
    }

    echo '<div class="table-responsive">';
    echo '<table class="table table-vcenter card-table">';
    echo '<thead><tr>';
    echo '<th>Titulo</th>';
    echo '<th>Canvas</th>';
    echo '<th>Status</th>';
    echo '<th>Data</th>';
    echo '<th class="w-1"></th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($items as $item) {
        $statusBadge = match($item['status']) {
            'completed' => '<span class="badge bg-success">Concluido</span>',
            'error' => '<span class="badge bg-danger">Erro</span>',
            'pending' => '<span class="badge bg-warning">Pendente</span>',
            'archived' => '<span class="badge bg-secondary">Arquivado</span>',
            default => '<span class="badge bg-secondary">' . htmlspecialchars($item['status']) . '</span>',
        };
        $title = htmlspecialchars($item['title'] ?: 'Sem titulo');
        $canvasName = htmlspecialchars($item['canvas_name'] ?? '');
        $date = date('d/m/Y H:i', strtotime($item['created_at']));
        $favorite = $item['is_favorite'] ? '<i class="ti ti-star-filled text-warning"></i> ' : '';

        echo '<tr>';
        echo '<td>';
        echo $favorite;
        echo '<a href="' . BASE_URL . '/canvas/result.php?id=' . $item['id'] . '">' . $title . '</a>';
        echo '</td>';
        echo '<td><span class="text-secondary">' . $canvasName . '</span></td>';
        echo '<td>' . $statusBadge . '</td>';
        echo '<td class="text-secondary">' . $date . '</td>';
        echo '<td>';
        echo '<div class="btn-list flex-nowrap">';
        echo '<a href="' . BASE_URL . '/canvas/result.php?id=' . $item['id'] . '" class="btn btn-sm btn-ghost-primary"><i class="ti ti-eye"></i></a>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    // Pagination info
    if ($total > $limit) {
        $currentPage = floor($offset / $limit) + 1;
        $totalPages = ceil($total / $limit);
        echo '<div class="card-footer d-flex justify-content-between align-items-center">';
        echo '<span class="text-secondary">' . $total . ' submissoes no total</span>';
        echo '<div class="btn-group">';
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            echo '<button class="btn btn-sm btn-outline-primary" '
               . 'hx-get="' . BASE_URL . '/api/submissions/list.php?' . http_build_query(array_merge($_GET, ['offset' => $prevOffset])) . '" '
               . 'hx-target="closest .card-body" hx-swap="innerHTML">'
               . '<i class="ti ti-chevron-left"></i></button>';
        }
        echo '<span class="btn btn-sm btn-outline-secondary disabled">' . $currentPage . '/' . $totalPages . '</span>';
        if ($offset + $limit < $total) {
            $nextOffset = $offset + $limit;
            echo '<button class="btn btn-sm btn-outline-primary" '
               . 'hx-get="' . BASE_URL . '/api/submissions/list.php?' . http_build_query(array_merge($_GET, ['offset' => $nextOffset])) . '" '
               . 'hx-target="closest .card-body" hx-swap="innerHTML">'
               . '<i class="ti ti-chevron-right"></i></button>';
        }
        echo '</div></div>';
    }
    exit;
}

// JSON format (default)
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'items' => $items,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
