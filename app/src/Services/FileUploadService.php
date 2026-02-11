<?php
/**
 * File Upload Service
 *
 * Handles file uploads, validation, and storage for the Canvas system
 *
 * @package Sunyata\Services
 */

namespace Sunyata\Services;

use Sunyata\Core\Database;
use Exception;

class FileUploadService {
    private static $instance = null;
    private $db;

    // Configuration
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB in bytes

    private function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get upload base path (environment-aware)
     *
     * @return string Upload directory path
     */
    private function getUploadBasePath(): string {
        // Check if running on Hostinger production
        if (strpos(__DIR__, '/home/u202164171') !== false) {
            return '/home/u202164171/domains/sunyataconsulting.com/storage/uploads';
        }

        // Local/development environment
        return '/var/uploads';
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Upload and store a file
     *
     * @param array $fileData $_FILES array element
     * @param int $userId User ID who owns the file
     * @return array ['success' => bool, 'file_id' => int|null, 'message' => string]
     */
    public function uploadFile(array $fileData, int $userId): array {
        try {
            // Bug #6 Fix: Basic rate limiting (10 uploads per hour per user)
            $recentUploadsCount = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM user_files
                 WHERE user_id = :user_id
                 AND uploaded_at > NOW() - INTERVAL '1 hour'",
                ['user_id' => $userId]
            )['count'] ?? 0;

            if ($recentUploadsCount >= 10) {
                return [
                    'success' => false,
                    'file_id' => null,
                    'message' => 'Limite de uploads excedido. Você pode enviar até 10 arquivos por hora.'
                ];
            }

            // Validate file data structure
            if (!isset($fileData['tmp_name']) || !isset($fileData['name']) || !isset($fileData['size'])) {
                return [
                    'success' => false,
                    'file_id' => null,
                    'message' => 'Dados de arquivo inválidos'
                ];
            }

            // Check for upload errors
            if ($fileData['error'] !== UPLOAD_ERR_OK) {
                return [
                    'success' => false,
                    'file_id' => null,
                    'message' => $this->getUploadErrorMessage($fileData['error'])
                ];
            }

            // Validate file exists
            if (!is_uploaded_file($fileData['tmp_name'])) {
                return [
                    'success' => false,
                    'file_id' => null,
                    'message' => 'Arquivo não encontrado ou inválido'
                ];
            }

            // Validate reported file size
            if ($fileData['size'] > self::MAX_FILE_SIZE) {
                return [
                    'success' => false,
                    'file_id' => null,
                    'message' => 'Arquivo muito grande. Tamanho máximo: 10MB'
                ];
            }

            // Bug #7 Fix: Validate REAL file size (don't trust client data)
            $realSize = filesize($fileData['tmp_name']);
            if ($realSize > self::MAX_FILE_SIZE) {
                return [
                    'success' => false,
                    'file_id' => null,
                    'message' => 'Arquivo muito grande. Tamanho máximo: 10MB'
                ];
            }

            // Validate MIME type using finfo (more secure than extension)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $fileData['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
                return [
                    'success' => false,
                    'file_id' => null,
                    'message' => 'Tipo de arquivo não permitido. Apenas PDF e DOCX são aceitos'
                ];
            }

            // Generate unique filename
            $fileHash = hash_file('sha256', $fileData['tmp_name']);
            $timestamp = time();
            $extension = $this->getExtensionFromMime($mimeType);

            // Bug #8 Fix: Sanitize filename to prevent path traversal
            $originalName = pathinfo($fileData['name'], PATHINFO_FILENAME);
            $originalName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
            $originalName = substr($originalName, 0, 100); // Limit length

            $storedFilename = "{$fileHash}_{$timestamp}_{$originalName}.{$extension}";

            // Create directory structure: /path/to/uploads/YYYY/MM/user_id/
            $year = date('Y');
            $month = date('m');
            $uploadDir = $this->getUploadBasePath() . "/{$year}/{$month}/{$userId}";

            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    return [
                        'success' => false,
                        'file_id' => null,
                        'message' => 'Erro ao criar diretório de upload'
                    ];
                }
            }

            // Full file path
            $filePath = "{$uploadDir}/{$storedFilename}";

            // Move uploaded file
            if (!move_uploaded_file($fileData['tmp_name'], $filePath)) {
                return [
                    'success' => false,
                    'file_id' => null,
                    'message' => 'Erro ao salvar arquivo'
                ];
            }

            // Insert record in database
            // Column names match user_files table: filename, filepath, size_bytes, md5_hash, uploaded_at
            $fileId = $this->db->insert('user_files', [
                'user_id' => $userId,
                'filename' => $fileData['name'],
                'stored_filename' => $storedFilename,
                'filepath' => $filePath,
                'mime_type' => $mimeType,
                'size_bytes' => $realSize, // Bug #7 Fix: Use real size, not client-reported
                'md5_hash' => md5_file($filePath) // Required NOT NULL column
                // uploaded_at has DEFAULT current_timestamp()
            ]);

            return [
                'success' => true,
                'file_id' => (int)$fileId,
                'original_name' => $fileData['name'],
                'message' => 'Arquivo enviado com sucesso'
            ];

        } catch (Exception $e) {
            error_log('FileUploadService::uploadFile error: ' . $e->getMessage());
            return [
                'success' => false,
                'file_id' => null,
                'message' => 'Erro ao processar upload: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get file by ID (security check: user must own the file)
     *
     * @param int $fileId File ID
     * @param int $userId User ID requesting the file
     * @return array|null File data or null if not found/not owned
     */
    public function getFileById(int $fileId, int $userId): ?array {
        try {
            $file = $this->db->fetchOne(
                "SELECT * FROM user_files WHERE id = :file_id AND user_id = :user_id",
                [
                    'file_id' => $fileId,
                    'user_id' => $userId
                ]
            );

            return $file ?: null;
        } catch (Exception $e) {
            error_log('FileUploadService::getFileById error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a file (soft delete - removes from disk and database)
     *
     * @param int $fileId File ID
     * @param int $userId User ID (security check)
     * @return bool True if deleted successfully
     */
    public function deleteFile(int $fileId, int $userId): bool {
        try {
            // Get file to verify ownership and get path
            $file = $this->getFileById($fileId, $userId);

            if (!$file) {
                return false;
            }

            // Delete physical file
            if (file_exists($file['filepath'])) {
                unlink($file['filepath']);
            }

            // Delete database record
            $deleted = $this->db->delete(
                'user_files',
                'id = :file_id AND user_id = :user_id',
                [
                    'file_id' => $fileId,
                    'user_id' => $userId
                ]
            );

            return $deleted > 0;
        } catch (Exception $e) {
            error_log('FileUploadService::deleteFile error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get extension from MIME type
     *
     * @param string $mimeType MIME type
     * @return string File extension
     */
    private function getExtensionFromMime(string $mimeType): string {
        $mimeMap = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
        ];

        return $mimeMap[$mimeType] ?? 'bin';
    }

    /**
     * Get human-readable upload error message
     *
     * @param int $errorCode PHP upload error code
     * @return string Error message
     */
    private function getUploadErrorMessage(int $errorCode): string {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Arquivo excede tamanho máximo permitido pelo servidor',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo excede tamanho máximo permitido',
            UPLOAD_ERR_PARTIAL => 'Arquivo foi enviado parcialmente',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo no disco',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP'
        ];

        return $errors[$errorCode] ?? 'Erro desconhecido no upload';
    }
}
