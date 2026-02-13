<?php
/**
 * API: List Drafts for a template
 *
 * Method: GET
 * Query: ?template_id=X
 * Response: { success: true, drafts: [...], count: N }
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
$templateId = (int) ($_GET['template_id'] ?? 0);

if (!$templateId) {
    json_response(['success' => false, 'error' => 'template_id obrigatorio'], 400);
}

try {
    $service = new DraftService();
    $drafts = $service->listDrafts($userId, $templateId);
    $count = $service->countDrafts($userId, $templateId);

    json_response([
        'success' => true,
        'drafts' => $drafts,
        'count' => $count,
    ]);
} catch (\Exception $e) {
    error_log('Draft list error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Erro ao listar rascunhos'], 500);
}
