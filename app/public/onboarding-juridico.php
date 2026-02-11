<?php
/**
 * Onboarding - Jurídico: Formulário de solicitação de acesso
 * Durante o onboarding, para quem escolhe vertical Jurídico
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

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de segurança inválido';
    } else {
        $profissao = $_POST['profissao'] ?? '';
        $oab = trim($_POST['oab'] ?? '');
        $escritorio = trim($_POST['escritorio'] ?? '');
        $motivo = trim($_POST['motivo'] ?? '');

        // Validações (campos opcionais - apenas formato se preenchido)
        $profissoes_validas = ['Advogado(a)', 'Estudante de Direito', 'Juiz(a)', 'Promotor(a)', 'Outro', ''];
        if (!in_array($profissao, $profissoes_validas)) {
            $errors[] = 'Selecione uma profissão válida';
        }

        // Validar OAB se preenchido
        if (!empty($oab) && !preg_match('/^[0-9]{1,6}-[A-Z]{2}$/', $oab)) {
            $errors[] = 'Formato de OAB inválido. Use: 123456-UF (ex: 123456-RJ)';
        }

        // Motivo agora é opcional (não valida se está vazio)

        // Verificar se já existe solicitação pendente
        if (empty($errors)) {
            $existing_request = $db->fetchOne("
                SELECT id, status, requested_at
                FROM vertical_access_requests
                WHERE user_id = :user_id
                AND vertical = 'juridico'
                AND status = 'pending'
                LIMIT 1
            ", ['user_id' => $_SESSION['user_id']]);

            if ($existing_request) {
                $tempo_espera = time() - strtotime($existing_request['requested_at']);
                $horas = floor($tempo_espera / 3600);
                $minutos = floor(($tempo_espera % 3600) / 60);

                $tempo_msg = $horas > 0
                    ? "{$horas} hora(s) e {$minutos} minuto(s)"
                    : "{$minutos} minuto(s)";

                $errors[] = "Você já possui uma solicitação pendente de acesso à vertical Jurídico enviada há {$tempo_msg}. Por favor, aguarde a análise da sua solicitação anterior ou acesse a tela de aguardando aprovação.";
            }
        }

        if (empty($errors)) {
            try {
                // Salvar dados do perfil
                $request_data = [
                    'profissao' => $profissao,
                    'oab' => $oab ?: null,
                    'escritorio' => $escritorio ?: null,
                    'motivo' => $motivo
                ];

                // SE APROVAÇÃO DESABILITADA: dar acesso imediato
                if (!$juridico_requires_approval) {
                    // Atualizar usuário
                    $db->update('users', [
                        'selected_vertical' => 'juridico',
                        'completed_onboarding' => 1
                    ], 'id = :id', ['id' => $_SESSION['user_id']]);

                    // Salvar perfil
                    $db->insert('user_profiles', [
                        'user_id' => $_SESSION['user_id'],
                        'profile_data' => json_encode($request_data, JSON_UNESCAPED_UNICODE)
                    ]);

                    // Log
                    $db->insert('audit_logs', [
                        'user_id' => $_SESSION['user_id'],
                        'action' => 'onboarding_completed',
                        'entity_type' => 'users',
                        'entity_id' => $_SESSION['user_id'],
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'details' => json_encode(['vertical' => 'juridico', 'auto_approved' => true])
                    ]);

                    // Atualizar sessão
                    $_SESSION['user']['selected_vertical'] = 'juridico';
                    $_SESSION['user']['completed_onboarding'] = true;

                    // Redirecionar para dashboard
                    redirect(BASE_URL . '/dashboard.php');
                }

                // SE APROVAÇÃO HABILITADA: criar solicitação e aguardar
                $request_id = $db->insert('vertical_access_requests', [
                    'user_id' => $_SESSION['user_id'],
                    'vertical' => 'juridico',
                    'status' => 'pending',
                    'request_data' => json_encode($request_data, JSON_UNESCAPED_UNICODE)
                ]);

                // Enviar email de notificação
                $to = 'contato@sunyataconsulting.com';
                $subject = '[Portal Sunyata] Nova Solicitação: Vertical Jurídico';

                $body = "Nova solicitação de acesso à vertical Jurídico:\n\n";
                $body .= "Nome: " . $_SESSION['name'] . "\n";
                $body .= "Email: " . $_SESSION['email'] . "\n";
                $body .= "Profissão: {$profissao}\n";
                $body .= "OAB: " . ($oab ?: 'Não informado') . "\n";
                $body .= "Escritório: " . ($escritorio ?: 'Não informado') . "\n";
                $body .= "Motivo: {$motivo}\n\n";
                $body .= "User ID: " . $_SESSION['user_id'] . "\n";
                $body .= "Data: " . date('d/m/Y H:i:s') . "\n";

                $headers = [
                    'Content-Type: text/plain; charset=UTF-8',
                    'From: Portal Sunyata <contato@sunyataconsulting.com>',
                    'Reply-To: ' . $_SESSION['email']
                ];

                @mail($to, $subject, $body, implode("\r\n", $headers));

                // Log
                $db->insert('audit_logs', [
                    'user_id' => $_SESSION['user_id'],
                    'action' => 'vertical_access_requested',
                    'entity_type' => 'vertical_access_requests',
                    'entity_id' => $request_id,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'details' => json_encode(['vertical' => 'juridico'])
                ]);

                // Redirecionar para tela de aguardo
                redirect(BASE_URL . '/aguardando-aprovacao.php');

            } catch (Exception $e) {
                error_log('Erro ao solicitar acesso jurídico: ' . $e->getMessage());
                $errors[] = 'Erro ao enviar solicitação. Tente novamente.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vertical Jurídico - Solicitar Acesso - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .onboarding-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 3rem 0;
        }
    </style>
</head>
<body>
    <div class="onboarding-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow-lg">
                        <div class="card-body p-5">
                            <?php if ($success): ?>
                                <!-- Success State -->
                                <div class="text-center">
                                    <div style="font-size: 4rem;" class="mb-3">✅</div>
                                    <h2 class="mb-3">Solicitação Enviada!</h2>
                                    <p class="text-muted mb-4">
                                        Sua solicitação de acesso à vertical Jurídico foi recebida.<br>
                                        Você receberá um email quando for aprovada.
                                    </p>

                                    <div class="alert alert-info text-start">
                                        <strong>ℹ️ E agora?</strong>
                                        <p class="mb-0 mt-2">
                                            Sua solicitação está em análise. Aguarde o contato da equipe.
                                        </p>
                                    </div>

                                    <div class="d-grid gap-2">
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

                            <?php else: ?>
                                <!-- Form State -->
                                <div class="text-center mb-4">
                                    <div style="font-size: 4rem;">⚖️</div>
                                    <h2 class="mb-2">Vertical Jurídico</h2>
                                    <p class="text-muted">Solicite acesso às ferramentas especializadas</p>
                                </div>

                                <?php if (!empty($errors)): ?>
                                    <?php
                                    // Verificar se é erro de solicitação duplicada
                                    $has_duplicate_request = false;
                                    foreach ($errors as $error) {
                                        if (strpos($error, 'já possui uma solicitação pendente') !== false) {
                                            $has_duplicate_request = true;
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="alert alert-<?= $has_duplicate_request ? 'warning' : 'danger' ?>">
                                        <?php if ($has_duplicate_request): ?>
                                            <strong>⏳ Solicitação Já Enviada</strong>
                                            <hr>
                                        <?php endif; ?>
                                        <ul class="mb-0">
                                            <?php foreach ($errors as $error): ?>
                                                <li><?= sanitize_output($error) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if ($has_duplicate_request): ?>
                                            <hr>
                                            <div class="d-grid gap-2 mt-3">
                                                <a href="<?= BASE_URL ?>/aguardando-aprovacao.php" class="btn btn-primary">
                                                    ⏳ Ir para Tela de Aguardo
                                                </a>
                                                <a href="<?= BASE_URL ?>/onboarding-step2.php" class="btn btn-outline-secondary">
                                                    ↩️ Escolher Outra Vertical
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="alert alert-info mb-4">
                                    <small>
                                        <strong>ℹ️ Informação:</strong> Todos os campos são <strong>opcionais</strong>.
                                        Forneça as informações que julgar relevantes.
                                    </small>
                                </div>

                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                                    <!-- Profissão -->
                                    <div class="mb-3">
                                        <label for="profissao" class="form-label">
                                            Profissão <small class="text-muted">(opcional)</small>
                                        </label>
                                        <select class="form-select" id="profissao" name="profissao">
                                            <option value="">Selecione...</option>
                                            <option value="Advogado(a)">Advogado(a)</option>
                                            <option value="Estudante de Direito">Estudante de Direito</option>
                                            <option value="Juiz(a)">Juiz(a)</option>
                                            <option value="Promotor(a)">Promotor(a)</option>
                                            <option value="Outro">Outro</option>
                                        </select>
                                    </div>

                                    <!-- OAB -->
                                    <div class="mb-3">
                                        <label for="oab" class="form-label">
                                            Número OAB <small class="text-muted">(opcional)</small>
                                        </label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="oab"
                                            name="oab"
                                            placeholder="123456-RJ"
                                            pattern="[0-9]{1,6}-[A-Z]{2}"
                                            title="Formato: 123456-UF"
                                            value="<?= sanitize_output($_POST['oab'] ?? '') ?>"
                                        >
                                        <small class="form-text text-muted">Formato: 123456-UF (ex: 123456-RJ)</small>
                                    </div>

                                    <!-- Escritório -->
                                    <div class="mb-3">
                                        <label for="escritorio" class="form-label">
                                            Escritório/Instituição <small class="text-muted">(opcional)</small>
                                        </label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="escritorio"
                                            name="escritorio"
                                            placeholder="Nome do escritório ou instituição"
                                            value="<?= sanitize_output($_POST['escritorio'] ?? '') ?>"
                                        >
                                    </div>

                                    <!-- Motivo -->
                                    <div class="mb-4">
                                        <label for="motivo" class="form-label">
                                            Descreva brevemente seu interesse <small class="text-muted">(opcional)</small>
                                        </label>
                                        <textarea
                                            class="form-control"
                                            id="motivo"
                                            name="motivo"
                                            rows="3"
                                            placeholder="Ex: Atuo na área de Direito Civil e gostaria de usar IA para otimizar pesquisas jurisprudenciais..."
                                        ><?= sanitize_output($_POST['motivo'] ?? '') ?></textarea>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            Enviar Solicitação
                                        </button>
                                        <a href="<?= BASE_URL ?>/onboarding-step2.php" class="btn btn-outline-secondary">
                                            Voltar
                                        </a>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
