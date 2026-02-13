<?php
/**
 * API: Delete a draft
 *
 * Method: POST
 * Body: { draft_id: X }
 * Response: { success: true }
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Metodo nao permitido'], 405);
}

// CSRF
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    json_response(['success' => false, 'error' => 'CSRF token invalido'], 403);
}

$userId = (int) $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$draftId = (int) ($input['draft_id'] ?? 0);

if (!$draftId) {
    json_response(['success' => false, 'error' => 'draft_id obrigatorio'], 400);
}

try {
    $service = new DraftService();
    $deleted = $service->deleteDraft($draftId, $userId);

    if (!$deleted) {
        json_response(['success' => false, 'error' => 'Rascunho nao encontrado'], 404);
    }

    json_response(['success' => true]);
} catch (\Exception $e) {
    error_log('Draft delete error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Erro ao deletar rascunho'], 500);
}
