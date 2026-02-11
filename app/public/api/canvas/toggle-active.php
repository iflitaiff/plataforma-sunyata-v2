<?php
/**
 * API: Toggle Canvas Active Status
 * Alterna o status is_active de um Canvas template
 *
 * @method POST
 * @requires admin access
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
        'error' => 'Acesso negado. Apenas administradores podem modificar Canvas.'
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

if (!$canvas_id || !is_numeric($canvas_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'ID do Canvas inválido'
    ]);
    exit;
}

try {
    $db = Database::getInstance();

    // Buscar canvas atual
    $canvas = $db->fetchOne("
        SELECT id, slug, name, is_active, vertical
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

    // Alternar estado
    $new_state = !$canvas['is_active'];

    $db->execute("
        UPDATE canvas_templates
        SET is_active = :is_active,
            updated_at = NOW()
        WHERE id = :id
    ", [
        'is_active' => $new_state ? 1 : 0,
        'id' => $canvas_id
    ]);

    // Log da ação
    error_log(sprintf(
        '[ADMIN] User %d toggled canvas %d (%s) from %s to %s',
        $_SESSION['user_id'],
        $canvas_id,
        $canvas['slug'],
        $canvas['is_active'] ? 'active' : 'inactive',
        $new_state ? 'active' : 'inactive'
    ));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'is_active' => $new_state,
        'message' => $new_state
            ? 'Canvas ativado com sucesso'
            : 'Canvas desativado com sucesso'
    ]);

} catch (Exception $e) {
    error_log('Error toggling canvas active status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao alternar status do Canvas: ' . $e->getMessage()
    ]);
}
