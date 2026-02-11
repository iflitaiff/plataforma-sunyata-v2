<?php
/**
 * API: File Upload for Canvas
 * Processa upload de arquivos do SurveyJS
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Services\FileUploadService;

// Debug logger
function uploadDebugLog($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = '/tmp/canvas-upload-debug.log';
    $entry = "[$timestamp] $message";
    if ($data !== null) {
        $entry .= "\n" . print_r($data, true);
    }
    $entry .= "\n---\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

uploadDebugLog("=== UPLOAD REQUEST START ===");
uploadDebugLog("Method: " . $_SERVER['REQUEST_METHOD']);
uploadDebugLog("Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
uploadDebugLog("FILES array:", $_FILES);

// Headers
header('Content-Type: application/json; charset=utf-8');

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    uploadDebugLog("ERROR: Not authenticated");
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadDebugLog("ERROR: Method not allowed");
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Verificar se tem arquivo
if (!isset($_FILES['file'])) {
    uploadDebugLog("ERROR: No file sent");
    http_response_code(400);
    echo json_encode(['error' => 'Nenhum arquivo enviado']);
    exit;
}

try {
    $fileService = FileUploadService::getInstance();
    uploadDebugLog("FileUploadService obtained");

    // Upload do arquivo (FileUploadService já faz todas as validações)
    $result = $fileService->uploadFile(
        fileData: $_FILES['file'],
        userId: (int)$_SESSION['user_id']
    );

    uploadDebugLog("Upload result:", $result);

    if ($result['success']) {
        // Formato esperado pelo SurveyJS
        $response = [
            'file' => [
                'name' => $result['original_name'] ?? ('file_' . $result['file_id']),
                'type' => $_FILES['file']['type'] ?? 'application/octet-stream',
                'content' => (string)$result['file_id'], // SurveyJS armazena ID no form data
            ]
        ];
        uploadDebugLog("SUCCESS response:", $response);
        echo json_encode($response);
    } else {
        uploadDebugLog("UPLOAD FAILED:", $result);
        http_response_code(400);
        echo json_encode(['error' => $result['message'] ?? 'Erro ao fazer upload']);
    }

} catch (Exception $e) {
    uploadDebugLog("EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    error_log('File upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
