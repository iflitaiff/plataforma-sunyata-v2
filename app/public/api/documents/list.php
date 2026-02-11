<?php
/**
 * API: Documents List
 * Returns paginated documents for the current user.
 *
 * Query params:
 *   limit      (int)    — items per page (default 20, max 100)
 *   offset     (int)    — pagination offset
 *   mime_type  (string) — filter by MIME type
 *   search     (string) — full-text search
 *   count_only (1)      — return only the count (for dashboard stats)
 *   format     (string) — 'table' returns HTML, 'json' (default) returns JSON
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Services\DocumentLibraryService;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nao autenticado']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$service = new DocumentLibraryService();

// Count-only mode (dashboard stats)
if (isset($_GET['count_only'])) {
    echo $service->countUserDocuments($userId);
    exit;
}

$limit = min((int)($_GET['limit'] ?? 20), 100);
$offset = (int)($_GET['offset'] ?? 0);

$filters = [];
if (!empty($_GET['mime_type'])) $filters['mime_type'] = $_GET['mime_type'];
if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

$result = $service->getUserDocuments($userId, $limit, $offset, $filters);
$items = $result['items'];
$total = $result['total'];

$format = $_GET['format'] ?? 'json';

if ($format === 'table') {
    if (empty($items)) {
        echo '<div class="text-center p-4 text-secondary">';
        echo '<i class="ti ti-files-off" style="font-size:2rem;"></i>';
        echo '<p class="mt-2">Nenhum documento encontrado.</p>';
        echo '<p class="small">Arraste arquivos para a area acima para comecar.</p>';
        echo '</div>';
        exit;
    }

    echo '<div class="table-responsive">';
    echo '<table class="table table-vcenter card-table">';
    echo '<thead><tr>';
    echo '<th>Documento</th>';
    echo '<th>Tipo</th>';
    echo '<th>Tamanho</th>';
    echo '<th>Extracao</th>';
    echo '<th>Data</th>';
    echo '<th class="w-1"></th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($items as $item) {
        $icon = DocumentLibraryService::mimeIcon($item['mime_type']);
        $size = DocumentLibraryService::formatFileSize((int)$item['file_size']);
        $date = date('d/m/Y H:i', strtotime($item['created_at']));

        $extractionBadge = match($item['extraction_status']) {
            'completed' => '<span class="badge bg-success">OK</span>',
            'processing' => '<span class="badge bg-warning">Processando</span>',
            'failed' => '<span class="badge bg-danger">Falha</span>',
            default => '<span class="badge bg-secondary">Pendente</span>',
        };

        // Determine short MIME label
        $typeLabel = match(true) {
            str_contains($item['mime_type'], 'pdf') => 'PDF',
            str_contains($item['mime_type'], 'word') || str_contains($item['mime_type'], 'document') => 'DOCX',
            str_contains($item['mime_type'], 'spreadsheet') || str_contains($item['mime_type'], 'excel') => 'XLSX',
            str_contains($item['mime_type'], 'csv') => 'CSV',
            str_contains($item['mime_type'], 'text') => 'TXT',
            default => strtoupper(pathinfo($item['filename'], PATHINFO_EXTENSION)),
        };

        echo '<tr>';
        echo '<td>';
        echo '<div class="d-flex align-items-center">';
        echo '<i class="ti ' . $icon . ' me-2" style="font-size:1.5rem;"></i>';
        echo '<span class="text-truncate" style="max-width:300px;">' . htmlspecialchars($item['filename']) . '</span>';
        echo '</div>';
        echo '</td>';
        echo '<td><span class="badge bg-secondary-lt">' . $typeLabel . '</span></td>';
        echo '<td class="text-secondary">' . $size . '</td>';
        echo '<td>' . $extractionBadge . '</td>';
        echo '<td class="text-secondary">' . $date . '</td>';
        echo '<td>';
        echo '<div class="btn-list flex-nowrap">';
        echo '<button class="btn btn-sm btn-ghost-danger" onclick="deleteDocument(' . $item['id'] . ')" title="Excluir">';
        echo '<i class="ti ti-trash"></i>';
        echo '</button>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    // Pagination
    if ($total > $limit) {
        $currentPage = floor($offset / $limit) + 1;
        $totalPages = ceil($total / $limit);
        echo '<div class="card-footer d-flex justify-content-between align-items-center">';
        echo '<span class="text-secondary">' . $total . ' documentos</span>';
        echo '<div class="btn-group">';
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            echo '<button class="btn btn-sm btn-outline-primary" '
               . 'hx-get="' . BASE_URL . '/api/documents/list.php?' . http_build_query(array_merge($_GET, ['offset' => $prevOffset])) . '" '
               . 'hx-target="closest .card-body" hx-swap="innerHTML">'
               . '<i class="ti ti-chevron-left"></i></button>';
        }
        echo '<span class="btn btn-sm btn-outline-secondary disabled">' . $currentPage . '/' . $totalPages . '</span>';
        if ($offset + $limit < $total) {
            $nextOffset = $offset + $limit;
            echo '<button class="btn btn-sm btn-outline-primary" '
               . 'hx-get="' . BASE_URL . '/api/documents/list.php?' . http_build_query(array_merge($_GET, ['offset' => $nextOffset])) . '" '
               . 'hx-target="closest .card-body" hx-swap="innerHTML">'
               . '<i class="ti ti-chevron-right"></i></button>';
        }
        echo '</div></div>';
    }
    exit;
}

// JSON format
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'items' => $items,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
