<?php
/**
 * API Admin - System Events Dashboard 24h
 *
 * GET /api/admin/system-events-dashboard.php
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

$sql = "
    SELECT
        COUNT(*)::bigint AS total,
        COUNT(*) FILTER (WHERE se.severity = 'error')::bigint AS errors,
        COUNT(*) FILTER (WHERE se.severity = 'warning')::bigint AS warnings,
        COUNT(*) FILTER (WHERE se.event_type = 'iatr.analysis.completed')::bigint AS analises,
        COALESCE(
            SUM(
                CASE
                    WHEN se.event_type = 'iatr.llm.completed'
                     AND se.payload ? 'custo_usd'
                     AND (se.payload->>'custo_usd') ~ '^-?[0-9]+(\\.[0-9]+)?$'
                    THEN (se.payload->>'custo_usd')::numeric
                    ELSE 0::numeric
                END
            ),
            0::numeric
        ) AS custo_total
    FROM system_events se
    WHERE se.created_at >= NOW() - INTERVAL '24 hours'
";

try {
    $db = Database::getInstance();
    $row = $db->fetchOne($sql) ?: [];

    echo json_encode([
        'total' => isset($row['total']) ? (int) $row['total'] : 0,
        'errors' => isset($row['errors']) ? (int) $row['errors'] : 0,
        'warnings' => isset($row['warnings']) ? (int) $row['warnings'] : 0,
        'analises' => isset($row['analises']) ? (int) $row['analises'] : 0,
        'custo_total' => isset($row['custo_total']) ? (float) $row['custo_total'] : 0.0,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('system-events-dashboard.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
