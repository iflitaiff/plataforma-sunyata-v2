<?php
/**
 * API: Submission Data
 * Returns form_data from a submission (for session reuse in form.php).
 *
 * Query params:
 *   id (int) — submission ID (required)
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

$submissionId = (int)($_GET['id'] ?? 0);
if (!$submissionId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID nao especificado']);
    exit;
}

use Sunyata\Services\SubmissionService;

$service = new SubmissionService();
$formData = $service->getSubmissionData($submissionId, (int)$_SESSION['user_id']);

if ($formData === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Submissao nao encontrada']);
    exit;
}

echo json_encode([
    'success' => true,
    'form_data' => $formData,
]);
