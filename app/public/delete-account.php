<?php
/**
 * Delete Account - Página de confirmação e execução
 *
 * Permite que o usuário delete sua própria conta e todos os dados associados
 * conforme LGPD Art. 18
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Services\UserDeletionService;

require_login();

$db = Database::getInstance();
$deletionService = new UserDeletionService();

$error = null;
$confirmed = false;

// Processar confirmação
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token de segurança inválido';
    } elseif ($_POST['action'] === 'confirm') {
        // Primeira confirmação
        $confirmed = true;
    } elseif ($_POST['action'] === 'delete') {
        // Confirmação final - deletar conta
        $confirmText = $_POST['confirm_text'] ?? '';

        if (strtoupper(trim($confirmText)) !== 'DELETAR') {
            $error = 'Digite exatamente "DELETAR" para confirmar';
            $confirmed = true;
        } else {
            try {
                $userId = $_SESSION['user_id'];
                $userEmail = $_SESSION['email'];

                // Executar deleção
                $result = $deletionService->deleteUser($userId);

                if ($result['success']) {
                    // Log da ação ANTES de destruir sessão
                    error_log("User self-deletion: {$userEmail} (ID: {$userId})");

                    // Destruir sessão
                    session_destroy();

                    // Redirecionar para página de confirmação
                    header('Location: ' . BASE_URL . '/account-deleted.php');
                    exit;
                } else {
                    $error = 'Erro ao deletar conta: ' . $result['message'];
                    $confirmed = true;
                }
            } catch (Exception $e) {
                error_log('Error deleting user account: ' . $e->getMessage());
                $error = 'Erro ao processar solicitação. Tente novamente.';
                $confirmed = true;
            }
        }
    }
}

$pageTitle = 'Deletar Conta';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <?php if (!$confirmed): ?>
                            <!-- Aviso Inicial -->
                            <div class="text-center mb-4">
                                <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 4rem;"></i>
                                <h2 class="mt-3">Deletar Conta?</h2>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <?= sanitize_output($error) ?>
                                </div>
                            <?php endif; ?>

                            <div class="alert alert-danger">
                                <h5 class="alert-heading">⚠️ ATENÇÃO: Ação Irreversível!</h5>
                                <p class="mb-0">
                                    Esta ação não pode ser desfeita. Todos os seus dados serão permanentemente removidos:
                                </p>
                            </div>

                            <ul class="mb-4">
                                <li>Seus dados pessoais (nome, email)</li>
                                <li>Histórico de uso da plataforma</li>
                                <li>Prompts e interações com IA</li>
                                <li>Solicitações de acesso</li>
                                <li>Logs de auditoria</li>
                                <li>Consentimentos LGPD</li>
                            </ul>

                            <div class="alert alert-info">
                                <strong>📋 Direito garantido pela LGPD</strong>
                                <p class="mb-0 small">
                                    Conforme Lei Geral de Proteção de Dados (LGPD), você tem o direito de
                                    solicitar a eliminação dos seus dados pessoais (Art. 18, VI).
                                </p>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="confirm">

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-danger btn-lg">
                                        Prosseguir com Deleção
                                    </button>
                                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary">
                                        Cancelar e Voltar
                                    </a>
                                </div>
                            </form>

                        <?php else: ?>
                            <!-- Confirmação Final -->
                            <div class="text-center mb-4">
                                <i class="bi bi-shield-x text-danger" style="font-size: 4rem;"></i>
                                <h2 class="mt-3">Confirmação Final</h2>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <?= sanitize_output($error) ?>
                                </div>
                            <?php endif; ?>

                            <div class="alert alert-danger">
                                <h5 class="alert-heading">🚨 Última Chance!</h5>
                                <p class="mb-0">
                                    Digite <strong>DELETAR</strong> (em maiúsculas) para confirmar a
                                    exclusão permanente da sua conta.
                                </p>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="delete">

                                <div class="mb-4">
                                    <label for="confirm_text" class="form-label">
                                        Digite "DELETAR" para confirmar:
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control form-control-lg text-center"
                                        id="confirm_text"
                                        name="confirm_text"
                                        autocomplete="off"
                                        required
                                        autofocus
                                    >
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-danger btn-lg">
                                        <i class="bi bi-trash-fill"></i>
                                        Deletar Minha Conta Permanentemente
                                    </button>
                                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-success">
                                        <i class="bi bi-arrow-left"></i>
                                        Não, Voltar ao Dashboard
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <p class="text-muted small">
                        <i class="bi bi-shield-check"></i>
                        Seus dados estão protegidos pela LGPD
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
