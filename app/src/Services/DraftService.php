<?php

namespace Sunyata\Services;

use Sunyata\Core\Database;

class DraftService
{
    private Database $db;

    private const MAX_DRAFTS_PER_TEMPLATE = 10;
    private const MAX_PAYLOAD_BYTES = 1048576; // 1 MB
    private const TTL_DAYS = 90;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create or update a draft.
     *
     * @return int Draft ID
     * @throws \Exception on limit exceeded or payload too large
     */
    public function saveDraft(
        int $userId,
        int $canvasTemplateId,
        array $formData,
        int $pageNo = 0,
        ?string $label = null,
        ?int $draftId = null
    ): int {
        // Payload size check
        $encoded = json_encode($formData, JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new \Exception('Payload excede 1 MB', 413);
        }

        // Update existing draft
        if ($draftId !== null) {
            // Verify ownership
            $existing = $this->db->fetchOne(
                'SELECT id FROM form_drafts WHERE id = :id AND user_id = :user_id',
                ['id' => $draftId, 'user_id' => $userId]
            );

            if (!$existing) {
                throw new \Exception('Rascunho não encontrado', 404);
            }

            $updateData = [
                'form_data' => $encoded,
                'page_no' => $pageNo,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::TTL_DAYS . ' days')),
            ];

            if ($label !== null) {
                $updateData['label'] = mb_substr(trim($label), 0, 255);
            }

            $this->db->update('form_drafts', $updateData, 'id = :id', ['id' => $draftId]);

            return $draftId;
        }

        // Insert new draft — check limit first
        $count = $this->countDrafts($userId, $canvasTemplateId);
        if ($count >= self::MAX_DRAFTS_PER_TEMPLATE) {
            throw new \Exception('Limite de ' . self::MAX_DRAFTS_PER_TEMPLATE . ' rascunhos atingido', 409);
        }

        $autoLabel = $label
            ? mb_substr(trim($label), 0, 255)
            : 'Rascunho ' . date('d/m H:i');

        return (int) $this->db->insert('form_drafts', [
            'user_id' => $userId,
            'canvas_template_id' => $canvasTemplateId,
            'label' => $autoLabel,
            'form_data' => $encoded,
            'page_no' => $pageNo,
        ]);
    }

    /**
     * List drafts for a user + template, ordered by most recent.
     * Includes a preview of the first 3 filled fields.
     */
    public function listDrafts(int $userId, int $canvasTemplateId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, label, page_no, updated_at, form_data
             FROM form_drafts
             WHERE user_id = :user_id
               AND canvas_template_id = :template_id
               AND expires_at > NOW()
             ORDER BY updated_at DESC',
            ['user_id' => $userId, 'template_id' => $canvasTemplateId]
        );

        return array_map(function ($row) {
            $formData = json_decode($row['form_data'], true) ?: [];
            $preview = $this->buildPreview($formData);

            return [
                'id' => (int) $row['id'],
                'label' => $row['label'],
                'page_no' => (int) $row['page_no'],
                'updated_at' => $row['updated_at'],
                'preview' => $preview,
            ];
        }, $rows);
    }

    /**
     * Load a single draft with full form_data.
     */
    public function loadDraft(int $draftId, int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, label, form_data, page_no, updated_at
             FROM form_drafts
             WHERE id = :id AND user_id = :user_id AND expires_at > NOW()',
            ['id' => $draftId, 'user_id' => $userId]
        );

        if (!$row) {
            return null;
        }

        $row['form_data'] = json_decode($row['form_data'], true) ?: [];
        $row['id'] = (int) $row['id'];
        $row['page_no'] = (int) $row['page_no'];

        return $row;
    }

    /**
     * Delete a draft (IDOR-safe).
     */
    public function deleteDraft(int $draftId, int $userId): bool
    {
        $rows = $this->db->delete(
            'form_drafts',
            'id = :id AND user_id = :user_id',
            ['id' => $draftId, 'user_id' => $userId]
        );

        return $rows > 0;
    }

    /**
     * Rename a draft (IDOR-safe).
     */
    public function renameDraft(int $draftId, int $userId, string $newLabel): bool
    {
        $newLabel = mb_substr(trim($newLabel), 0, 255);
        if ($newLabel === '') {
            return false;
        }

        $rows = $this->db->update(
            'form_drafts',
            ['label' => $newLabel],
            'id = :id AND user_id = :user_id',
            ['id' => $draftId, 'user_id' => $userId]
        );

        return $rows > 0;
    }

    /**
     * Count drafts for a user + template.
     */
    public function countDrafts(int $userId, int $canvasTemplateId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM form_drafts
             WHERE user_id = :user_id AND canvas_template_id = :template_id AND expires_at > NOW()',
            ['user_id' => $userId, 'template_id' => $canvasTemplateId]
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Delete expired drafts. Returns number of rows deleted.
     */
    public function cleanExpired(): int
    {
        return $this->db->delete('form_drafts', 'expires_at < NOW()', []);
    }

    /**
     * Build a preview string from the first 3 non-empty fields.
     */
    private function buildPreview(array $formData): string
    {
        $parts = [];
        foreach ($formData as $key => $value) {
            if (count($parts) >= 3) {
                break;
            }
            if (is_string($value) && trim($value) !== '') {
                $parts[] = mb_substr(trim($value), 0, 50);
            } elseif (is_array($value) && !empty($value)) {
                $parts[] = '[' . count($value) . ' itens]';
            }
        }

        return implode(' | ', $parts);
    }
}
