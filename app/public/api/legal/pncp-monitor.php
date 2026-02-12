<?php
/**
 * PNCP Monitor API Proxy
 *
 * Receives keyword-based search from the Monitor Config form,
 * forwards to FastAPI pncp-monitor endpoint.
 *
 * POST /api/legal/pncp-monitor.php
 * Body: { keywords, ufs, status_contratacao, ... }
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

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['keywords'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "keywords" é obrigatório']);
    exit;
}

// Forward to FastAPI
$aiServiceUrl = defined('AI_SERVICE_URL') ? AI_SERVICE_URL : 'http://127.0.0.1:8000';
$aiServiceKey = defined('AI_SERVICE_INTERNAL_KEY') ? AI_SERVICE_INTERNAL_KEY : '';

$url = rtrim($aiServiceUrl, '/') . '/api/ai/pncp-monitor';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($input),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 35,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Internal-Key: ' . $aiServiceKey,
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Erro de conexão com o serviço de busca: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);
if (!$data) {
    http_response_code(502);
    echo json_encode(['error' => 'Resposta inválida do serviço (HTTP ' . $httpCode . ')']);
    exit;
}

http_response_code($httpCode >= 400 ? $httpCode : 200);
echo json_encode($data);
