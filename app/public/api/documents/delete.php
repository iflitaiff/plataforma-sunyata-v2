<?php
/**
 * API: Document Delete
 * Permanently deletes a document from the library.
 *
 * POST JSON:
 *   document_id (int) — the document to delete
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

$input = json_decode(file_get_contents('php://input'), true);
$documentId = (int)($input['document_id'] ?? 0);

if (!$documentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'document_id obrigatorio']);
    exit;
}

use Sunyata\Services\DocumentLibraryService;

$service = new DocumentLibraryService();
$ok = $service->deleteDocument($documentId, (int)$_SESSION['user_id']);

echo json_encode([
    'success' => $ok,
    'message' => $ok ? 'Documento excluido' : 'Documento nao encontrado',
]);
