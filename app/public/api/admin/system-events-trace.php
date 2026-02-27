<?php
/**
 * API Admin - System Events Trace Timeline
 *
 * GET /api/admin/system-events-trace.php?trace_id=<uuid>
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

use Sunyata\Core\Database;

session_name(SESSION_NAME);
session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']) || ($_SESSION['user']['access_level'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso restrito'], JSON_UNESCAPED_UNICODE);
    exit;
}

$traceId = filter_input(INPUT_GET, 'trace_id', FILTER_UNSAFE_RAW);
$traceId = is_string($traceId) ? trim($traceId) : '';

if ($traceId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "trace_id" é obrigatório'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uuidRegex = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';
if (!preg_match($uuidRegex, $traceId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "trace_id" inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "
    SELECT
        se.id,
        se.trace_id,
        se.source,
        se.event_type,
        se.severity,
        se.entity_type,
        se.entity_id,
        se.summary,
        se.payload,
        se.duration_ms,
        se.created_at
    FROM system_events se
    WHERE se.trace_id = ?::uuid
    ORDER BY se.created_at ASC, se.id ASC
";

try {
    $db = Database::getInstance();
    $events = $db->fetchAll($sql, [$traceId]);

    echo json_encode([
        'trace_id' => $traceId,
        'events' => $events,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('system-events-trace.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
