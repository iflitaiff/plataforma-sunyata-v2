<?php
/**
 * API: Deletar Vertical
 *
 * POST /api/verticals/delete.php
 *
 * @package Sunyata\API
 * @since 2026-02-18 (Fase 3.5)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Services\VerticalService;

header('Content-Type: application/json');

// Verificar autenticação
if (!isset($_SESSION['user']) || $_SESSION['user']['access_level'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado. Requer admin.']);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);

// Verificar CSRF token
if (!verify_csrf($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token inválido']);
    exit;
}

// Verificar ID
if (empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID da vertical não fornecido']);
    exit;
}

try {
    $verticalService = VerticalService::getInstance();

    // Hard delete se solicitado explicitamente
    $hardDelete = ($input['hard_delete'] ?? false) === true;

    if ($hardDelete) {
        $success = $verticalService->hardDelete((int)$input['id']);
        $message = 'Vertical deletada permanentemente!';
    } else {
        // Soft delete (padrão)
        $success = $verticalService->delete((int)$input['id']);
        $message = 'Vertical marcada como indisponível!';
    }

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => $message,
        ]);
    } else {
        throw new Exception('Falha ao deletar vertical');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
