<?php
/**
 * Gateway: Guia de Prompts (Jogos)
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
        'tool_slug' => 'guia-prompts-jogos',
        'vertical' => $user_vertical,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
} catch (Exception $e) {
    error_log('Erro ao registrar acesso à ferramenta: ' . $e->getMessage());
}

// Caminho para o HTML da ferramenta
$tool_html = __DIR__ . '/../../ferramentas/guia-prompts-jogos.html';

// Verificar se arquivo existe
if (!file_exists($tool_html)) {
    http_response_code(404);
    echo '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ferramenta não encontrada</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="alert alert-danger">
            <h4>⚠️ Ferramenta não encontrada</h4>
            <p>O arquivo da ferramenta não foi localizado no servidor.</p>
            <a href="' . BASE_URL . '/areas/docencia/" class="btn btn-primary">Voltar</a>
        </div>
    </div>
</body>
</html>';
    exit;
}

// Embedar o HTML da ferramenta
readfile($tool_html);
