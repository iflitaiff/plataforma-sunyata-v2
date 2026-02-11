<?php
/**
 * API Endpoint: Upload de Arquivo
 *
 * Upload de arquivo (PDF/DOCX) com processamento automático (extração de texto)
 *
 * Method: POST
 * Content-Type: multipart/form-data
 * Headers Required: X-CSRF-Token
 *
 * @return JSON
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';

use App\Services\FileUploadService;
use App\Services\DocumentProcessorService;

// Start session for authentication and CSRF
session_start();

// Set JSON response header
header('Content-Type: application/json');

try {
    // 1. Validate HTTP method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed',
            'message' => 'Only POST requests are accepted'
        ]);
        exit;
    }

    // 2. Validate authentication
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'User not authenticated'
        ]);
        exit;
    }

    // 3. Validate CSRF token
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'CSRF validation failed',
            'message' => 'Invalid or missing CSRF token'
        ]);
        exit;
    }

    // 4. Validate file upload
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No file uploaded',
            'message' => 'The "file" field is required'
        ]);
        exit;
    }

    $file = $_FILES['file'];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];

        $errorMessage = $errorMessages[$file['error']] ?? 'Unknown upload error';

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Upload failed',
            'message' => $errorMessage
        ]);
        exit;
    }

    // 5. Upload file using FileUploadService
    $userId = (int) $_SESSION['user_id'];
    $fileUploadService = FileUploadService::getInstance();

    $uploadResult = $fileUploadService->uploadFile($file, $userId);

    // Check if upload was successful
    if (!$uploadResult['success']) {
        // Determine appropriate HTTP status code based on error message
        $statusCode = 400; // Default

        if (strpos($uploadResult['message'], 'Rate limit') !== false) {
            $statusCode = 429;
        } elseif (strpos($uploadResult['message'], 'too large') !== false) {
            $statusCode = 413;
        } elseif (strpos($uploadResult['message'], 'Invalid file type') !== false) {
            $statusCode = 400;
        }

        http_response_code($statusCode);
        echo json_encode($uploadResult);
        exit;
    }

    $fileId = $uploadResult['file_id'];

    // 6. Extract text using DocumentProcessorService
    $docProcessor = DocumentProcessorService::getInstance();
    $extractResult = $docProcessor->extractText($fileId, $userId);

    $extractedText = null;
    if ($extractResult['success']) {
        $rawText = $extractResult['text'] ?? '';

        // Bug #2 Fix: Validate extracted text length
        $maxTextLength = 100000; // 100KB of text (sufficient for ~50 pages)

        if (strlen($rawText) > $maxTextLength) {
            $extractedText = substr($rawText, 0, $maxTextLength);
            $extractedText .= "\n\n[...texto truncado devido ao tamanho. Original: " . strlen($rawText) . " caracteres]";
            error_log("Text extraction truncated for file_id {$fileId}: original size " . strlen($rawText) . " bytes");
        } else {
            $extractedText = $rawText;
        }
    } else {
        // Log extraction failure but don't fail the upload
        error_log("Text extraction failed for file_id {$fileId}: " . ($extractResult['message'] ?? 'Unknown error'));
    }

    // 7. Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'file_id' => $fileId,
        'filename' => $uploadResult['filename'],
        'original_name' => $uploadResult['original_name'],
        'file_size' => $uploadResult['file_size'],
        'mime_type' => $uploadResult['mime_type'],
        'extracted_text' => $extractedText,
        'upload_date' => $uploadResult['upload_date']
    ]);

} catch (Exception $e) {
    // Log unexpected errors
    error_log('API Error (upload-file.php): ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'An unexpected error occurred while processing your request'
    ]);
}
