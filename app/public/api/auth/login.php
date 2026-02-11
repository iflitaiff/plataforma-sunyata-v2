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

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
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
