<?php
/**
 * PNCP Analysis Trigger Proxy
 *
 * POST /api/pncp/trigger-analise.php
 * Body: {"edital_id": N}
 *
 * Proxies the analysis request to N8N webhook server-side,
 * avoiding CORS/SSL issues and keeping auth tokens off the client.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../src/Helpers/system_events.php';

use Sunyata\Core\Database;

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

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse body
$input = json_decode(file_get_contents('php://input'), true);
$editalId = filter_var($input['edital_id'] ?? null, FILTER_VALIDATE_INT);
if (!$editalId) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "edital_id" inválido']);
    exit;
}

// Optional params forwarded to N8N
$TIPOS_VALIDOS = ['resumo_executivo', 'habilitacao', 'verifica_edital', 'contratos', 'sg_contrato'];
$tipoAnalise = $input['tipo_analise'] ?? 'resumo_executivo';
if (!in_array($tipoAnalise, $TIPOS_VALIDOS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'tipo_analise inválido']);
    exit;
}
$contextoEmpresa = isset($input['contexto_empresa']) ? (string)$input['contexto_empresa'] : null;

$NIVEIS_VALIDOS = ['triagem', 'resumo', 'completa'];
$nivelProfundidade = $input['nivel_profundidade'] ?? 'completa';
if (!in_array($nivelProfundidade, $NIVEIS_VALIDOS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'nivel_profundidade inválido']);
    exit;
}
$instrucoesComplementares = null;
if (isset($input['instrucoes_complementares']) && trim((string)$input['instrucoes_complementares']) !== '') {
    $instrucoesBruto = substr(trim((string)$input['instrucoes_complementares']), 0, 1000);
    // Strip dollar-quoting sequences that could break N8N's SQL $TAG$...$TAG$ interpolation
    $instrucoesComplementares = preg_replace('/\$[A-Z]+\$/', '', $instrucoesBruto);
}

// Fix 1: Block concurrent analysis — prevent double-click / parallel API calls
// FOR UPDATE serializes concurrent requests; check prevents duplicate N8N workflows
$db = Database::getInstance();
$current = $db->fetchOne(
    "SELECT status_analise FROM pncp_editais WHERE id = ? FOR UPDATE",
    [$editalId]
);
if ($current && $current['status_analise'] === 'em_analise') {
    http_response_code(409);
    echo json_encode(['error' => 'Análise já em andamento para este edital']);
    exit;
}

// Build N8N webhook URL (internal network: VM100 → CT104 direct)
// Use internal IP to avoid DNS/SSL issues with sslip.io from inside Proxmox
$webhookBase = defined('N8N_WEBHOOK_INTERNAL_URL')
    ? N8N_WEBHOOK_INTERNAL_URL
    : 'http://192.168.100.14:5678';
$webhookToken = defined('N8N_WEBHOOK_AUTH_TOKEN') ? N8N_WEBHOOK_AUTH_TOKEN : '';

if (!$webhookToken) {
    http_response_code(500);
    echo json_encode(['error' => 'Webhook não configurado']);
    exit;
}

$webhookUrl = rtrim($webhookBase, '/') . '/webhook/iatr/analisar';

$traceId = generate_trace_id();

log_event(
    eventType:  'iatr.analysis.requested',
    source:     'portal',
    severity:   'info',
    entityType: 'edital',
    entityId:   (string) $editalId,
    summary:    "Análise solicitada: {$tipoAnalise} ({$nivelProfundidade})",
    payload:    [
        'tipo_analise' => $tipoAnalise,
        'nivel_profundidade' => $nivelProfundidade ?? 'completa',
        'user_id' => $_SESSION['user']['id'] ?? null,
        'tem_instrucoes' => !empty($instrucoesComplementares),
    ],
    traceId:    $traceId
);

$startTime = microtime(true);

// Call N8N webhook server-side
$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array_filter([
        'edital_id'                 => $editalId,
        'tipo_analise'              => $tipoAnalise,
        'nivel_profundidade'        => $nivelProfundidade,
        'instrucoes_complementares' => $instrucoesComplementares,
        'contexto_empresa'          => $contextoEmpresa,
        'trace_id'                  => $traceId,
    ], fn($v) => $v !== null)),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Auth-Token: ' . $webhookToken,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 300, // 5 min — v3 waits for full LLM response
    CURLOPT_SSL_VERIFYPEER => false, // Internal HTTP, no SSL needed
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$duration = (int)((microtime(true) - $startTime) * 1000);

log_event(
    eventType:  $httpCode >= 200 && $httpCode < 300 ? 'iatr.analysis.dispatched' : 'iatr.analysis.dispatch_failed',
    source:     'portal',
    severity:   $httpCode >= 200 && $httpCode < 300 ? 'info' : 'error',
    entityType: 'edital',
    entityId:   (string) $editalId,
    summary:    "Dispatch para N8N: HTTP {$httpCode}",
    payload:    ['http_code' => $httpCode, 'trace_id' => $traceId],
    traceId:    $traceId,
    durationMs: $duration
);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Erro ao contactar webhook: ' . $curlError]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'Webhook retornou HTTP ' . $httpCode]);
    exit;
}

// Forward N8N response
http_response_code(200);
echo $response ?: json_encode(['success' => true]);
