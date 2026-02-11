<?php
/**
 * Aguardando Aprovação - Jurídico
 *
 * Tela de espera com auto-refresh que verifica se o usuário foi aprovado
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Core\Settings;

require_login();

$db = Database::getInstance();
$settings = Settings::getInstance();

// Verificar se aprovação está desabilitada
$juridico_requires_approval = $settings->get('juridico_requires_approval', true);

// Se aprovação foi desabilitada, liberar acesso
if (!$juridico_requires_approval) {
    // Atualizar usuário
    $db->update('users', [
        'selected_vertical' => 'juridico',
        'completed_onboarding' => 1
    ], 'id = :id', ['id' => $_SESSION['user_id']]);

    // Atualizar sessão
    $_SESSION['user']['selected_vertical'] = 'juridico';
    $_SESSION['user']['completed_onboarding'] = true;

    redirect(BASE_URL . '/dashboard.php');
}

// Verificar se já foi aprovado
$user = $db->fetchOne("SELECT selected_vertical, completed_onboarding FROM users WHERE id = :id", [
    'id' => $_SESSION['user_id']
]);

if ($user['selected_vertical'] === 'juridico' && $user['completed_onboarding']) {
    // Atualizar sessão
    $_SESSION['user']['selected_vertical'] = 'juridico';
    $_SESSION['user']['completed_onboarding'] = true;

    redirect(BASE_URL . '/dashboard.php');
}

// Verificar se tem solicitação pendente
$request = $db->fetchOne("
    SELECT id, status, requested_at
    FROM vertical_access_requests
    WHERE user_id = :user_id AND vertical = 'juridico'
    ORDER BY requested_at DESC
    LIMIT 1
", ['user_id' => $_SESSION['user_id']]);

if (!$request || $request['status'] === 'rejected') {
    // Sem solicitação ou rejeitada - redirecionar para fazer nova
    redirect(BASE_URL . '/onboarding-juridico.php');
}

// Calcular tempo desde solicitação
$tempo_aguardando = time() - strtotime($request['requested_at']);
$horas = floor($tempo_aguardando / 3600);
$minutos = floor(($tempo_aguardando % 3600) / 60);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aguardando Aprovação - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Auto-refresh a cada 30 segundos -->
    <meta http-equiv="refresh" content="30">

    <style>
        .waiting-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .pulse {
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .spinner {
            animation: spin 2s linear infinite;
        }
    </style>
</head>
<body>
    <div class="waiting-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow-lg">
                        <div class="card-body p-5 text-center">
                            <!-- Ícone animado -->
                            <div class="mb-4">
                                <i class="bi bi-hourglass-split spinner" style="font-size: 5rem; color: #667eea;"></i>
                            </div>

                            <h2 class="mb-3">Aguardando Aprovação</h2>

                            <div class="alert alert-info text-start">
                                <h5 class="alert-heading">⚖️ Vertical Jurídico</h5>
                                <p class="mb-2">
                                    Sua solicitação de acesso foi enviada com sucesso!
                                </p>
                                <hr>
                                <p class="mb-0">
                                    <strong>Tempo de espera:</strong>
                                    <?php if ($horas > 0): ?>
                                        <?= $horas ?> hora(s) e <?= $minutos ?> minuto(s)
                                    <?php else: ?>
                                        <?= $minutos ?> minuto(s)
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="card bg-light mb-4">
                                <div class="card-body">
                                    <h6 class="card-title">ℹ️ O que acontece agora?</h6>
                                    <ul class="text-start mb-0">
                                        <li class="mb-2">Nossa equipe está analisando sua solicitação</li>
                                        <li class="mb-2">Você receberá um email quando for aprovada</li>
                                        <li class="mb-2"><strong>Esta página atualiza automaticamente</strong> - quando aprovado, você será redirecionado</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Status de refresh -->
                            <div class="small text-muted mb-4 pulse">
                                <i class="bi bi-arrow-clockwise"></i>
                                Verificando status... (atualiza a cada 30 segundos)
                            </div>

                            <!-- Ações -->
                            <div class="d-grid gap-2">
                                <button onclick="location.reload()" class="btn btn-primary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Verificar Agora
                                </button>

                                <a href="https://chat.whatsapp.com/HEyyAyoS4bb6ycTMs0kLWq"
                                   target="_blank"
                                   class="btn btn-success">
                                    <i class="bi bi-whatsapp"></i> Contate o Administrador
                                </a>

                                <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-danger">
                                    <i class="bi bi-box-arrow-right"></i> Sair
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
