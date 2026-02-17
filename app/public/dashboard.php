<?php
/**
 * User Dashboard — Tabler layout with stats and quick access.
 *
 * @package Sunyata
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

use Sunyata\Auth\GoogleAuth;
use Sunyata\Core\User;
use Sunyata\Core\VerticalManager;
use Sunyata\Compliance\ConsentManager;

$auth = new GoogleAuth();
$userModel = new User();
$consentManager = new ConsentManager();

$currentUser = $auth->getCurrentUser();
$contracts = $userModel->getActiveContracts($currentUser['id']);

// Refresh user data from database
$freshUserData = $userModel->findById($currentUser['id']);
if ($freshUserData) {
    $_SESSION['user']['selected_vertical'] = $freshUserData['selected_vertical'];
    $_SESSION['user']['completed_onboarding'] = (bool)$freshUserData['completed_onboarding'];
    $currentUser = array_merge($currentUser, $freshUserData);
}

// Handle consent form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_terms'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Token de seguranca invalido';
        redirect(BASE_URL . '/dashboard.php');
    }

    $consentManager->recordConsent($currentUser['id'], 'terms_of_use', true, $consentManager->getConsentText('terms_of_use'));
    $consentManager->recordConsent($currentUser['id'], 'privacy_policy', true, $consentManager->getConsentText('privacy_policy'));

    if (isset($_POST['data_processing'])) {
        $consentManager->recordConsent($currentUser['id'], 'data_processing', true, $consentManager->getConsentText('data_processing'));
    }
    if (isset($_POST['marketing'])) {
        $consentManager->recordConsent($currentUser['id'], 'marketing', true, $consentManager->getConsentText('marketing'));
    }

    unset($_SESSION['needs_consent']);
    $_SESSION['success'] = 'Consentimentos registrados com sucesso!';

    $redirectAfterConsent = $_SESSION['redirect_after_consent'] ?? null;
    unset($_SESSION['redirect_after_consent']);

    if ($redirectAfterConsent) {
        redirect(BASE_URL . $redirectAfterConsent);
    }
    redirect(BASE_URL . '/dashboard.php');
}

$needsConsent = isset($_SESSION['needs_consent']) || isset($_GET['consent']);
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_demo = $_SESSION['user']['is_demo'] ?? false;
$is_admin = ($currentUser['access_level'] === 'admin');
$completed_onboarding = $_SESSION['user']['completed_onboarding'] ?? false;

$verticalManager = VerticalManager::getInstance();
$verticals_info = $verticalManager->getAllDisplayData();

// If needs consent, use minimal layout
if ($needsConsent) {
    $pageContent = function () use ($consentManager) {
?>
    <div class="card card-md">
        <div class="card-body">
            <h2 class="h2 text-center mb-4">Termos e Consentimentos</h2>
            <p class="text-secondary text-center">Para continuar, precisamos do seu consentimento:</p>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="mb-3">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="accept_terms" required>
                        <span class="form-check-label fw-bold">Li e aceito os Termos de Uso e Politica de Privacidade (obrigatorio)</span>
                    </label>
                    <div class="text-secondary small mt-1"><?= nl2br(sanitize_output($consentManager->getConsentText('terms_of_use'))) ?></div>
                </div>

                <div class="mb-3">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="data_processing" checked>
                        <span class="form-check-label fw-bold">Processamento de dados para personalizacao (opcional)</span>
                    </label>
                    <div class="text-secondary small mt-1"><?= nl2br(sanitize_output($consentManager->getConsentText('data_processing'))) ?></div>
                </div>

                <div class="mb-3">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="marketing">
                        <span class="form-check-label fw-bold">Comunicacoes de marketing (opcional)</span>
                    </label>
                    <div class="text-secondary small mt-1"><?= nl2br(sanitize_output($consentManager->getConsentText('marketing'))) ?></div>
                </div>

                <div class="form-footer">
                    <button type="submit" class="btn btn-primary w-100">Aceitar e Continuar</button>
                </div>
            </form>
        </div>
    </div>
<?php
    };
    include __DIR__ . '/../src/views/layouts/minimal.php';
    exit;
}

// Normal dashboard — user layout
$pageContent = function () use ($currentUser, $is_admin, $user_vertical, $is_demo, $completed_onboarding, $verticals_info, $contracts) {
?>

<?php
$pageHeaderTitle = 'Bem-vindo, ' . ($currentUser['name'] ?? 'Usuario') . '!';
$pageHeaderPretitle = 'Dashboard';
$pageHeaderActions = $is_admin ? '<a href="' . BASE_URL . '/admin/" class="btn btn-dark btn-sm" hx-boost="false"><i class="ti ti-shield-lock me-1"></i> Painel Admin</a>' : '';
include __DIR__ . '/../src/views/components/page-header.php';
?>

<!-- Stats Row -->
<div class="row row-deck row-cards mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-primary text-white avatar"><i class="ti ti-send"></i></span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium"
                             hx-get="<?= BASE_URL ?>/api/submissions/list.php?count_only=1&period=month"
                             hx-trigger="load"
                             hx-target="this"
                             hx-swap="innerHTML">--</div>
                        <div class="text-secondary">Submissoes este mes</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-green text-white avatar"><i class="ti ti-files"></i></span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium"
                             hx-get="<?= BASE_URL ?>/api/documents/list.php?count_only=1"
                             hx-trigger="load"
                             hx-target="this"
                             hx-swap="innerHTML">--</div>
                        <div class="text-secondary">Documentos salvos</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-azure text-white avatar"><i class="ti ti-user"></i></span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium"><?= htmlspecialchars(ucfirst($currentUser['access_level'])) ?></div>
                        <div class="text-secondary">Nivel de acesso</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-purple text-white avatar"><i class="ti ti-compass"></i></span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium"><?= htmlspecialchars($verticals_info[$user_vertical]['nome'] ?? 'Nenhuma') ?></div>
                        <div class="text-secondary">Vertical ativa</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$completed_onboarding && !$is_admin): ?>
    <div class="alert alert-warning">
        <div class="d-flex">
            <div><i class="ti ti-alert-triangle alert-icon"></i></div>
            <div>
                <h4 class="alert-title">Onboarding Pendente</h4>
                <div class="text-secondary">Complete seu perfil para acessar as ferramentas.</div>
            </div>
            <div class="ms-auto">
                <a href="<?= BASE_URL ?>/onboarding-step1.php" class="btn btn-warning" hx-boost="false">Completar Agora</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Recent Submissions -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Submissoes Recentes</h3>
                <a href="<?= BASE_URL ?>/meu-trabalho/" class="btn btn-ghost-primary btn-sm">Ver todas</a>
            </div>
            <div class="card-body p-0"
                 hx-get="<?= BASE_URL ?>/api/submissions/list.php?limit=5&format=table"
                 hx-trigger="load"
                 hx-target="this"
                 hx-swap="innerHTML">
                <div class="text-center p-4 text-secondary">
                    <span class="spinner-border spinner-border-sm"></span> Carregando submissoes recentes...
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Access -->
    <div class="col-lg-4">
        <?php if ($is_admin): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Verticais</h3>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($verticals_info as $slug => $info): ?>
                            <?php if ($info['disponivel']): ?>
                            <a href="<?= BASE_URL ?>/areas/<?= $slug ?>/" class="list-group-item list-group-item-action" hx-boost="false">
                                <span class="me-2"><?= $info['icone'] ?></span>
                                <?= htmlspecialchars($info['nome']) ?>
                            </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($user_vertical): ?>
            <div class="card mb-4">
                <div class="card-body text-center">
                    <div style="font-size: 3rem;" class="mb-2"><?= $verticals_info[$user_vertical]['icone'] ?? '' ?></div>
                    <h3 class="card-title"><?= htmlspecialchars($verticals_info[$user_vertical]['nome'] ?? ucfirst($user_vertical)) ?></h3>
                    <?php if ($is_demo): ?>
                        <span class="badge bg-warning mb-2">Modo Demo</span>
                    <?php endif; ?>
                    <div class="d-grid mt-3">
                        <a href="<?= BASE_URL ?>/areas/<?= $user_vertical ?>/" class="btn btn-primary">
                            Ir para Area da Vertical
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Support Card -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Suporte</h3>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="https://chat.whatsapp.com/FaLWg0SJ6W73SRVHkOq5fD" class="btn btn-success btn-sm" target="_blank" rel="noopener" hx-boost="false">
                        <i class="ti ti-brand-whatsapp me-1"></i> WhatsApp - Grupo de Suporte
                    </a>
                    <a href="mailto:contato@sunyataconsulting.com" class="btn btn-outline-primary btn-sm" hx-boost="false">
                        <i class="ti ti-mail me-1"></i> contato@sunyataconsulting.com
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($contracts)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h3 class="card-title">Contratos Ativos</h3>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Vertical</th>
                    <th>Inicio</th>
                    <th>Termino</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contracts as $contract): ?>
                <tr>
                    <td><?= htmlspecialchars(ucfirst($contract['type'])) ?></td>
                    <td><?= htmlspecialchars($verticals_info[$contract['vertical']]['nome'] ?? $contract['vertical']) ?></td>
                    <td><?= date('d/m/Y', strtotime($contract['start_date'])) ?></td>
                    <td><?= $contract['end_date'] ? date('d/m/Y', strtotime($contract['end_date'])) : 'Indeterminado' ?></td>
                    <td><span class="badge bg-success">Ativo</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
};

include __DIR__ . '/../src/views/layouts/user.php';
