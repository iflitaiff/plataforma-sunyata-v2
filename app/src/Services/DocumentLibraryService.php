<?php
/**
 * Document Library Service — manages user_documents (permanent document storage).
 *
 * Handles upload, promotion from user_files, CRUD, search, and picker queries.
 *
 * @package Sunyata\Services
 */

namespace Sunyata\Services;

use Sunyata\Core\Database;

class DocumentLibraryService
{
    private Database $db;
    private string $storagePath;

    /** Max file size: 20MB */
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /** Allowed MIME types for upload */
    private const ALLOWED_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'text/plain',
        'text/csv',
        'text/markdown',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->storagePath = defined('STORAGE_PATH')
            ? STORAGE_PATH . '/documents'
            : dirname(__DIR__, 2) . '/storage/documents';
    }

    /**
     * Upload a new document directly.
     *
     * @param array $file $_FILES entry
     * @return array ['success' => bool, 'document_id' => int|null, 'error' => string|null]
     */
    public function uploadDocument(int $userId, array $file, array $tags = []): array
    {
        // Validate
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'document_id' => null, 'error' => 'Erro no upload: código ' . $file['error']];
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return ['success' => false, 'document_id' => null, 'error' => 'Arquivo excede o limite de 20MB'];
        }

        $mimeType = $file['type'] ?? mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, self::ALLOWED_TYPES)) {
            return ['success' => false, 'document_id' => null, 'error' => 'Tipo de arquivo nao permitido: ' . $mimeType];
        }

        // Ensure storage directory exists
        $userDir = $this->storagePath . '/' . $userId;
        if (!is_dir($userDir)) {
            mkdir($userDir, 0750, true);
        }

        // Generate unique stored filename
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $storedFilename = uniqid('doc_', true) . ($ext ? '.' . $ext : '');
        $storedPath = $userDir . '/' . $storedFilename;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            return ['success' => false, 'document_id' => null, 'error' => 'Falha ao salvar arquivo'];
        }

        // Insert record
        $documentId = $this->db->insert('user_documents', [
            'user_id' => $userId,
            'filename' => mb_substr($file['name'], 0, 255),
            'stored_filename' => $storedFilename,
            'mime_type' => $mimeType,
            'file_size' => $file['size'],
            'storage_path' => $storedPath,
            'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'extraction_status' => 'pending',
        ]);

        return ['success' => true, 'document_id' => $documentId, 'error' => null];
    }

    /**
     * Promote a temporary file from user_files to permanent document library.
     */
    public function promoteFromUpload(int $fileId, int $userId, array $tags = []): ?int
    {
        $file = $this->db->fetchOne("
            SELECT * FROM user_files WHERE id = :id AND user_id = :user_id
        ", ['id' => $fileId, 'user_id' => $userId]);

        if (!$file) return null;

        // Check if already promoted (same md5_hash)
        $existing = $this->db->fetchOne("
            SELECT id FROM user_documents
            WHERE user_id = :user_id AND metadata->>'source_file_id' = :file_id
        ", ['user_id' => $userId, 'file_id' => (string)$fileId]);

        if ($existing) return (int)$existing['id'];

        // Copy file to documents storage
        $userDir = $this->storagePath . '/' . $userId;
        if (!is_dir($userDir)) {
            mkdir($userDir, 0750, true);
        }

        $ext = pathinfo($file['filename'], PATHINFO_EXTENSION);
        $storedFilename = uniqid('doc_', true) . ($ext ? '.' . $ext : '');
        $storedPath = $userDir . '/' . $storedFilename;

        $sourcePath = $file['filepath'];
        if ($sourcePath && file_exists($sourcePath)) {
            copy($sourcePath, $storedPath);
        }

        return $this->db->insert('user_documents', [
            'user_id' => $userId,
            'filename' => $file['filename'],
            'stored_filename' => $storedFilename,
            'mime_type' => $file['mime_type'] ?? 'application/octet-stream',
            'file_size' => $file['size_bytes'] ?? 0,
            'storage_path' => $storedPath,
            'extracted_text' => $file['extracted_text'],
            'extraction_status' => $file['extracted_text'] ? 'completed' : 'pending',
            'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'metadata' => json_encode([
                'source_file_id' => $fileId,
                'md5_hash' => $file['md5_hash'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Get paginated documents for a user with filters.
     *
     * @param array $filters Keys: mime_type, search, is_archived
     * @return array ['items' => [...], 'total' => int]
     */
    public function getUserDocuments(
        int $userId,
        int $limit = 20,
        int $offset = 0,
        array $filters = []
    ): array {
        $where = ['ud.user_id = :user_id'];
        $params = ['user_id' => $userId];

        // Default: exclude archived
        if (empty($filters['is_archived'])) {
            $where[] = 'ud.is_archived = FALSE';
        }

        if (!empty($filters['mime_type'])) {
            $where[] = 'ud.mime_type = :mime_type';
            $params['mime_type'] = $filters['mime_type'];
        }

        if (!empty($filters['search'])) {
            $where[] = "ud.search_vector @@ plainto_tsquery('portuguese', :search)";
            $params['search'] = $filters['search'];
        }

        $whereClause = implode(' AND ', $where);

        $countRow = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM user_documents ud WHERE {$whereClause}",
            $params
        );
        $total = $countRow['total'] ?? 0;

        $items = $this->db->fetchAll("
            SELECT ud.id, ud.filename, ud.mime_type, ud.file_size,
                   ud.extraction_status, ud.tags, ud.is_archived,
                   ud.created_at, ud.updated_at
            FROM user_documents ud
            WHERE {$whereClause}
            ORDER BY ud.created_at DESC
            LIMIT :limit OFFSET :offset
        ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get a single document.
     */
    public function getDocument(int $documentId, int $userId): ?array
    {
        return $this->db->fetchOne("
            SELECT * FROM user_documents
            WHERE id = :id AND user_id = :user_id
        ", ['id' => $documentId, 'user_id' => $userId]) ?: null;
    }

    /**
     * Search documents by full-text.
     */
    public function searchDocuments(int $userId, string $query, int $limit = 20): array
    {
        return $this->db->fetchAll("
            SELECT ud.id, ud.filename, ud.mime_type, ud.file_size,
                   ud.extraction_status, ud.created_at,
                   ts_rank(ud.search_vector, plainto_tsquery('portuguese', :query)) as rank
            FROM user_documents ud
            WHERE ud.user_id = :user_id
              AND ud.search_vector @@ plainto_tsquery('portuguese', :query)
              AND ud.is_archived = FALSE
            ORDER BY rank DESC
            LIMIT :limit
        ", ['user_id' => $userId, 'query' => $query, 'limit' => $limit]);
    }

    /**
     * Delete a document (removes file and record).
     */
    public function deleteDocument(int $documentId, int $userId): bool
    {
        $doc = $this->getDocument($documentId, $userId);
        if (!$doc) return false;

        // Delete file
        if (!empty($doc['storage_path']) && file_exists($doc['storage_path'])) {
            unlink($doc['storage_path']);
        }

        // Delete record (cascade removes submission_documents links)
        $this->db->execute(
            "DELETE FROM user_documents WHERE id = :id AND user_id = :user_id",
            ['id' => $documentId, 'user_id' => $userId]
        );

        return true;
    }

    /**
     * Get documents for the picker (filtered by accepted MIME types).
     */
    public function getDocumentsForPicker(int $userId, array $acceptedTypes = [], int $limit = 50): array
    {
        $where = ['ud.user_id = :user_id', 'ud.is_archived = FALSE'];
        $params = ['user_id' => $userId, 'limit' => $limit];

        if (!empty($acceptedTypes)) {
            $placeholders = [];
            foreach ($acceptedTypes as $i => $type) {
                $key = 'type_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $type;
            }
            $where[] = 'ud.mime_type IN (' . implode(',', $placeholders) . ')';
        }

        $whereClause = implode(' AND ', $where);

        return $this->db->fetchAll("
            SELECT ud.id, ud.filename, ud.mime_type, ud.file_size,
                   ud.extraction_status, ud.created_at
            FROM user_documents ud
            WHERE {$whereClause}
            ORDER BY ud.created_at DESC
            LIMIT :limit
        ", $params);
    }

    /**
     * Update extracted text (called after FastAPI processing).
     */
    public function updateExtractedText(int $documentId, string $text, string $status = 'completed'): void
    {
        $this->db->update('user_documents', [
            'extracted_text' => $text,
            'extraction_status' => $status,
        ], 'id = :id', ['id' => $documentId]);
    }

    /**
     * Count user documents (for dashboard stat).
     */
    public function countUserDocuments(int $userId): int
    {
        $row = $this->db->fetchOne("
            SELECT COUNT(*) as count FROM user_documents
            WHERE user_id = :user_id AND is_archived = FALSE
        ", ['user_id' => $userId]);
        return (int)($row['count'] ?? 0);
    }

    /**
     * Format file size for display.
     */
    public static function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    /**
     * Get icon class for a MIME type.
     */
    public static function mimeIcon(string $mimeType): string
    {
        return match(true) {
            str_contains($mimeType, 'pdf') => 'ti-file-type-pdf text-danger',
            str_contains($mimeType, 'word') || str_contains($mimeType, 'document') => 'ti-file-type-doc text-primary',
            str_contains($mimeType, 'spreadsheet') || str_contains($mimeType, 'excel') => 'ti-file-type-xls text-success',
            str_contains($mimeType, 'text') => 'ti-file-type-txt text-secondary',
            str_contains($mimeType, 'csv') => 'ti-file-type-csv text-info',
            default => 'ti-file text-secondary',
        };
    }
}
