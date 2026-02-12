<?php
/**
 * Email/Password Authentication Handler
 *
 * @package Sunyata\Auth
 */

namespace Sunyata\Auth;

use Sunyata\Core\Database;
use Sunyata\Core\User;

class PasswordAuth {
    private $db;
    private $user;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->user = new User();
    }

    /**
     * Register a new user with email/password
     *
     * @param string $email
     * @param string $password
     * @param string $name
     * @return array ['success' => bool, 'error' => string|null, 'user_id' => int|null]
     */
    public function register(string $email, string $password, string $name): array {
        $email = strtolower(trim($email));
        $name = trim($name);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Email inválido.'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'A senha deve ter pelo menos 8 caracteres.'];
        }

        if (strlen($name) < 2) {
            return ['success' => false, 'error' => 'Nome deve ter pelo menos 2 caracteres.'];
        }

        // Check if email already exists
        $existing = $this->user->findByEmail($email);
        if ($existing) {
            return ['success' => false, 'error' => 'Este email já está cadastrado.'];
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            $userId = $this->db->insert('users', [
                'email' => $email,
                'name' => $name,
                'password_hash' => $passwordHash,
                'access_level' => 'guest',
            ]);

            // Log audit
            $this->logAudit($userId, 'user_registered', 'users', $userId);

            return ['success' => true, 'user_id' => (int)$userId];
        } catch (\Exception $e) {
            error_log('PasswordAuth::register error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Erro ao criar conta. Tente novamente.'];
        }
    }

    /**
     * Login with email/password
     *
     * @param string $email
     * @param string $password
     * @return array ['success' => bool, 'error' => string|null, 'user' => array|null]
     */
    public function login(string $email, string $password): array {
        $email = strtolower(trim($email));

        if (empty($email) || empty($password)) {
            return ['success' => false, 'error' => 'Email e senha são obrigatórios.'];
        }

        $user = $this->user->findByEmail($email);

        if (!$user) {
            return ['success' => false, 'error' => 'Email ou senha incorretos.'];
        }

        if (empty($user['password_hash'])) {
            return ['success' => false, 'error' => 'Esta conta usa login via Google. Use o botão "Entrar com Google".'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Email ou senha incorretos.'];
        }

        // Update last login
        $this->user->updateLastLogin($user['id']);

        // Log audit
        $this->logAudit($user['id'], 'user_login_password', 'users', $user['id']);

        // Create session (reuse GoogleAuth session structure)
        $this->createSession($user);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Create user session (same structure as GoogleAuth)
     */
    private function createSession(array $user): void {
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['picture'] = $user['picture'] ?? null;
        $_SESSION['access_level'] = $user['access_level'];
        $_SESSION['logged_in_at'] = time();

        // Hardcoded admin override (temporary — move to DB when user management is ready)
        $effectiveAccessLevel = is_admin_email($user['email'])
            ? 'admin'
            : $user['access_level'];

        $_SESSION['access_level'] = $effectiveAccessLevel;

        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'picture' => $user['picture'] ?? null,
            'google_id' => $user['google_id'] ?? null,
            'access_level' => $effectiveAccessLevel,
            'completed_onboarding' => $user['completed_onboarding'] ?? false,
            'selected_vertical' => $user['selected_vertical'] ?? null,
        ];

        // Store session in database
        $this->storeSession($user['id']);
    }

    /**
     * Store session in database for tracking
     */
    private function storeSession(int $userId): void {
        $sessionData = [
            'id' => session_id(),
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        try {
            $this->db->insert('sessions', $sessionData);
        } catch (\Exception $e) {
            // Session already exists, update it
            $this->db->update(
                'sessions',
                [
                    'ip_address' => $sessionData['ip_address'],
                    'user_agent' => $sessionData['user_agent'],
                ],
                'id = :id',
                ['id' => $sessionData['id']]
            );
        }
    }

    /**
     * Log audit event
     */
    private function logAudit(int $userId, string $action, ?string $entityType = null, ?int $entityId = null): void {
        try {
            $this->db->insert('audit_logs', [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'details' => json_encode([
                    'timestamp' => time(),
                    'session_id' => session_id(),
                ]),
            ]);
        } catch (\Exception $e) {
            error_log('PasswordAuth::logAudit error: ' . $e->getMessage());
        }
    }
}
