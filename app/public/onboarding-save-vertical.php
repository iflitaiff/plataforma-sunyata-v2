<?php
/**
 * Salva a vertical escolhida e completa o onboarding
 * Para verticais que NÃO requerem aprovação ou info extra
 *
 * REFATORADO: 2025-10-20
 * Agora usa VerticalManager para validação dinâmica
 *
 * ATUALIZADO: 2026-02-05
 * Adicionada verificação de consentimento LGPD após onboarding
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Core\VerticalManager;
use Sunyata\Compliance\ConsentManager;

require_login();

// Validar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/onboarding-step2.php');
}

// Validar CSRF
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Token de segurança inválido';
    redirect(BASE_URL . '/onboarding-step2.php');
}

$vertical = $_POST['vertical'] ?? '';

// Inicializar VerticalManager
$verticalManager = VerticalManager::getInstance();

// Validar vertical existe
if (!$verticalManager->exists($vertical)) {
    $_SESSION['error'] = 'Vertical inválida';
    redirect(BASE_URL . '/onboarding-step2.php');
}

// Validar vertical está disponível
if (!$verticalManager->isAvailable($vertical)) {
    $_SESSION['error'] = 'Esta vertical não está disponível no momento';
    redirect(BASE_URL . '/onboarding-step2.php');
}

// Validar que vertical pode ser acessada diretamente
// (não requer aprovação nem info extra)
if (!$verticalManager->canAccessDirectly($vertical)) {
    $verticalData = $verticalManager->get($vertical);

    if ($verticalManager->requiresExtraInfo($vertical)) {
        // Redirecionar para formulário de info extra
        $extraForm = $verticalManager->getExtraForm($vertical);
        if ($extraForm) {
            redirect(BASE_URL . '/' . $extraForm);
        }
    }

    if ($verticalManager->requiresApproval($vertical)) {
        // Redirecionar para formulário de aprovação
        $approvalForm = $verticalManager->getApprovalForm($vertical);
        if ($approvalForm) {
            redirect(BASE_URL . '/' . $approvalForm);
        }
    }

    $_SESSION['error'] = 'Esta vertical requer processo especial de acesso';
    redirect(BASE_URL . '/onboarding-step2.php');
}

try {
    $db = Database::getInstance();

    // Atualizar usuário
    $db->update('users', [
        'selected_vertical' => $vertical,
        'completed_onboarding' => true
    ], 'id = :id', ['id' => $_SESSION['user_id']]);

    // Atualizar sessão (CRÍTICO: deve atualizar antes de redirecionar)
    if (!isset($_SESSION['user'])) {
        $_SESSION['user'] = [];
    }
    $_SESSION['user']['selected_vertical'] = $vertical;
    $_SESSION['user']['completed_onboarding'] = true;

    // Log de auditoria
    $db->insert('audit_logs', [
        'user_id' => $_SESSION['user_id'],
        'action' => 'onboarding_completed',
        'entity_type' => 'users',
        'entity_id' => $_SESSION['user_id'],
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'details' => json_encode(['vertical' => $vertical])
    ]);

    // Mensagem de sucesso
    $verticalNome = $verticalManager->get($vertical)['nome'];
    $_SESSION['success'] = "Perfil configurado com sucesso! Bem-vindo à {$verticalNome}!";

    // Verificar se precisa de consentimento LGPD antes de acessar a vertical
    $consentManager = new ConsentManager();
    if ($consentManager->needsConsent($_SESSION['user_id'], 'terms_of_use')) {
        $_SESSION['needs_consent'] = true;
        $_SESSION['redirect_after_consent'] = "/areas/{$vertical}/";
        redirect(BASE_URL . '/dashboard.php?consent=required');
    }

    // Redirecionar para a vertical escolhida
    redirect(BASE_URL . "/areas/{$vertical}/");

} catch (Exception $e) {
    error_log('Erro ao salvar vertical: ' . $e->getMessage());
    $_SESSION['error'] = 'Erro ao salvar configuração. Por favor, tente novamente.';
    redirect(BASE_URL . '/onboarding-step2.php');
}
