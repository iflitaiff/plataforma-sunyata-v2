<?php
/**
 * API: Load a single draft with full form_data
 *
 * Method: GET
 * Query: ?id=X
 * Response: { success: true, draft: { id, label, form_data, page_no, updated_at } }
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Services\DraftService;

header('Content-Type: application/json; charset=utf-8');

// Auth
if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'error' => 'Nao autenticado'], 401);
}

// Method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'error' => 'Metodo nao permitido'], 405);
}

$userId = (int) $_SESSION['user_id'];
$draftId = (int) ($_GET['id'] ?? 0);

if (!$draftId) {
    json_response(['success' => false, 'error' => 'id obrigatorio'], 400);
}

try {
    $service = new DraftService();
    $draft = $service->loadDraft($draftId, $userId);

    if (!$draft) {
        json_response(['success' => false, 'error' => 'Rascunho nao encontrado'], 404);
    }

    json_response([
        'success' => true,
        'draft' => $draft,
    ]);
} catch (\Exception $e) {
    error_log('Draft load error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Erro ao carregar rascunho'], 500);
}
