<?php
/**
 * LGPD Data Retention Manager
 *
 * @package Sunyata\Compliance
 */

namespace Sunyata\Compliance;

use Sunyata\Core\Database;
use Sunyata\Core\User;

class DataRetention {
    private $db;
    private $user;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->user = new User();
    }

    /**
     * Create data request (LGPD Article 18 rights)
     */
    public function createRequest($userId, $requestType) {
        $validTypes = ['access', 'deletion', 'portability', 'correction'];

        if (!in_array($requestType, $validTypes)) {
            return false;
        }

        $requestData = [
            'user_id' => $userId,
            'request_type' => $requestType,
            'status' => 'pending'
        ];

        $requestId = $this->db->insert('data_requests', $requestData);

        // Log audit
        $this->logAudit($userId, 'data_request_created', 'data_requests', $requestId, [
            'request_type' => $requestType
        ]);

        return $requestId;
    }

    /**
     * Get user's data requests
     */
    public function getUserRequests($userId) {
        $sql = "SELECT * FROM data_requests
                WHERE user_id = :user_id
                ORDER BY requested_at DESC";

        return $this->db->fetchAll($sql, ['user_id' => $userId]);
    }

    /**
     * Process data access request
     */
    public function processAccessRequest($requestId, $processedBy) {
        $request = $this->getRequest($requestId);

        if (!$request || $request['request_type'] !== 'access') {
            return false;
        }

        // Gather all user data
        $userData = $this->exportUserData($request['user_id']);

        // Update request status
        $this->updateRequestStatus($requestId, 'completed', $processedBy, 'Data exported successfully');

        return $userData;
    }

    /**
     * Process data deletion request
     */
    public function processDeletionRequest($requestId, $processedBy) {
        $request = $this->getRequest($requestId);

        if (!$request || $request['request_type'] !== 'deletion') {
            return false;
        }

        // Anonymize user data (keep for legal/audit purposes)
        $this->user->anonymize($request['user_id']);

        // Update request status
        $this->updateRequestStatus($requestId, 'completed', $processedBy, 'User data anonymized');

        // Log audit
        $this->logAudit($request['user_id'], 'user_data_deleted', 'users', $request['user_id']);

        return true;
    }

    /**
     * Export all user data (LGPD Article 18 - portability)
     */
    public function exportUserData($userId) {
        $userData = $this->user->findById($userId);

        if (!$userData) {
            return false;
        }

        // Get all related data
        $contracts = $this->db->fetchAll(
            "SELECT * FROM contracts WHERE user_id = :user_id",
            ['user_id' => $userId]
        );

        $consents = $this->db->fetchAll(
            "SELECT * FROM consents WHERE user_id = :user_id",
            ['user_id' => $userId]
        );

        $sessions = $this->db->fetchAll(
            "SELECT * FROM sessions WHERE user_id = :user_id ORDER BY last_activity DESC LIMIT 10",
            ['user_id' => $userId]
        );

        $auditLogs = $this->db->fetchAll(
            "SELECT * FROM audit_logs WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 100",
            ['user_id' => $userId]
        );

        return [
            'user' => $userData,
            'contracts' => $contracts,
            'consents' => $consents,
            'recent_sessions' => $sessions,
            'recent_audit_logs' => $auditLogs,
            'exported_at' => date('Y-m-d H:i:s'),
            'export_version' => '1.0.0'
        ];
    }

    /**
     * Get request by ID
     */
    public function getRequest($requestId) {
        $sql = "SELECT * FROM data_requests WHERE id = :id LIMIT 1";
        return $this->db->fetchOne($sql, ['id' => $requestId]);
    }

    /**
     * Update request status
     */
    public function updateRequestStatus($requestId, $status, $processedBy, $notes = null) {
        $updateData = [
            'status' => $status,
            'processed_by' => $processedBy,
            'notes' => $notes
        ];

        if ($status === 'completed' || $status === 'rejected') {
            $sql = "UPDATE data_requests
                    SET status = :status,
                        processed_by = :processed_by,
                        processed_at = NOW(),
                        notes = :notes
                    WHERE id = :id";

            return $this->db->query($sql, array_merge($updateData, ['id' => $requestId]));
        } else {
            return $this->db->update('data_requests', $updateData, 'id = :id', ['id' => $requestId]);
        }
    }

    /**
     * Get pending requests (admin)
     */
    public function getPendingRequests($limit = 50) {
        $sql = "SELECT dr.*, u.name as user_name, u.email as user_email
                FROM data_requests dr
                JOIN users u ON dr.user_id = u.id
                WHERE dr.status = 'pending'
                ORDER BY dr.requested_at ASC
                LIMIT :limit";

        return $this->db->fetchAll($sql, ['limit' => $limit]);
    }

    /**
     * Clean old anonymized data (retention policy)
     */
    public function cleanOldData() {
        $cutoffDate = date('Y-m-d', strtotime('-' . DATA_RETENTION_DAYS . ' days'));

        // Get users to anonymize
        $sql = "SELECT id FROM users
                WHERE last_login < :cutoff_date
                AND access_level = 'guest'
                AND email NOT LIKE 'deleted_%'";

        $users = $this->db->fetchAll($sql, ['cutoff_date' => $cutoffDate]);

        $count = 0;
        foreach ($users as $user) {
            $this->user->anonymize($user['id']);
            $this->logAudit(null, 'auto_anonymization', 'users', $user['id'], [
                'reason' => 'data_retention_policy',
                'cutoff_date' => $cutoffDate
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Log audit event
     */
    private function logAudit($userId, $action, $entityType, $entityId, $details = []) {
        $auditData = [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => json_encode($details)
        ];

        $this->db->insert('audit_logs', $auditData);
    }
}
