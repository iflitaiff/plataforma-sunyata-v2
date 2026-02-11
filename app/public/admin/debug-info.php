<?php
/**
 * Debug Info - Informações detalhadas para troubleshooting
 * REMOVER ESTE ARQUIVO APÓS DEBUG!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Core\Settings;

// Verificar se é admin
$isAdmin = isset($_SESSION['user']['access_level']) && $_SESSION['user']['access_level'] === 'admin';

header('Content-Type: text/plain; charset=utf-8');

echo "=================================================\n";
echo "🔍 DEBUG INFO - Plataforma Sunyata\n";
echo "=================================================\n\n";

echo "📅 Data/Hora: " . date('Y-m-d H:i:s') . "\n";
echo "👤 Admin: " . ($isAdmin ? 'SIM' : 'NÃO') . "\n";
if (isset($_SESSION['user'])) {
    echo "   User ID: " . $_SESSION['user']['id'] . "\n";
    echo "   Email: " . $_SESSION['user']['email'] . "\n";
}
echo "\n";

echo "=== PHP INFO ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "OPcache: " . (function_exists('opcache_get_status') ? 'ENABLED' : 'DISABLED') . "\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "OPcache Enabled: " . ($status['opcache_enabled'] ? 'YES' : 'NO') . "\n";
}
echo "\n";

echo "=== FILE SYSTEM ===\n";
echo "Vendor exists: " . (file_exists(__DIR__ . '/../../vendor/autoload.php') ? '✅ YES' : '❌ NO') . "\n";
echo "Config exists: " . (file_exists(__DIR__ . '/../../config/config.php') ? '✅ YES' : '❌ NO') . "\n";
echo "Settings.php exists: " . (file_exists(__DIR__ . '/../../src/Core/Settings.php') ? '✅ YES' : '❌ NO') . "\n";
echo "Database.php exists: " . (file_exists(__DIR__ . '/../../src/Core/Database.php') ? '✅ YES' : '❌ NO') . "\n";
echo "UserDeletionService.php exists: " . (file_exists(__DIR__ . '/../../src/Admin/UserDeletionService.php') ? '✅ YES' : '❌ NO') . "\n";
echo "\n";

echo "=== CLASS LOADING ===\n";
try {
    echo "Database class: " . (class_exists('Sunyata\\Core\\Database') ? '✅ LOADED' : '❌ NOT FOUND') . "\n";
    echo "Settings class: " . (class_exists('Sunyata\\Core\\Settings') ? '✅ LOADED' : '❌ NOT FOUND') . "\n";
    echo "UserDeletionService class: " . (class_exists('Sunyata\\Admin\\UserDeletionService') ? '✅ LOADED' : '❌ NOT FOUND') . "\n";
} catch (Exception $e) {
    echo "❌ ERROR loading classes: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== DATABASE CONNECTION ===\n";
try {
    $db = Database::getInstance();
    echo "✅ Database connected\n";

    $result = $db->fetchOne("SELECT COUNT(*) as count FROM users");
    echo "   Total users: " . $result['count'] . "\n";

    $result = $db->fetchOne("SELECT COUNT(*) as count FROM settings");
    echo "   Total settings: " . $result['count'] . "\n";
} catch (Exception $e) {
    echo "❌ Database ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== SETTINGS SYSTEM ===\n";
try {
    $settings = Settings::getInstance();
    echo "✅ Settings instantiated\n";

    $juridico = $settings->get('juridico_requires_approval');
    echo "   juridico_requires_approval: " . ($juridico ? 'TRUE' : 'FALSE') . "\n";

    $platform = $settings->get('platform_name');
    echo "   platform_name: " . $platform . "\n";

    $max = $settings->get('max_users_per_vertical');
    echo "   max_users_per_vertical: " . $max . "\n";
} catch (Exception $e) {
    echo "❌ Settings ERROR: " . $e->getMessage() . "\n";
    echo "   Stack: " . $e->getTraceAsString() . "\n";
}
echo "\n";

echo "=== ADMIN INDEX.PHP CHECK ===\n";
$indexPath = __DIR__ . '/index.php';
$indexContent = file_get_contents($indexPath);
echo "Admin index.php size: " . strlen($indexContent) . " bytes\n";
echo "Contains 'use Sunyata\\Core\\Settings': " . (strpos($indexContent, 'use Sunyata\\Core\\Settings') !== false ? '✅ YES' : '❌ NO') . "\n";
echo "Contains 'Configurações Rápidas': " . (strpos($indexContent, 'Configurações Rápidas') !== false ? '✅ YES' : '❌ NO') . "\n";
echo "Contains 'toggle_juridico_approval': " . (strpos($indexContent, 'toggle_juridico_approval') !== false ? '✅ YES' : '❌ NO') . "\n";
echo "\n";

echo "=== USERS.PHP CHECK ===\n";
$usersPath = __DIR__ . '/users.php';
$usersContent = file_get_contents($usersPath);
echo "Admin users.php size: " . strlen($usersContent) . " bytes\n";
echo "Contains 'UserDeletionService': " . (strpos($usersContent, 'UserDeletionService') !== false ? '✅ YES' : '❌ NO') . "\n";
echo "Contains 'confirmDelete': " . (strpos($usersContent, 'confirmDelete') !== false ? '✅ YES' : '❌ NO') . "\n";
echo "\n";

echo "=== FILE TIMESTAMPS ===\n";
echo "index.php modified: " . date('Y-m-d H:i:s', filemtime($indexPath)) . "\n";
echo "users.php modified: " . date('Y-m-d H:i:s', filemtime($usersPath)) . "\n";
echo "Settings.php modified: " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/../../src/Core/Settings.php')) . "\n";
echo "\n";

echo "=================================================\n";
echo "✅ Debug info complete!\n";
echo "=================================================\n";
