<?php
/**
 * Limpar OPcache do PHP
 * RESTRITO: Apenas administradores
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

// Verificar autenticação
require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Acesso restrito a administradores.</p>');
}

// Limpar OPcache do PHP
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ OPcache limpo com sucesso!<br>";
} else {
    echo "⚠️ OPcache não está disponível<br>";
}

// Limpar outros caches
if (function_exists('apc_clear_cache')) {
    apc_clear_cache();
    echo "✅ APC cache limpo!<br>";
}

// Informações sobre o cache
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    echo "<h3>Status do OPcache:</h3>";
    echo "<pre>";
    print_r($status);
    echo "</pre>";
}

echo "<hr>";
echo "<p><a href='/areas/pesquisa/'>← Voltar para Pesquisa</a></p>";
