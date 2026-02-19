<?php
/**
 * API: Atualizar Vertical Existente
 *
 * POST /api/verticals/update.php
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

    // Preparar dados para update (apenas campos fornecidos)
    $data = [];

    if (isset($input['slug'])) {
        // Validar formato
        if (!preg_match('/^[a-z0-9-]+$/', $input['slug'])) {
            throw new Exception('Slug inválido. Use apenas letras minúsculas, números e hífens.');
        }
        $data['slug'] = $input['slug'];
    }

    if (isset($input['nome'])) $data['nome'] = $input['nome'];
    if (isset($input['icone'])) $data['icone'] = $input['icone'];
    if (isset($input['descricao'])) $data['descricao'] = $input['descricao'];
    if (isset($input['ordem'])) $data['ordem'] = (int)$input['ordem'];
    if (isset($input['disponivel'])) $data['disponivel'] = (bool)$input['disponivel'];
    if (isset($input['requer_aprovacao'])) $data['requer_aprovacao'] = (bool)$input['requer_aprovacao'];
    if (isset($input['max_users'])) {
        $data['max_users'] = !empty($input['max_users']) ? (int)$input['max_users'] : null;
    }

    // API params
    if (isset($input['api_params'])) {
        $data['api_params'] = is_string($input['api_params'])
            ? json_decode($input['api_params'], true)
            : $input['api_params'];
    }

    $success = $verticalService->update((int)$input['id'], $data);

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Vertical atualizada com sucesso!',
        ]);
    } else {
        throw new Exception('Nenhuma mudança detectada');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
