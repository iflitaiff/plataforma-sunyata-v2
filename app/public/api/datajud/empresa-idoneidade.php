<?php
/**
 * DataJud Company Idoneidade Check Proxy
 *
 * POST /api/datajud/empresa-idoneidade.php
 * Body: {"cnpj": "12345678000199", "edital_id": 84}
 *
 * Proxies to FastAPI /api/ai/datajud/empresa-idoneidade
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (!$csrfHeader || $csrfHeader !== $csrfSession) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Inject user_id from session
$input['user_id'] = $_SESSION['user']['id'] ?? null;

$fastApiUrl = 'http://127.0.0.1:8000/api/ai/datajud/empresa-idoneidade';
$internalKey = defined('FASTAPI_INTERNAL_KEY') ? FASTAPI_INTERNAL_KEY : '';

$ch = curl_init($fastApiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($input),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Internal-Key: ' . $internalKey,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Erro ao contactar servico: ' . $curlError]);
    exit;
}

http_response_code($httpCode ?: 200);
echo $response;
