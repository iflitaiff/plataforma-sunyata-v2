<?php
/**
 * User Deletion Service - Serviço de Deleção Segura de Usuários
 *
 * Gerencia a deleção completa e segura de usuários,
 * incluindo todos os dados relacionados (LGPD compliant).
 *
 * @package Sunyata\Admin
 * @author Claude Code
 * @version 1.0.0
 */

namespace Sunyata\Admin;

use Sunyata\Core\Database;
use PDOException;

class UserDeletionService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Deleta um usuário e todos os dados relacionados
     *
     * @param int $userId ID do usuário a deletar
     * @param int $adminId ID do admin executando a ação
     * @return array ['success' => bool, 'message' => string, 'deleted_records' => array]
     */
    public function deleteUser(int $userId, int $adminId): array {
        try {
            // 1. Validações de Segurança
            $validationResult = $this->validateDeletion($userId, $adminId);
            if (!$validationResult['success']) {
                return $validationResult;
            }

            $user = $validationResult['user'];

            // 2. Iniciar Transação (all-or-nothing)
            $this->db->beginTransaction();

            $deletedRecords = [];

            // 3. Deletar dados relacionados (ordem importa por foreign keys)

            // User profiles
            $count = $this->db->delete('user_profiles', 'user_id = :user_id', ['user_id' => $userId]);
            $deletedRecords['user_profiles'] = $count;

            // Vertical access requests
            $count = $this->db->delete('vertical_access_requests', 'user_id = :user_id', ['user_id' => $userId]);
            $deletedRecords['vertical_access_requests'] = $count;

            // Consents
            $count = $this->db->delete('consents', 'user_id = :user_id', ['user_id' => $userId]);
            $deletedRecords['consents'] = $count;

            // Data requests
            $count = $this->db->delete('data_requests', 'user_id = :user_id', ['user_id' => $userId]);
            $deletedRecords['data_requests'] = $count;

            // Tool access logs
            $count = $this->db->delete('tool_access_logs', 'user_id = :user_id', ['user_id' => $userId]);
            $deletedRecords['tool_access_logs'] = $count;

            // Contracts (se existir)
            $count = $this->db->delete('contracts', 'user_id = :user_id', ['user_id' => $userId]);
            $deletedRecords['contracts'] = $count;

            // Audit logs - ANONIMIZAR, não deletar (compliance)
            $stmt = $this->db->getConnection()->prepare(
                "UPDATE audit_logs SET user_id = NULL WHERE user_id = :user_id"
            );
            $stmt->execute(['user_id' => $userId]);
            $deletedRecords['audit_logs_anonymized'] = $stmt->rowCount();

            // 4. Deletar o usuário
            $count = $this->db->delete('users', 'id = :id', ['id' => $userId]);
            $deletedRecords['user'] = $count;

            // 5. Log de auditoria da deleção
            $this->db->insert('audit_logs', [
                'user_id' => $adminId,
                'action' => 'user_deleted',
                'entity_type' => 'users',
                'entity_id' => $userId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'details' => json_encode([
                    'deleted_user_email' => $user['email'],
                    'deleted_user_name' => $user['name'],
                    'deleted_records' => $deletedRecords
                ])
            ]);

            // 6. Commit da transação
            $this->db->commit();

            return [
                'success' => true,
                'message' => "Usuário {$user['name']} ({$user['email']}) deletado com sucesso.",
                'deleted_records' => $deletedRecords
            ];

        } catch (PDOException $e) {
            // Rollback em caso de erro
            $this->db->rollback();

            error_log("UserDeletionService::deleteUser() failed for user_id={$userId}: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Erro ao deletar usuário. Por favor, tente novamente.',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Valida se a deleção pode ser executada
     *
     * @param int $userId ID do usuário a deletar
     * @param int $adminId ID do admin executando
     * @return array ['success' => bool, 'message' => string, 'user' => array|null]
     */
    private function validateDeletion(int $userId, int $adminId): array {
        // 1. Verificar se usuário existe
        $user = $this->db->fetchOne(
            "SELECT id, name, email, access_level FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Usuário não encontrado.',
                'user' => null
            ];
        }

        // 2. Não permitir deletar administradores (proteção)
        if ($user['access_level'] === 'admin') {
            return [
                'success' => false,
                'message' => 'Não é permitido deletar usuários administradores por segurança.',
                'user' => null
            ];
        }

        // 3. Não permitir deletar a si mesmo (proteção)
        if ($userId === $adminId) {
            return [
                'success' => false,
                'message' => 'Você não pode deletar sua própria conta.',
                'user' => null
            ];
        }

        // Todas validações passaram
        return [
            'success' => true,
            'message' => 'Validação OK',
            'user' => $user
        ];
    }

    /**
     * Obtém informações sobre o que será deletado (preview)
     *
     * @param int $userId ID do usuário
     * @return array Informações sobre registros relacionados
     */
    public function getDeletionPreview(int $userId): array {
        $user = $this->db->fetchOne(
            "SELECT id, name, email, access_level, selected_vertical, created_at FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if (!$user) {
            return ['error' => 'Usuário não encontrado'];
        }

        // Conta registros relacionados
        $relatedData = [];

        $relatedData['user_profiles'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM user_profiles WHERE user_id = :id",
            ['id' => $userId]
        )['count'];

        $relatedData['vertical_access_requests'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM vertical_access_requests WHERE user_id = :id",
            ['id' => $userId]
        )['count'];

        $relatedData['consents'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM consents WHERE user_id = :id",
            ['id' => $userId]
        )['count'];

        $relatedData['tool_access_logs'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM tool_access_logs WHERE user_id = :id",
            ['id' => $userId]
        )['count'];

        $relatedData['audit_logs'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM audit_logs WHERE user_id = :id",
            ['id' => $userId]
        )['count'];

        return [
            'user' => $user,
            'related_data' => $relatedData,
            'can_delete' => $user['access_level'] !== 'admin'
        ];
    }
}
