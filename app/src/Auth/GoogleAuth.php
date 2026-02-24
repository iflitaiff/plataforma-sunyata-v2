<?php
/**
 * Google OAuth Authentication Handler
 *
 * @package Sunyata\Auth
 */

namespace Sunyata\Auth;

use Sunyata\Core\Database;
use Sunyata\Core\User;
use Sunyata\Compliance\ConsentManager;

class GoogleAuth {
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $user;
    private $consentManager;

    public function __construct() {
        $this->clientId = GOOGLE_CLIENT_ID;
        $this->clientSecret = GOOGLE_CLIENT_SECRET;
        $this->redirectUri = CALLBACK_URL;
        $this->user = new User();
        $this->consentManager = new ConsentManager();
    }

    /**
     * Get Google OAuth login URL
     */
    public function getAuthUrl() {
        // Generate CSRF state token
        $state = bin2hex(random_bytes(32));
        $_SESSION['oauth_state'] = $state;
        $_SESSION['oauth_state_time'] = time();

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account',
            'state' => $state
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function getAccessToken($code) {
        $params = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code'
        ];

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log('Google OAuth token error: ' . $response);
            return false;
        }

        $data = json_decode($response, true);
        return $data['access_token'] ?? false;
    }

    /**
     * Get user info from Google
     */
    public function getUserInfo($accessToken) {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log('Google user info error: ' . $response);
            return false;
        }

        return json_decode($response, true);
    }

    /**
     * Handle OAuth callback and login user
     */
    public function handleCallback($code) {
        // Exchange code for access token
        $accessToken = $this->getAccessToken($code);
        if (!$accessToken) {
            return ['success' => false, 'error' => 'Failed to get access token'];
        }

        // Get user info from Google
        $googleUser = $this->getUserInfo($accessToken);
        if (!$googleUser) {
            return ['success' => false, 'error' => 'Failed to get user info'];
        }

        // Check if user exists (by Google ID first, then by email)
        $user = $this->user->findByGoogleId($googleUser['id']);

        if (!$user) {
            // Check if user exists by email (e.g. created via password auth)
            $user = $this->user->findByEmail($googleUser['email']);
        }

        if (!$user) {
            // Create new user
            $userId = $this->user->create([
                'google_id' => $googleUser['id'],
                'email' => $googleUser['email'],
                'name' => $googleUser['name'],
                'picture' => $googleUser['picture'] ?? null,
                'access_level' => 'guest'
            ]);

            $user = $this->user->findById($userId);

            // Log audit
            $this->logAudit($userId, 'user_created', 'users', $userId);
        } else {
            // Update user info (link google_id if missing)
            $updateData = [
                'name' => $googleUser['name'],
                'picture' => $googleUser['picture'] ?? null
            ];
            if (empty($user['google_id'])) {
                $updateData['google_id'] = $googleUser['id'];
            }
            $this->user->update($user['id'], $updateData);

            // Log audit
            $this->logAudit($user['id'], 'user_login', 'users', $user['id']);
        }

        // Update last login
        $this->user->updateLastLogin($user['id']);

        // TEMPORARY: Demo mode - allow all users
        // Skip contract verification for demo purposes
        // TODO: Re-enable contract verification before production launch
        /*
        // Check if user has active contract
        if ($user['access_level'] === 'guest') {
            $hasActiveContract = $this->checkActiveContract($user['id']);
            if (!$hasActiveContract) {
                return [
                    'success' => false,
                    'error' => 'No active contract found',
                    'redirect' => '/error-no-contract.php'
                ];
            }
        }
        */

        // Create session
        $this->createSession($user);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Create user session
     */
    private function createSession($user) {
        // Preserve redirect URL before regeneration (Redis sessions can lose data)
        $redirectAfterLogin = $_SESSION['redirect_after_login'] ?? null;

        // Regenerate session ID for security
        session_regenerate_id(true);

        // Restore redirect URL if it was lost during regeneration
        if ($redirectAfterLogin) {
            $_SESSION['redirect_after_login'] = $redirectAfterLogin;
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['picture'] = $user['picture'];
        $_SESSION['access_level'] = $user['access_level'];
        $_SESSION['logged_in_at'] = time();

        // Set user array for compatibility with require_login() and other checks
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'picture' => $user['picture'],
            'google_id' => $user['google_id'],
            'access_level' => $user['access_level'],
            'completed_onboarding' => $user['completed_onboarding'] ?? false,
            'selected_vertical' => $user['selected_vertical'] ?? null
        ];

        // Store session in database
        $this->storeSession($user['id']);
    }

    /**
     * Store session in database for tracking
     */
    private function storeSession($userId) {
        $db = Database::getInstance();

        $sessionData = [
            'id' => session_id(),
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];

        try {
            $db->insert('sessions', $sessionData);
        } catch (\Exception $e) {
            // Session already exists, update it
            $db->update(
                'sessions',
                [
                    'ip_address' => $sessionData['ip_address'],
                    'user_agent' => $sessionData['user_agent']
                ],
                'id = :id',
                ['id' => $sessionData['id']]
            );
        }
    }

    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->logAudit($_SESSION['user_id'], 'user_logout', 'users', $_SESSION['user_id']);

            // Remove session from database
            $db = Database::getInstance();
            $db->delete('sessions', 'id = :id', ['id' => session_id()]);
        }

        // Destroy session
        $_SESSION = [];
        session_destroy();

        // Start new session for CSRF protection
        session_start();
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['user']) && isset($_SESSION['user_id']);
    }

    /**
     * Get current user
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return $this->user->findById($_SESSION['user_id']);
    }

    /**
     * Log audit event
     */
    private function logAudit($userId, $action, $entityType = null, $entityId = null) {
        $db = Database::getInstance();

        $auditData = [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => json_encode([
                'timestamp' => time(),
                'session_id' => session_id()
            ])
        ];

        $db->insert('audit_logs', $auditData);
    }
}
