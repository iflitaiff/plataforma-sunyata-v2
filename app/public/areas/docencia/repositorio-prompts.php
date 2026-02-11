<?php
/**
 * Gateway: Repositório de Prompts
 * Embeda o HTML da ferramenta com controle de acesso
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar acesso à vertical
if (!isset($_SESSION['user']['selected_vertical'])) {
    $_SESSION['error'] = 'Por favor, complete o onboarding primeiro';
    redirect(BASE_URL . '/onboarding-step1.php');
}

$user_vertical = $_SESSION['user']['selected_vertical'];
$is_demo = $_SESSION['user']['is_demo'] ?? false;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';

// Verificar se tem acesso (vertical docencia OU usuário demo)
if ($user_vertical !== 'docencia' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta ferramenta';
    redirect(BASE_URL . '/dashboard.php');
}

// Log de acesso para analytics
try {
    $db = Database::getInstance();
    $db->insert('tool_access_logs', [
        'user_id' => $_SESSION['user_id'],
        'tool_name' => 'Repositório de Prompts',
        'tool_path' => '/dicionario.php',
        'vertical' => $user_vertical,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
} catch (Exception $e) {
    error_log('Erro ao registrar acesso à ferramenta: ' . $e->getMessage());
}

// Redirecionar para o dicionário de prompts
redirect(BASE_URL . '/dicionario.php');
