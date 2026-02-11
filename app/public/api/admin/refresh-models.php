<?php
/**
 * API Admin - Refresh do cache de modelos Claude
 *
 * POST /api/admin/refresh-models.php
 * Requer sessão admin autenticada.
 *
 * @package Sunyata
 * @since 2026-01-27
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

header('Content-Type: application/json; charset=utf-8');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido. Use POST.']);
    exit;
}

// Verificar admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado. Área restrita a administradores.']);
    exit;
}

use Sunyata\AI\ModelService;

try {
    $modelService = ModelService::getInstance();
    $success = $modelService->refreshCache();

    if ($success) {
        $models = $modelService->getAvailableModels();
        $cacheInfo = $modelService->getCacheInfo();

        echo json_encode([
            'success' => true,
            'count' => count($models),
            'models' => $models,
            'cached_at' => $cacheInfo['updated_at'],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Falha ao buscar modelos da API Anthropic. Verifique os logs.',
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('refresh-models.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno: ' . $e->getMessage(),
    ]);
}
