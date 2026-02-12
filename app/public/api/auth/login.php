<?php
/**
 * API: Email/Password Login
 *
 * POST /api/auth/login.php
 * Body: { email, password }
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Auth\PasswordAuth;
use Sunyata\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
}

// Rate limiting: 5 attempts per 15 minutes per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
try {
    $limiter = new RateLimiter();
    $check = $limiter->check("login:{$ip}", 5, 900);
    if (!$check['allowed']) {
        header("Retry-After: {$check['retry_after']}");
        json_response([
            'success' => false,
            'error' => 'Muitas tentativas. Aguarde ' . ceil($check['retry_after'] / 60) . ' minutos.',
        ], 429);
    }
} catch (\Exception $e) {
    // Redis down — allow request but log warning
    error_log("[WARN] RateLimiter unavailable: " . $e->getMessage());
}

$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

$auth = new PasswordAuth();
$result = $auth->login($email, $password);

if ($result['success']) {
    json_response([
        'success' => true,
        'redirect' => BASE_URL . '/dashboard.php',
    ]);
} else {
    json_response([
        'success' => false,
        'error' => $result['error'],
    ], 401);
}
