<?php
/**
 * API: Email/Password Registration
 *
 * POST /api/auth/register.php
 * Body: { email, password, name }
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
$name = $input['name'] ?? '';

$auth = new PasswordAuth();
$result = $auth->register($email, $password, $name);

if ($result['success']) {
    // Auto-login after registration
    $loginResult = $auth->login($email, $password);

    json_response([
        'success' => true,
        'user_id' => $result['user_id'],
        'redirect' => BASE_URL . '/dashboard.php',
    ], 201);
} else {
    json_response([
        'success' => false,
        'error' => $result['error'],
    ], 400);
}
