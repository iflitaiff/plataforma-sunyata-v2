<?php
/**
 * API: Update Canvas Vertical
 * Atualiza a vertical de um Canvas template
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
$new_vertical = $input['vertical'] ?? null;

if (!$canvas_id || !is_numeric($canvas_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'ID do Canvas inválido'
    ]);
    exit;
}

if (!$new_vertical) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Vertical não informada'
    ]);
    exit;
}

try {
    // Carregar verticais disponíveis
    $verticals_config = require __DIR__ . '/../../../config/verticals.php';
    $valid_verticals = array_keys($verticals_config);

    // Validar vertical
    if (!in_array($new_vertical, $valid_verticals)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Vertical inválida. Verticais disponíveis: ' . implode(', ', $valid_verticals)
        ]);
        exit;
    }

    $db = Database::getInstance();

    // Buscar canvas atual
    $canvas = $db->fetchOne("
        SELECT id, slug, name
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

    // Phase 3.5: Get current vertical from junction table (first one if multiple)
    $current_assignment = $db->fetchOne("
        SELECT vertical_slug FROM canvas_vertical_assignments
        WHERE canvas_id = :canvas_id
        ORDER BY display_order ASC
        LIMIT 1
    ", ['canvas_id' => $canvas_id]);

    $old_vertical = $current_assignment['vertical_slug'] ?? null;

    // Se já está na vertical desejada, não fazer nada
    if ($old_vertical === $new_vertical) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Canvas já pertence à vertical ' . ucfirst($new_vertical),
            'vertical' => $new_vertical,
            'no_change' => true
        ]);
        exit;
    }

    // Phase 3.5: Update junction table instead of vertical column
    // Remove old assignment (if exists)
    if ($old_vertical) {
        $db->execute("
            DELETE FROM canvas_vertical_assignments
            WHERE canvas_id = :canvas_id AND vertical_slug = :vertical_slug
        ", [
            'canvas_id' => $canvas_id,
            'vertical_slug' => $old_vertical
        ]);
    }

    // Add new assignment
    $db->execute("
        INSERT INTO canvas_vertical_assignments (canvas_id, vertical_slug, display_order, created_at, updated_at)
        VALUES (:canvas_id, :vertical_slug, 0, NOW(), NOW())
        ON CONFLICT (canvas_id, vertical_slug) DO UPDATE
            SET updated_at = NOW()
    ", [
        'canvas_id' => $canvas_id,
        'vertical_slug' => $new_vertical
    ]);

    // Update canvas updated_at timestamp
    $db->execute("
        UPDATE canvas_templates
        SET updated_at = NOW()
        WHERE id = :id
    ", ['id' => $canvas_id]);

    // Log da ação
    error_log(sprintf(
        '[ADMIN] User %d changed canvas %d (%s) vertical: %s -> %s',
        $_SESSION['user_id'],
        $canvas_id,
        $canvas['slug'],
        $old_vertical,
        $new_vertical
    ));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => sprintf(
            'Vertical alterada de "%s" para "%s" com sucesso',
            ucfirst($old_vertical),
            ucfirst($new_vertical)
        ),
        'vertical' => $new_vertical,
        'vertical_info' => $verticals_config[$new_vertical]
    ]);

} catch (Exception $e) {
    error_log('Error updating canvas vertical: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao atualizar vertical do Canvas: ' . $e->getMessage()
    ]);
}
