<?php
/**
 * Contract Model
 *
 * @package Sunyata\Core
 */

namespace Sunyata\Core;

class Contract {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Create new contract
     */
    public function create($data) {
        $contractData = [
            'user_id' => $data['user_id'],
            'type' => $data['type'],
            'vertical' => $data['vertical'],
            'status' => $data['status'] ?? 'active',
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null
        ];

        return $this->db->insert('contracts', $contractData);
    }

    /**
     * Get contract by ID
     */
    public function findById($id) {
        $sql = "SELECT * FROM contracts WHERE id = :id LIMIT 1";
        $contract = $this->db->fetchOne($sql, ['id' => $id]);

        if ($contract && $contract['metadata']) {
            $contract['metadata'] = json_decode($contract['metadata'], true);
        }

        return $contract;
    }

    /**
     * Get all contracts for user
     */
    public function getByUserId($userId) {
        $sql = "SELECT * FROM contracts WHERE user_id = :user_id ORDER BY created_at DESC";
        $contracts = $this->db->fetchAll($sql, ['user_id' => $userId]);

        foreach ($contracts as &$contract) {
            if ($contract['metadata']) {
                $contract['metadata'] = json_decode($contract['metadata'], true);
            }
        }

        return $contracts;
    }

    /**
     * Get active contracts for user
     */
    public function getActiveByUserId($userId) {
        $sql = "SELECT * FROM contracts
                WHERE user_id = :user_id
                AND status = 'active'
                AND (end_date IS NULL OR end_date >= CURDATE())
                ORDER BY created_at DESC";

        $contracts = $this->db->fetchAll($sql, ['user_id' => $userId]);

        foreach ($contracts as &$contract) {
            if ($contract['metadata']) {
                $contract['metadata'] = json_decode($contract['metadata'], true);
            }
        }

        return $contracts;
    }

    /**
     * Update contract status
     */
    public function updateStatus($id, $status) {
        $validStatuses = ['active', 'inactive', 'suspended', 'expired'];

        if (!in_array($status, $validStatuses)) {
            return false;
        }

        return $this->db->update('contracts', ['status' => $status], 'id = :id', ['id' => $id]);
    }

    /**
     * Update contract
     */
    public function update($id, $data) {
        $allowedFields = ['type', 'vertical', 'status', 'start_date', 'end_date', 'metadata'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return false;
        }

        if (isset($updateData['metadata'])) {
            $updateData['metadata'] = json_encode($updateData['metadata']);
        }

        return $this->db->update('contracts', $updateData, 'id = :id', ['id' => $id]);
    }

    /**
     * Check if contract is active
     */
    public function isActive($id) {
        $sql = "SELECT COUNT(*) as count FROM contracts
                WHERE id = :id
                AND status = 'active'
                AND (end_date IS NULL OR end_date >= CURDATE())";

        $result = $this->db->fetchOne($sql, ['id' => $id]);
        return $result['count'] > 0;
    }

    /**
     * Expire contracts past end date
     */
    public function expireOutdated() {
        $sql = "UPDATE contracts
                SET status = 'expired'
                WHERE status = 'active'
                AND end_date IS NOT NULL
                AND end_date < CURDATE()";

        $stmt = $this->db->query($sql);
        return $stmt->rowCount();
    }

    /**
     * Get all contracts (admin)
     */
    public function getAll($limit = 100, $offset = 0) {
        $sql = "SELECT c.*, u.name as user_name, u.email as user_email
                FROM contracts c
                JOIN users u ON c.user_id = u.id
                ORDER BY c.created_at DESC
                LIMIT :limit OFFSET :offset";

        $contracts = $this->db->fetchAll($sql, ['limit' => $limit, 'offset' => $offset]);

        foreach ($contracts as &$contract) {
            if ($contract['metadata']) {
                $contract['metadata'] = json_decode($contract['metadata'], true);
            }
        }

        return $contracts;
    }

    /**
     * Delete contract
     */
    public function delete($id) {
        return $this->db->delete('contracts', 'id = :id', ['id' => $id]);
    }
}
