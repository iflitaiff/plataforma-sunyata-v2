<?php
/**
 * API: Toggle Mock Mode (Session-based)
 * Alterna entre modo real e modo simulado do Claude
 */

require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Não autenticado'
    ]);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Alternar estado do Mock Mode na sessão
    $current_state = $_SESSION['canvas_mock_mode'] ?? false;
    $new_state = !$current_state;

    $_SESSION['canvas_mock_mode'] = $new_state;

    // Log da ação
    error_log(sprintf(
        'Mock Mode toggled by user %d: %s -> %s',
        $_SESSION['user_id'],
        $current_state ? 'true' : 'false',
        $new_state ? 'true' : 'false'
    ));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'mock_mode_active' => $new_state,
        'message' => $new_state ? 'Modo Teste ativado' : 'Modo Teste desativado'
    ]);

} catch (Exception $e) {
    error_log('Error toggling mock mode: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao alternar Modo Teste'
    ]);
}
