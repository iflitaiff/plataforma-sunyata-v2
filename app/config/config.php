<?php
/**
 * Plataforma Sunyata - Main Configuration
 *
 * @package Sunyata
 */

// Prevent double inclusion
if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// Start session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Base paths
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
define('SRC_PATH', BASE_PATH . '/src');
define('CONFIG_PATH', BASE_PATH . '/config');

// URLs (override via env for dev/staging)
define('BASE_URL', getenv('BASE_URL') ?: 'https://portal.sunyataconsulting.com');
define('CALLBACK_URL', BASE_URL . '/callback.php');

// Load environment variables from .env.local (if exists)
$envFile = BASE_PATH . '/.env.local';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Set environment variable if not already set
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
}

// Load secrets
$secretsFile = CONFIG_PATH . '/secrets.php';
if (!file_exists($secretsFile)) {
    die('Configuration error: secrets.php not found. Copy secrets.php.example to secrets.php and configure.');
}
require_once $secretsFile;

// Database configuration
// Priority: database.local.php (local dev) > secrets.php (production)
$localDbFile = CONFIG_PATH . '/database.local.php';
if (file_exists($localDbFile)) {
    // Local development database (overrides secrets.php constants)
    require_once $localDbFile;
}

// Note: DB_HOST, DB_NAME, DB_USER, DB_PASS are already defined in secrets.php
// Note: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET are already defined in secrets.php
// No need to redefine them here

// Database port (PostgreSQL default: 5432)
if (!defined('DB_PORT')) {
    define('DB_PORT', '5432');
}

// Session configuration
define('SESSION_LIFETIME', 3600 * 24); // 24 hours
define('SESSION_NAME', 'SUNYATA_SESSION');

// Redis session handler (enabled via env REDIS_SESSION_HOST)
$redisHost = getenv('REDIS_SESSION_HOST');
if ($redisHost) {
    $redisPort = getenv('REDIS_SESSION_PORT') ?: '6379';
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', "tcp://{$redisHost}:{$redisPort}");
}

// LGPD Configuration
define('CONSENT_VERSION', '1.0.0');
define('DATA_RETENTION_DAYS', 730); // 2 years
define('ANONYMIZATION_AFTER_DAYS', 2555); // 7 years

// Application settings
define('APP_NAME', 'Plataforma Sunyata');
define('COMPANY_NAME', 'Sunyata Consulting');
define('SUPPORT_EMAIL', 'suporte@sunyataconsulting.com');
// DPO_EMAIL is already defined in secrets.php (line 31)

// Claude API Mock Mode (use true para testar sem gastar créditos)
if (!defined('CLAUDE_MOCK_MODE')) {
    define('CLAUDE_MOCK_MODE', false); // DESATIVADO: testando API real
}

// Access levels
define('ACCESS_LEVELS', [
    'guest' => 0,
    'student' => 10,
    'client' => 20,
    'admin' => 100
]);

// Verticals configuration
define('VERTICALS', [
    'juridico' => 'Jurídico',
    'sales' => 'Vendas',
    'marketing' => 'Marketing',
    'customer_service' => 'Atendimento',
    'hr' => 'RH',
    'general' => 'Geral',
    'licitacoes' => 'Licitações',
    'docencia' => 'Docência',
    'pesquisa' => 'Pesquisa',
    'iatr' => 'IATR',
    'ifrj_alunos' => 'IFRJ Alunos',
    'geral' => 'Geral'
]);

// Error Handler - Must be loaded after constants are defined
require_once __DIR__ . '/error-handler.php';

// Helper functions
function require_login() {
    // Verifica se o usuário está logado (compatível com auth.php)
    if (!isset($_SESSION['user'])) {
        header('Location: ' . BASE_URL . '/index.php?m=login_required');
        exit;
    }
}

function has_access($required_level) {
    if (!isset($_SESSION['access_level'])) {
        return false;
    }

    $user_level = ACCESS_LEVELS[$_SESSION['access_level']] ?? 0;
    $required = ACCESS_LEVELS[$required_level] ?? 0;

    return $user_level >= $required;
}

function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize_output($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
