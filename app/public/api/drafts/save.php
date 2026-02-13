<?php
/**
 * API: Save Draft (create or update)
 *
 * Method: POST
 * Body: { canvas_template_id, form_data, page_no?, label?, draft_id? }
 * Response: { success: true, draft_id, label }
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Services\DraftService;
use Sunyata\Core\RateLimiter;

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

// Rate limit: 30 saves per 15 min (auto-save generates many)
try {
    $limiter = new RateLimiter();
    $check = $limiter->check("drafts_save:{$userId}", 30, 900);
    if (!$check['allowed']) {
        header("Retry-After: {$check['retry_after']}");
        json_response([
            'success' => false,
            'error' => 'Limite de salvamento excedido. Aguarde ' . ceil($check['retry_after'] / 60) . ' min.',
        ], 429);
    }
} catch (\Exception $e) {
    error_log("[WARN] RateLimiter unavailable: " . $e->getMessage());
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_response(['success' => false, 'error' => 'JSON invalido'], 400);
}

// Validate required fields
$canvasTemplateId = (int) ($input['canvas_template_id'] ?? 0);
$formData = $input['form_data'] ?? null;

if (!$canvasTemplateId || !is_array($formData)) {
    json_response(['success' => false, 'error' => 'canvas_template_id e form_data sao obrigatorios'], 400);
}

$pageNo = (int) ($input['page_no'] ?? 0);
$label = isset($input['label']) ? (string) $input['label'] : null;
$draftId = isset($input['draft_id']) ? (int) $input['draft_id'] : null;

try {
    $service = new DraftService();
    $savedId = $service->saveDraft($userId, $canvasTemplateId, $formData, $pageNo, $label, $draftId);

    // Fetch the label that was actually saved
    $draft = $service->loadDraft($savedId, $userId);

    json_response([
        'success' => true,
        'draft_id' => $savedId,
        'label' => $draft['label'] ?? '',
    ]);
} catch (\Exception $e) {
    $code = (int) $e->getCode();

    if ($code === 409) {
        json_response(['success' => false, 'error' => $e->getMessage()], 409);
    } elseif ($code === 413) {
        json_response(['success' => false, 'error' => $e->getMessage()], 413);
    } elseif ($code === 404) {
        json_response(['success' => false, 'error' => $e->getMessage()], 404);
    }

    error_log('Draft save error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Erro ao salvar rascunho'], 500);
}
