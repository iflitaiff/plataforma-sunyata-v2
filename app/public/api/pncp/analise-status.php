<?php
/**
 * PNCP Analysis Status API
 *
 * GET /api/pncp/analise-status.php?id={edital_id}
 * Returns the current analysis status for a given edital.
 * Used by edital.php for polling during AI analysis.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

header('Content-Type: application/json; charset=utf-8');

// Auth check
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

// CSRF check
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfSession = csrf_token();
if (!$csrfHeader || $csrfHeader !== $csrfSession) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

// Only GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validate ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "id" inválido']);
    exit;
}

use Sunyata\Core\Database;

$db = Database::getInstance();

$edital = $db->fetchOne("
    SELECT
        id,
        status_analise,
        analise_resultado,
        analise_modelo,
        analise_tipo,
        analise_nivel,
        analise_tokens,
        analise_erro,
        analise_concluida_em
    FROM pncp_editais
    WHERE id = ?
", [$id]);

if (!$edital) {
    http_response_code(404);
    echo json_encode(['error' => 'Edital não encontrado']);
    exit;
}

echo json_encode([
    'id'                 => (int) $edital['id'],
    'status_analise'     => $edital['status_analise'],
    'analise_resultado'  => $edital['analise_resultado'] ? json_decode($edital['analise_resultado'], true) : null,
    'analise_modelo'     => $edital['analise_modelo'],
    'analise_tipo'       => $edital['analise_tipo'],
    'analise_nivel'      => $edital['analise_nivel'],
    'analise_tokens'     => $edital['analise_tokens'] ? (int) $edital['analise_tokens'] : null,
    'analise_erro'       => $edital['analise_erro'],
    'analise_concluida_em' => $edital['analise_concluida_em'],
]);
