<?php
/**
 * User Model
 *
 * @package Sunyata\Core
 */

namespace Sunyata\Core;

class User {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Find user by Google ID
     */
    public function findByGoogleId($googleId) {
        $sql = "SELECT * FROM users WHERE google_id = :google_id LIMIT 1";
        return $this->db->fetchOne($sql, ['google_id' => $googleId]);
    }

    /**
     * Find user by email
     */
    public function findByEmail($email) {
        $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
        return $this->db->fetchOne($sql, ['email' => $email]);
    }

    /**
     * Find user by ID
     */
    public function findById($id) {
        $sql = "SELECT * FROM users WHERE id = :id LIMIT 1";
        return $this->db->fetchOne($sql, ['id' => $id]);
    }

    /**
     * Create new user
     */
    public function create($data) {
        $userData = [
            'google_id' => $data['google_id'],
            'email' => $data['email'],
            'name' => $data['name'],
            'picture' => $data['picture'] ?? null,
            'access_level' => $data['access_level'] ?? 'guest'
        ];

        return $this->db->insert('users', $userData);
    }

    /**
     * Update user
     */
    public function update($id, $data) {
        $allowedFields = ['name', 'picture', 'access_level'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return false;
        }

        return $this->db->update('users', $updateData, 'id = :id', ['id' => $id]);
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin($id) {
        $sql = "UPDATE users SET last_login = NOW() WHERE id = :id";
        return $this->db->query($sql, ['id' => $id]);
    }

    /**
     * Get user's active contracts
     */
    public function getActiveContracts($userId) {
        $sql = "SELECT * FROM contracts
                WHERE user_id = :user_id
                AND status = 'active'
                AND (end_date IS NULL OR end_date >= CURDATE())
                ORDER BY created_at DESC";

        return $this->db->fetchAll($sql, ['user_id' => $userId]);
    }

    /**
     * Check if user has access to vertical
     */
    public function hasVerticalAccess($userId, $vertical) {
        $sql = "SELECT COUNT(*) as count FROM contracts
                WHERE user_id = :user_id
                AND vertical = :vertical
                AND status = 'active'
                AND (end_date IS NULL OR end_date >= CURDATE())";

        $result = $this->db->fetchOne($sql, [
            'user_id' => $userId,
            'vertical' => $vertical
        ]);

        return $result['count'] > 0;
    }

    /**
     * Get all users (admin only)
     */
    public function getAll($limit = 100, $offset = 0) {
        $sql = "SELECT id, email, name, picture, access_level, created_at, last_login
                FROM users
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";

        return $this->db->fetchAll($sql, ['limit' => $limit, 'offset' => $offset]);
    }

    /**
     * Count total users
     */
    public function count() {
        $sql = "SELECT COUNT(*) as count FROM users";
        $result = $this->db->fetchOne($sql);
        return $result['count'];
    }

    /**
     * Anonymize user data (LGPD compliance)
     */
    public function anonymize($id) {
        $anonymizedData = [
            'email' => 'deleted_' . $id . '@anonymized.local',
            'name' => 'Usuário Removido',
            'picture' => null,
            'access_level' => 'guest'
        ];

        return $this->db->update('users', $anonymizedData, 'id = :id', ['id' => $id]);
    }
}
