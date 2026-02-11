<?php
/**
 * API: Submissions Search
 * Full-text search across user submissions.
 *
 * Query params:
 *   q     (string) — search query (required, min 2 chars)
 *   limit (int)    — max results (default 20, max 50)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nao autenticado']);
    exit;
}

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Busca deve ter pelo menos 2 caracteres']);
    exit;
}

$limit = min((int)($_GET['limit'] ?? 20), 50);

use Sunyata\Services\SubmissionService;

$service = new SubmissionService();
$items = $service->searchSubmissions((int)$_SESSION['user_id'], $query, $limit);

echo json_encode([
    'success' => true,
    'items' => $items,
    'total' => count($items),
    'query' => $query,
]);
