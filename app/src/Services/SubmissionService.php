<?php
/**
 * Submission Service — manages user_submissions (workspace / "Meu Trabalho").
 *
 * Handles CRUD, search, versioning, and integration with prompt_history.
 *
 * @package Sunyata\Services
 */

namespace Sunyata\Services;

use Sunyata\Core\Database;

class SubmissionService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new submission (status: pending).
     * Called at the start of form processing, before Claude API call.
     *
     * @return int Submission ID
     */
    public function createSubmission(
        int $userId,
        int $canvasTemplateId,
        string $verticalSlug,
        array $formData,
        ?string $title = null,
        ?int $parentId = null
    ): int {
        // Auto-generate title from first meaningful field
        if (!$title) {
            $title = $this->generateTitle($formData);
        }

        return $this->db->insert('user_submissions', [
            'user_id' => $userId,
            'canvas_template_id' => $canvasTemplateId,
            'vertical_slug' => $verticalSlug,
            'title' => mb_substr($title, 0, 500),
            'form_data' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'parent_id' => $parentId,
        ]);
    }

    /**
     * Complete a submission with the Claude response.
     */
    public function completeSubmission(
        int $submissionId,
        string $resultMarkdown,
        ?int $promptHistoryId = null,
        array $resultMetadata = []
    ): void {
        $this->db->update('user_submissions', [
            'result_markdown' => $resultMarkdown,
            'prompt_history_id' => $promptHistoryId,
            'result_metadata' => json_encode($resultMetadata, JSON_UNESCAPED_UNICODE),
            'status' => 'completed',
        ], 'id = :id', ['id' => $submissionId]);
    }

    /**
     * Mark submission as error.
     */
    public function failSubmission(int $submissionId, string $errorMessage): void
    {
        $this->db->update('user_submissions', [
            'status' => 'error',
            'result_metadata' => json_encode(['error' => $errorMessage], JSON_UNESCAPED_UNICODE),
        ], 'id = :id', ['id' => $submissionId]);
    }

    /**
     * Get a single submission (with canvas info).
     */
    public function getSubmission(int $submissionId, int $userId): ?array
    {
        return $this->db->fetchOne("
            SELECT us.*, ct.name as canvas_name, ct.slug as canvas_slug
            FROM user_submissions us
            JOIN canvas_templates ct ON ct.id = us.canvas_template_id
            WHERE us.id = :id AND us.user_id = :user_id
        ", ['id' => $submissionId, 'user_id' => $userId]) ?: null;
    }

    /**
     * Get paginated submissions for a user with filters.
     *
     * @param array $filters Keys: vertical, canvas_id, status, search, period
     * @return array ['items' => [...], 'total' => int]
     */
    public function getUserSubmissions(
        int $userId,
        int $limit = 20,
        int $offset = 0,
        array $filters = []
    ): array {
        $where = ['us.user_id = :user_id', "us.status != 'draft'"];
        $params = ['user_id' => $userId];

        if (!empty($filters['vertical'])) {
            $where[] = 'us.vertical_slug = :vertical';
            $params['vertical'] = $filters['vertical'];
        }

        if (!empty($filters['canvas_id'])) {
            $where[] = 'us.canvas_template_id = :canvas_id';
            $params['canvas_id'] = (int)$filters['canvas_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'us.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = "us.search_vector @@ plainto_tsquery('portuguese', :search)";
            $params['search'] = $filters['search'];
        }

        if (!empty($filters['period']) && $filters['period'] === 'month') {
            $where[] = "us.created_at >= date_trunc('month', NOW())";
        }

        if (!empty($filters['is_favorite'])) {
            $where[] = 'us.is_favorite = TRUE';
        }

        $whereClause = implode(' AND ', $where);

        // Count
        $countRow = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM user_submissions us WHERE {$whereClause}",
            $params
        );
        $total = $countRow['total'] ?? 0;

        // Items
        $items = $this->db->fetchAll("
            SELECT us.id, us.title, us.vertical_slug, us.status, us.is_favorite,
                   us.created_at, us.updated_at,
                   ct.name as canvas_name, ct.slug as canvas_slug, ct.icon as canvas_icon
            FROM user_submissions us
            JOIN canvas_templates ct ON ct.id = us.canvas_template_id
            WHERE {$whereClause}
            ORDER BY us.created_at DESC
            LIMIT :limit OFFSET :offset
        ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get recent submissions for a specific canvas (for sidebar in form.php).
     */
    public function getRecentByCanvas(int $userId, int $canvasTemplateId, int $limit = 5): array
    {
        return $this->db->fetchAll("
            SELECT id, title, status, created_at
            FROM user_submissions
            WHERE user_id = :user_id
              AND canvas_template_id = :canvas_id
              AND status IN ('completed', 'error')
            ORDER BY created_at DESC
            LIMIT :limit
        ", [
            'user_id' => $userId,
            'canvas_id' => $canvasTemplateId,
            'limit' => $limit,
        ]);
    }

    /**
     * Get form_data from a submission (for session reuse).
     */
    public function getSubmissionData(int $submissionId, int $userId): ?array
    {
        $row = $this->db->fetchOne("
            SELECT form_data FROM user_submissions
            WHERE id = :id AND user_id = :user_id
        ", ['id' => $submissionId, 'user_id' => $userId]);

        if (!$row) return null;

        $formData = $row['form_data'];
        if (is_string($formData)) {
            $formData = json_decode($formData, true);
        }
        return $formData;
    }

    /**
     * Search submissions by full-text.
     */
    public function searchSubmissions(int $userId, string $query, int $limit = 20): array
    {
        return $this->db->fetchAll("
            SELECT us.id, us.title, us.vertical_slug, us.status, us.created_at,
                   ct.name as canvas_name,
                   ts_rank(us.search_vector, plainto_tsquery('portuguese', :query)) as rank
            FROM user_submissions us
            JOIN canvas_templates ct ON ct.id = us.canvas_template_id
            WHERE us.user_id = :user_id
              AND us.search_vector @@ plainto_tsquery('portuguese', :query)
              AND us.status != 'draft'
            ORDER BY rank DESC
            LIMIT :limit
        ", ['user_id' => $userId, 'query' => $query, 'limit' => $limit]);
    }

    /**
     * Resubmit: create a new submission linked to original via parent_id.
     */
    public function resubmit(int $originalId, int $userId): ?int
    {
        $original = $this->getSubmission($originalId, $userId);
        if (!$original) return null;

        $formData = $original['form_data'];
        if (is_string($formData)) {
            $formData = json_decode($formData, true);
        }

        // The parent_id points to the root of the chain
        $parentId = $original['parent_id'] ?: $original['id'];

        return $this->createSubmission(
            $userId,
            (int)$original['canvas_template_id'],
            $original['vertical_slug'],
            $formData,
            $original['title'] . ' (v' . ($this->countVersions($parentId) + 1) . ')',
            $parentId
        );
    }

    /**
     * Toggle favorite status.
     */
    public function toggleFavorite(int $submissionId, int $userId): bool
    {
        $current = $this->db->fetchOne(
            "SELECT is_favorite FROM user_submissions WHERE id = :id AND user_id = :user_id",
            ['id' => $submissionId, 'user_id' => $userId]
        );

        if (!$current) return false;

        $newValue = !$current['is_favorite'];
        $this->db->update(
            'user_submissions',
            ['is_favorite' => $newValue ? 'true' : 'false'],
            'id = :id',
            ['id' => $submissionId]
        );

        return $newValue;
    }

    /**
     * Archive a submission.
     */
    public function archiveSubmission(int $submissionId, int $userId): bool
    {
        $rows = $this->db->update(
            'user_submissions',
            ['status' => 'archived'],
            'id = :id AND user_id = :user_id',
            ['id' => $submissionId, 'user_id' => $userId]
        );
        return $rows > 0;
    }

    /**
     * Count monthly submissions for a user.
     */
    public function countMonthly(int $userId): int
    {
        $row = $this->db->fetchOne("
            SELECT COUNT(*) as count FROM user_submissions
            WHERE user_id = :user_id
              AND created_at >= date_trunc('month', NOW())
              AND status != 'draft'
        ", ['user_id' => $userId]);
        return (int)($row['count'] ?? 0);
    }

    // --- Private helpers ---

    private function generateTitle(array $formData): string
    {
        // Try to find a meaningful field value for the title
        $candidates = ['titulo', 'title', 'nome', 'name', 'assunto', 'subject', 'tema', 'objetivo'];

        foreach ($candidates as $key) {
            if (!empty($formData[$key]) && is_string($formData[$key])) {
                return mb_substr(trim($formData[$key]), 0, 200);
            }
        }

        // Fallback: first non-empty string field
        foreach ($formData as $value) {
            if (is_string($value) && strlen(trim($value)) > 3) {
                return mb_substr(trim($value), 0, 200);
            }
        }

        return 'Submissao ' . date('d/m/Y H:i');
    }

    private function countVersions(int $parentId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM user_submissions WHERE parent_id = :parent_id",
            ['parent_id' => $parentId]
        );
        return (int)($row['count'] ?? 0);
    }
}
