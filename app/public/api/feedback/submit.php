<?php
/**
 * API: Submit Feedback (Opcional)
 * Permite usuários enviarem feedback sobre respostas do Canvas
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

// Verificar se está logado
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Verificar CSRF token
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de segurança inválido']);
    exit;
}

try {
    $db = Database::getInstance();

    // Validar campos obrigatórios
    $canvasId = (int)($_POST['canvas_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $promptHistoryId = !empty($_POST['prompt_history_id']) ? (int)$_POST['prompt_history_id'] : null;
    $comentario = trim($_POST['comentario'] ?? '');

    // Validações
    if ($canvasId <= 0) {
        throw new Exception('Canvas ID inválido');
    }

    if ($rating < 1 || $rating > 5) {
        throw new Exception('Rating deve estar entre 1 e 5');
    }

    // Verificar se Canvas existe
    $canvas = $db->fetchOne("
        SELECT id FROM canvas_templates WHERE id = :id
    ", ['id' => $canvasId]);

    if (!$canvas) {
        throw new Exception('Canvas não encontrado');
    }

    // Opcional: Verificar se prompt_history existe e pertence ao usuário
    if ($promptHistoryId) {
        $prompt = $db->fetchOne("
            SELECT id FROM prompt_history
            WHERE id = :id AND user_id = :user_id
        ", [
            'id' => $promptHistoryId,
            'user_id' => $_SESSION['user_id']
        ]);

        // Se não encontrou, permitir mesmo assim (não bloquear feedback)
        if (!$prompt) {
            error_log("Feedback: prompt_history_id=$promptHistoryId não pertence ao user_id={$_SESSION['user_id']}, mas permitindo feedback");
            $promptHistoryId = null; // Não vincular ao prompt
        }
    }

    // Sanitizar comentário
    $comentario = !empty($comentario) ? $comentario : null;

    // Inserir feedback
    $feedbackId = $db->insert('formulario_feedback', [
        'user_id' => $_SESSION['user_id'],
        'canvas_id' => $canvasId,
        'prompt_history_id' => $promptHistoryId,
        'rating' => $rating,
        'comentario' => $comentario,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    if ($feedbackId) {
        echo json_encode([
            'success' => true,
            'message' => 'Feedback enviado com sucesso! Obrigado pela sua avaliação.',
            'feedback_id' => $feedbackId
        ]);
    } else {
        throw new Exception('Erro ao salvar feedback');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    error_log('Feedback API Error: ' . $e->getMessage());
}
