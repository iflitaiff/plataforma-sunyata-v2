<?php
/**
 * Canvas Service
 *
 * Centraliza operações de canvas templates e suas associações com verticais.
 * Usa tabela junction canvas_vertical_assignments para many-to-many relationship.
 *
 * @package Sunyata\Services
 * @since 2026-02-19 (Phase 3.5 Part 2)
 */

namespace Sunyata\Services;

use Sunyata\Core\Database;

class CanvasService
{
    private static ?CanvasService $instance = null;
    private Database $db;
    private array $cache = [];

    private function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function getInstance(): CanvasService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get all canvas templates assigned to a specific vertical
     *
     * @param string $verticalSlug Vertical slug (e.g., 'iatr', 'legal')
     * @param bool $activeOnly Return only active canvas
     * @return array Array of canvas templates
     */
    public function getByVertical(string $verticalSlug, bool $activeOnly = true): array
    {
        $cacheKey = "vertical_{$verticalSlug}_" . ($activeOnly ? 'active' : 'all');

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $activeFilter = $activeOnly ? 'AND ct.is_active = true' : '';

        $sql = "
            SELECT DISTINCT
                ct.*,
                cva.display_order,
                cva.vertical_slug
            FROM canvas_templates ct
            INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
            WHERE cva.vertical_slug = :vertical_slug
              {$activeFilter}
            ORDER BY cva.display_order ASC, ct.name ASC
        ";

        $result = $this->db->fetchAll($sql, ['vertical_slug' => $verticalSlug]);
        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Get all verticals assigned to a canvas
     *
     * @param int $canvasId Canvas template ID
     * @return array Array of vertical slugs
     */
    public function getAssignedVerticals(int $canvasId): array
    {
        $sql = "
            SELECT vertical_slug, display_order
            FROM canvas_vertical_assignments
            WHERE canvas_id = :canvas_id
            ORDER BY display_order ASC
        ";

        return $this->db->fetchAll($sql, ['canvas_id' => $canvasId]);
    }

    /**
     * Assign canvas to multiple verticals
     *
     * @param int $canvasId Canvas template ID
     * @param array $verticalSlugs Array of vertical slugs to assign
     * @param bool $replaceExisting If true, removes old assignments first
     * @return bool Success status
     */
    public function assignVerticals(int $canvasId, array $verticalSlugs, bool $replaceExisting = true): bool
    {
        try {
            $this->db->beginTransaction();

            // Remove existing assignments if requested
            if ($replaceExisting) {
                $this->db->execute(
                    "DELETE FROM canvas_vertical_assignments WHERE canvas_id = :canvas_id",
                    ['canvas_id' => $canvasId]
                );
            }

            // Insert new assignments
            foreach ($verticalSlugs as $index => $slug) {
                $this->db->insert('canvas_vertical_assignments', [
                    'canvas_id' => $canvasId,
                    'vertical_slug' => $slug,
                    'display_order' => $index,  // Order based on array position
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $this->db->commit();
            $this->clearCache();

            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            error_log("CanvasService::assignVerticals failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove canvas from a specific vertical
     *
     * @param int $canvasId Canvas template ID
     * @param string $verticalSlug Vertical slug to remove
     * @return bool Success status
     */
    public function removeVertical(int $canvasId, string $verticalSlug): bool
    {
        try {
            $this->db->execute(
                "DELETE FROM canvas_vertical_assignments
                 WHERE canvas_id = :canvas_id AND vertical_slug = :vertical_slug",
                [
                    'canvas_id' => $canvasId,
                    'vertical_slug' => $verticalSlug,
                ]
            );

            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            error_log("CanvasService::removeVertical failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get canvas by ID with vertical assignments
     *
     * @param int $canvasId Canvas template ID
     * @return array|null Canvas data with 'verticals' array, or null if not found
     */
    public function getById(int $canvasId): ?array
    {
        $canvas = $this->db->fetchOne(
            "SELECT * FROM canvas_templates WHERE id = :id",
            ['id' => $canvasId]
        );

        if (!$canvas) {
            return null;
        }

        // Add assigned verticals
        $canvas['verticals'] = $this->getAssignedVerticals($canvasId);

        return $canvas;
    }

    /**
     * Get all canvas templates (optionally filtered)
     *
     * @param array $filters Filters: is_active, vertical_slug, status
     * @return array Array of canvas templates
     */
    public function getAll(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[] = 'ct.is_active = :is_active';
            $params['is_active'] = $filters['is_active'];
        }

        if (isset($filters['status'])) {
            $where[] = 'ct.status = :status';
            $params['status'] = $filters['status'];
        }

        if (isset($filters['vertical_slug'])) {
            $where[] = 'cva.vertical_slug = :vertical_slug';
            $params['vertical_slug'] = $filters['vertical_slug'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // If filtering by vertical, use JOIN
        if (isset($filters['vertical_slug'])) {
            $sql = "
                SELECT DISTINCT ct.*, cva.display_order
                FROM canvas_templates ct
                INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
                {$whereClause}
                ORDER BY cva.display_order ASC, ct.name ASC
            ";
        } else {
            $sql = "
                SELECT ct.*
                FROM canvas_templates ct
                {$whereClause}
                ORDER BY ct.name ASC
            ";
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Update display order for canvas in a vertical
     *
     * @param int $canvasId Canvas template ID
     * @param string $verticalSlug Vertical slug
     * @param int $newOrder New display order
     * @return bool Success status
     */
    public function updateDisplayOrder(int $canvasId, string $verticalSlug, int $newOrder): bool
    {
        try {
            $this->db->update(
                'canvas_vertical_assignments',
                ['display_order' => $newOrder, 'updated_at' => date('Y-m-d H:i:s')],
                ['canvas_id' => $canvasId, 'vertical_slug' => $verticalSlug]
            );

            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            error_log("CanvasService::updateDisplayOrder failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if canvas is assigned to any vertical
     *
     * @param int $canvasId Canvas template ID
     * @return bool True if assigned to at least one vertical
     */
    public function hasVerticalAssignments(int $canvasId): bool
    {
        $count = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM canvas_vertical_assignments WHERE canvas_id = :canvas_id",
            ['canvas_id' => $canvasId]
        );

        return ($count['count'] ?? 0) > 0;
    }

    /**
     * Get statistics about canvas assignments
     *
     * @return array Statistics data
     */
    public function getAssignmentStats(): array
    {
        $stats = [];

        // Total canvas
        $total = $this->db->fetchOne("SELECT COUNT(*) as count FROM canvas_templates");
        $stats['total_canvas'] = $total['count'] ?? 0;

        // Canvas with assignments
        $assigned = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT canvas_id) as count FROM canvas_vertical_assignments"
        );
        $stats['assigned_canvas'] = $assigned['count'] ?? 0;

        // Canvas without assignments
        $stats['unassigned_canvas'] = $stats['total_canvas'] - $stats['assigned_canvas'];

        // Assignments per vertical
        $perVertical = $this->db->fetchAll(
            "SELECT vertical_slug, COUNT(*) as count
             FROM canvas_vertical_assignments
             GROUP BY vertical_slug
             ORDER BY count DESC"
        );
        $stats['per_vertical'] = $perVertical;

        // Canvas assigned to multiple verticals
        $multiple = $this->db->fetchOne(
            "SELECT COUNT(*) as count
             FROM (
                 SELECT canvas_id, COUNT(*) as vertical_count
                 FROM canvas_vertical_assignments
                 GROUP BY canvas_id
                 HAVING COUNT(*) > 1
             ) subq"
        );
        $stats['multi_vertical_canvas'] = $multiple['count'] ?? 0;

        return $stats;
    }

    /**
     * Clear internal cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
