<?php
/**
 * API Admin - System Events (listagem com filtros)
 *
 * GET /api/admin/system-events.php
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

$source = filter_input(INPUT_GET, 'source', FILTER_UNSAFE_RAW);
$severity = filter_input(INPUT_GET, 'severity', FILTER_UNSAFE_RAW);
$entityType = filter_input(INPUT_GET, 'entity_type', FILTER_UNSAFE_RAW);
$entityId = filter_input(INPUT_GET, 'entity_id', FILTER_UNSAFE_RAW);
$dateFromRaw = filter_input(INPUT_GET, 'date_from', FILTER_UNSAFE_RAW);
$dateToRaw = filter_input(INPUT_GET, 'date_to', FILTER_UNSAFE_RAW);
$pageRaw = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

$source = is_string($source) ? trim($source) : null;
$severity = is_string($severity) ? trim($severity) : null;
$entityType = is_string($entityType) ? trim($entityType) : null;
$entityId = is_string($entityId) ? trim($entityId) : null;
$dateFromRaw = is_string($dateFromRaw) ? trim($dateFromRaw) : null;
$dateToRaw = is_string($dateToRaw) ? trim($dateToRaw) : null;

$source = $source === '' ? null : $source;
$severity = $severity === '' ? null : $severity;
$entityType = $entityType === '' ? null : $entityType;
$entityId = $entityId === '' ? null : $entityId;
$dateFromRaw = $dateFromRaw === '' ? null : $dateFromRaw;
$dateToRaw = $dateToRaw === '' ? null : $dateToRaw;

$validSources = ['portal', 'n8n', 'fastapi', 'litellm', 'cron'];
$validSeverities = ['info', 'warning', 'error'];

if ($source !== null && !in_array($source, $validSources, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "source" inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($severity !== null && !in_array($severity, $validSeverities, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "severity" inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($entityType !== null && mb_strlen($entityType) > 30) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "entity_type" inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($entityId !== null && mb_strlen($entityId) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "entity_id" inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dateFrom = null;
$dateTo = null;

if ($dateFromRaw !== null) {
    try {
        $dateFrom = (new DateTimeImmutable($dateFromRaw))->format('c');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetro "date_from" inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($dateToRaw !== null) {
    try {
        $dateTo = (new DateTimeImmutable($dateToRaw))->format('c');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetro "date_to" inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($dateFrom !== null && $dateTo !== null && strtotime($dateFrom) > strtotime($dateTo)) {
    http_response_code(400);
    echo json_encode(['error' => '"date_from" deve ser menor ou igual a "date_to"'], JSON_UNESCAPED_UNICODE);
    exit;
}

$page = ($pageRaw !== false && $pageRaw !== null && $pageRaw > 0) ? (int) $pageRaw : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$listSql = "
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
    WHERE (?::varchar(30) IS NULL OR se.source = ?::varchar(30))
      AND (?::varchar(10) IS NULL OR se.severity = ?::varchar(10))
      AND (
            ?::varchar(30) IS NULL
            OR (
                se.entity_type = ?::varchar(30)
                AND (?::varchar(100) IS NULL OR se.entity_id = ?::varchar(100))
            )
          )
      AND se.created_at >= COALESCE(?::timestamptz, NOW() - INTERVAL '7 days')
      AND se.created_at <  COALESCE(?::timestamptz, NOW())
    ORDER BY se.created_at DESC, se.id DESC
    LIMIT ? OFFSET ?
";

$countSql = "
    SELECT COUNT(*) AS total
    FROM system_events se
    WHERE (?::varchar(30) IS NULL OR se.source = ?::varchar(30))
      AND (?::varchar(10) IS NULL OR se.severity = ?::varchar(10))
      AND (
            ?::varchar(30) IS NULL
            OR (
                se.entity_type = ?::varchar(30)
                AND (?::varchar(100) IS NULL OR se.entity_id = ?::varchar(100))
            )
          )
      AND se.created_at >= COALESCE(?::timestamptz, NOW() - INTERVAL '7 days')
      AND se.created_at <  COALESCE(?::timestamptz, NOW())
";

$sharedParams = [
    $source, $source,
    $severity, $severity,
    $entityType, $entityType,
    $entityId, $entityId,
    $dateFrom, $dateTo,
];

try {
    $db = Database::getInstance();
    $events = $db->fetchAll($listSql, array_merge($sharedParams, [$perPage, $offset]));
    $countRow = $db->fetchOne($countSql, $sharedParams);
    $total = isset($countRow['total']) ? (int) $countRow['total'] : 0;
    $pages = (int) ceil($total / $perPage);

    echo json_encode([
        'events' => $events,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('system-events.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
