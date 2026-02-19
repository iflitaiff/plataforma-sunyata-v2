<?php
/**
 * API: Criar Nova Vertical
 *
 * POST /api/verticals/create.php
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

// Validar campos obrigatórios
$required = ['slug', 'nome', 'icone'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Campo obrigatório: $field"]);
        exit;
    }
}

// Validar slug format (apenas lowercase, números, hífens)
if (!preg_match('/^[a-z0-9-]+$/', $input['slug'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Slug inválido. Use apenas letras minúsculas, números e hífens.'
    ]);
    exit;
}

try {
    $verticalService = VerticalService::getInstance();

    // Preparar dados
    $data = [
        'slug' => $input['slug'],
        'nome' => $input['nome'],
        'icone' => $input['icone'],
        'descricao' => $input['descricao'] ?? '',
        'ordem' => (int)($input['ordem'] ?? 999),
        'disponivel' => (bool)($input['disponivel'] ?? true),
        'requer_aprovacao' => (bool)($input['requer_aprovacao'] ?? false),
        'max_users' => !empty($input['max_users']) ? (int)$input['max_users'] : null,
    ];

    // API params (se fornecido)
    if (!empty($input['api_params'])) {
        $data['api_params'] = is_string($input['api_params'])
            ? json_decode($input['api_params'], true)
            : $input['api_params'];
    }

    $id = $verticalService->create($data);

    echo json_encode([
        'success' => true,
        'message' => 'Vertical criada com sucesso!',
        'id' => $id,
        'slug' => $data['slug'],
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
