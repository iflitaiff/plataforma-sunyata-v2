<?php
/**
 * API: Update Canvas System Prompt
 * Atualiza o system_prompt de um Canvas template
 *
 * @method POST
 * @requires admin access
 * @body { canvas_id: int, system_prompt: string, csrf_token: string }
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

header('Content-Type: application/json');

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Não autenticado'
    ]);
    exit;
}

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Acesso negado. Apenas administradores podem modificar System Prompts.'
    ]);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido. Use POST.'
    ]);
    exit;
}

// Pegar dados do request
$input = json_decode(file_get_contents('php://input'), true);
$canvas_id = $input['canvas_id'] ?? null;
$system_prompt = $input['system_prompt'] ?? null;
$csrf_token = $input['csrf_token'] ?? null;

// Validar CSRF token
if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'CSRF token inválido'
    ]);
    exit;
}

// Validar inputs
if (!$canvas_id || !is_numeric($canvas_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'ID do Canvas inválido'
    ]);
    exit;
}

// System prompt pode ser vazio (null), validamos apenas se é string quando presente
if ($system_prompt !== null && !is_string($system_prompt)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'System prompt deve ser uma string'
    ]);
    exit;
}

try {
    $db = Database::getInstance();

    // Buscar canvas para verificar se existe e pegar dados antigos
    $canvas = $db->fetchOne("
        SELECT id, slug, name, system_prompt
        FROM canvas_templates
        WHERE id = :id
    ", ['id' => $canvas_id]);

    if (!$canvas) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Canvas não encontrado'
        ]);
        exit;
    }

    $old_prompt_length = strlen($canvas['system_prompt'] ?? '');
    $new_prompt_length = strlen($system_prompt ?? '');

    // Atualizar system_prompt
    $db->execute("
        UPDATE canvas_templates
        SET system_prompt = :system_prompt,
            updated_at = NOW()
        WHERE id = :id
    ", [
        'system_prompt' => $system_prompt,
        'id' => $canvas_id
    ]);

    // Log da ação
    error_log(sprintf(
        '[ADMIN] User %d updated system_prompt for canvas %d (%s) | Length: %d → %d chars',
        $_SESSION['user_id'],
        $canvas_id,
        $canvas['slug'],
        $old_prompt_length,
        $new_prompt_length
    ));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'System Prompt atualizado com sucesso',
        'canvas_id' => $canvas['id'],
        'canvas_name' => $canvas['name'],
        'old_length' => $old_prompt_length,
        'new_length' => $new_prompt_length
    ]);

} catch (Exception $e) {
    error_log('Error updating canvas system prompt: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao atualizar System Prompt: ' . $e->getMessage()
    ]);
}
