<?php
/**
 * API: Submission Actions
 * Handles favorite toggle, archive, and resubmit.
 *
 * POST JSON body:
 *   action        (string) — 'favorite', 'archive', 'resubmit'
 *   submission_id (int)    — target submission
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
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON invalido']);
    exit;
}

$action = $input['action'] ?? '';
$submissionId = (int)($input['submission_id'] ?? 0);

if (!$submissionId || !$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action e submission_id obrigatorios']);
    exit;
}

use Sunyata\Services\SubmissionService;

$service = new SubmissionService();
$userId = (int)$_SESSION['user_id'];

switch ($action) {
    case 'favorite':
        $newValue = $service->toggleFavorite($submissionId, $userId);
        echo json_encode([
            'success' => true,
            'is_favorite' => $newValue,
            'message' => $newValue ? 'Adicionado aos favoritos' : 'Removido dos favoritos',
        ]);
        break;

    case 'archive':
        $ok = $service->archiveSubmission($submissionId, $userId);
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? 'Submissao arquivada' : 'Erro ao arquivar',
        ]);
        break;

    case 'resubmit':
        $newId = $service->resubmit($submissionId, $userId);
        if ($newId) {
            echo json_encode([
                'success' => true,
                'submission_id' => $newId,
                'message' => 'Resubmissao criada',
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Submissao original nao encontrada',
            ]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acao desconhecida: ' . $action]);
}
