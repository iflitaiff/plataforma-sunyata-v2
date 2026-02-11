<?php
/**
 * API: Document Upload
 * Handles file upload to the permanent document library.
 *
 * POST multipart/form-data:
 *   file (file) — the document to upload
 *   tags (json) — optional tags array
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo nao permitido']);
    exit;
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
    exit;
}

use Sunyata\Services\DocumentLibraryService;

$tags = [];
if (!empty($_POST['tags'])) {
    $tags = json_decode($_POST['tags'], true) ?? [];
}

$service = new DocumentLibraryService();
$result = $service->uploadDocument((int)$_SESSION['user_id'], $_FILES['file'], $tags);

if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);
