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
$csrfSession = $_SESSION['csrf_token'] ?? '';
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

// Call N8N webhook server-side
$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['edital_id' => $editalId]),
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
