<?php
/**
 * Google OAuth Callback Handler
 *
 * @package Sunyata
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Auth\GoogleAuth;
use Sunyata\Compliance\ConsentManager;
use Sunyata\Core\MarkdownLogger;
use Sunyata\Core\Database;

$auth = new GoogleAuth();
$consentManager = new ConsentManager();

/**
 * Helper function to log OAuth events to audit_logs table
 * This makes events visible in Admin > Logs panel
 */
function logOAuthToDatabase($userId, $action, $status, $details = null) {
    try {
        $db = Database::getInstance();
        $db->insert('audit_logs', [
            'user_id' => $userId > 0 ? $userId : null,  // Convert 0 to NULL for pre-auth events
            'action' => $action,
            'entity_type' => 'oauth',
            'entity_id' => null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => $details ? json_encode($details) : null
        ]);
    } catch (Exception $e) {
        error_log("Failed to log OAuth event to audit_logs: " . $e->getMessage());
    }
}

// Check for error from Google
if (isset($_GET['error'])) {
    $_SESSION['error'] = 'Autenticação cancelada ou falhou';

    // Log failed login attempt
    MarkdownLogger::getInstance()->access(
        userId: 0,
        action: 'LOGIN_FAILED',
        resource: 'OAuth',
        status: 'cancelled',
        extraContext: ['error' => $_GET['error']]
    );

    // Also log to database for admin panel
    logOAuthToDatabase(0, 'oauth_login_failed', 'cancelled', ['error' => $_GET['error']]);

    redirect(BASE_URL . '/index.php');
}

// Check for authorization code
if (!isset($_GET['code'])) {
    redirect(BASE_URL . '/index.php');
}

// Validate CSRF state parameter
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state'])) {
    $_SESSION['error'] = 'Estado OAuth inválido';

    MarkdownLogger::getInstance()->access(
        userId: 0,
        action: 'LOGIN_FAILED',
        resource: 'OAuth',
        status: 'csrf_invalid_state',
        extraContext: ['error' => 'Missing state parameter']
    );

    // Also log to database for admin panel
    logOAuthToDatabase(0, 'oauth_login_failed', 'csrf_invalid_state', ['error' => 'Missing state parameter']);

    redirect(BASE_URL . '/index.php');
}

// Validate state matches (timing-safe comparison)
if (!hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
    $_SESSION['error'] = 'Estado OAuth inválido';
    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_state_time']);

    MarkdownLogger::getInstance()->access(
        userId: 0,
        action: 'LOGIN_FAILED',
        resource: 'OAuth',
        status: 'csrf_state_mismatch',
        extraContext: ['error' => 'State parameter mismatch']
    );

    // Also log to database for admin panel
    logOAuthToDatabase(0, 'oauth_login_failed', 'csrf_state_mismatch', ['error' => 'State parameter mismatch']);

    redirect(BASE_URL . '/index.php');
}

// Check state timeout (5 minutes)
if (time() - ($_SESSION['oauth_state_time'] ?? 0) > 300) {
    $_SESSION['error'] = 'Sessão OAuth expirada';
    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_state_time']);

    MarkdownLogger::getInstance()->access(
        userId: 0,
        action: 'LOGIN_FAILED',
        resource: 'OAuth',
        status: 'csrf_state_timeout',
        extraContext: ['error' => 'State timeout exceeded']
    );

    // Also log to database for admin panel
    logOAuthToDatabase(0, 'oauth_login_failed', 'csrf_state_timeout', ['error' => 'State timeout exceeded']);

    redirect(BASE_URL . '/index.php');
}

// Clear state after successful validation
unset($_SESSION['oauth_state']);
unset($_SESSION['oauth_state_time']);

// Handle OAuth callback
$result = $auth->handleCallback($_GET['code']);

if (!$result['success']) {
    $_SESSION['error'] = $result['error'] ?? 'Erro na autenticação';

    // Log authentication failure
    MarkdownLogger::getInstance()->access(
        userId: 0,
        action: 'LOGIN_FAILED',
        resource: 'OAuth',
        status: 'auth_error',
        extraContext: ['error' => $result['error'] ?? 'Unknown error']
    );

    // Also log to database for admin panel
    logOAuthToDatabase(0, 'oauth_login_failed', 'auth_error', ['error' => $result['error'] ?? 'Unknown error']);

    redirect(BASE_URL . '/index.php');
}

$user = $result['user'];

// Check if user needs to complete onboarding
if (!$user['completed_onboarding']) {
    redirect(BASE_URL . '/onboarding-step1.php');
}

// Check if user needs to accept terms
if ($consentManager->needsConsent($user['id'], 'terms_of_use')) {
    $_SESSION['needs_consent'] = true;
    redirect(BASE_URL . '/dashboard.php?consent=required');
}

// Successful login - redirect based on role and vertical
$vertical = $user['selected_vertical'] ?? null;
$isAdmin = ($user['access_level'] ?? 'guest') === 'admin';
$_SESSION['success'] = 'Login realizado com sucesso!';

// Log successful login (user_id is sufficient, no need for email)
MarkdownLogger::getInstance()->access(
    userId: $user['id'],
    action: 'LOGIN',
    resource: '-',
    status: 'success',
    extraContext: [
        'vertical' => $vertical,
        'is_admin' => $isAdmin
    ]
);

// Check for saved redirect URL (e.g., deep-link from email)
$savedRedirect = consume_redirect_after_login();
if ($savedRedirect) {
    redirect($savedRedirect);
}

// Admins always go to dashboard (they can access all verticals from there)
if ($isAdmin) {
    redirect(BASE_URL . '/dashboard.php');
}

// Regular users: redirect to their vertical if valid
if ($vertical && array_key_exists($vertical, VERTICALS)) {
    $verticalPath = __DIR__ . "/areas/{$vertical}/index.php";
    if (file_exists($verticalPath)) {
        redirect(BASE_URL . "/areas/{$vertical}/");
    }
}

// Default redirect
redirect(BASE_URL . '/dashboard.php');
