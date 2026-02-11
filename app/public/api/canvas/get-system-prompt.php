<?php
/**
 * API: Get Canvas System Prompt
 * Retorna o system_prompt de um Canvas template
 *
 * @method GET
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
        'error' => 'Acesso negado. Apenas administradores podem acessar System Prompts.'
    ]);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido. Use GET.'
    ]);
    exit;
}

// Pegar ID do Canvas
$canvas_id = $_GET['id'] ?? null;

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

    // Buscar canvas e seu system_prompt
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

    // Log da ação
    error_log(sprintf(
        '[ADMIN] User %d fetched system_prompt for canvas %d (%s)',
        $_SESSION['user_id'],
        $canvas_id,
        $canvas['slug']
    ));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'system_prompt' => $canvas['system_prompt'] ?? '',
        'canvas_id' => $canvas['id'],
        'canvas_name' => $canvas['name']
    ]);

} catch (Exception $e) {
    error_log('Error fetching canvas system prompt: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar System Prompt: ' . $e->getMessage()
    ]);
}
