<?php
/**
 * Onboarding - Step 1: Dados Pessoais/Profissionais
 * Primeira tela após login via Google OAuth
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Se já completou onboarding, redireciona
if (isset($_SESSION['user']['completed_onboarding']) && $_SESSION['user']['completed_onboarding']) {
    $vertical = $_SESSION['user']['selected_vertical'] ?? null;
    if ($vertical) {
        redirect(BASE_URL . "/areas/{$vertical}/");
    } else {
        redirect(BASE_URL . '/onboarding-step2.php');
    }
}

// Processar POST
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de segurança inválido. Por favor, tente novamente.';
    } else {
        // Coletar dados
        $phone = trim($_POST['phone'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $organization = trim($_POST['organization'] ?? '');
        $organization_size = $_POST['organization_size'] ?? null;
        $area = trim($_POST['area'] ?? '');

        // Validações básicas
        if (empty($position)) {
            $errors[] = 'Cargo/Função é obrigatório';
        }

        if (empty($errors)) {
            try {
                $db = Database::getInstance();

                // Verificar se já existe profile
                $existing = $db->query(
                    "SELECT id FROM user_profiles WHERE user_id = :user_id",
                    ['user_id' => $_SESSION['user_id']]
                );

                if ($existing) {
                    // Atualizar
                    $db->update('user_profiles', [
                        'phone' => $phone ?: null,
                        'position' => $position,
                        'organization' => $organization ?: null,
                        'organization_size' => $organization_size,
                        'area' => $area ?: null
                    ], 'user_id = :user_id', ['user_id' => $_SESSION['user_id']]);
                } else {
                    // Inserir
                    $db->insert('user_profiles', [
                        'user_id' => $_SESSION['user_id'],
                        'phone' => $phone ?: null,
                        'position' => $position,
                        'organization' => $organization ?: null,
                        'organization_size' => $organization_size,
                        'area' => $area ?: null
                    ]);
                }

                // Log de auditoria
                $db->insert('audit_logs', [
                    'user_id' => $_SESSION['user_id'],
                    'action' => 'onboarding_step1_completed',
                    'entity_type' => 'user_profiles',
                    'entity_id' => $_SESSION['user_id'],
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'details' => json_encode(['step' => 1])
                ]);

                // Redirecionar para Step 2
                redirect(BASE_URL . '/onboarding-step2.php');

            } catch (Exception $e) {
                error_log('Erro no onboarding step 1: ' . $e->getMessage());
                $errors[] = 'Erro ao salvar dados. Por favor, tente novamente.';
            }
        }
    }
}

$pageTitle = 'Bem-vindo - Complete seu Perfil';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .onboarding-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .onboarding-card {
            max-width: 600px;
            width: 100%;
        }
        .progress-steps {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }
        .progress-step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin: 0 10px;
            position: relative;
        }
        .progress-step.active {
            background: #0d6efd;
            color: white;
        }
        .progress-step.completed {
            background: #198754;
            color: white;
        }
        .progress-step::after {
            content: '';
            position: absolute;
            width: 40px;
            height: 2px;
            background: #e9ecef;
            left: 50px;
            top: 50%;
            transform: translateY(-50%);
        }
        .progress-step:last-child::after {
            display: none;
        }
    </style>
</head>
<body>
    <div class="onboarding-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-10 col-lg-8">
                    <div class="card shadow-lg onboarding-card">
                        <div class="card-body p-5">
                            <!-- Progress Steps -->
                            <div class="progress-steps">
                                <div class="progress-step active">1</div>
                                <div class="progress-step">2</div>
                            </div>

                            <!-- Welcome Message -->
                            <div class="text-center mb-4">
                                <h2 class="mb-2">Bem-vindo, <?= sanitize_output($_SESSION['name']) ?>! 👋</h2>
                                <p class="text-muted">Complete seu perfil para começar a usar a plataforma</p>
                            </div>

                            <!-- Errors -->
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?= sanitize_output($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <!-- Form -->
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                                <!-- Telefone -->
                                <div class="mb-3">
                                    <label for="phone" class="form-label">
                                        Telefone
                                        <small class="text-muted">(opcional)</small>
                                    </label>
                                    <input
                                        type="tel"
                                        class="form-control"
                                        id="phone"
                                        name="phone"
                                        placeholder="(11) 99999-9999"
                                        value="<?= sanitize_output($_POST['phone'] ?? '') ?>"
                                    >
                                </div>

                                <!-- Cargo/Função -->
                                <div class="mb-3">
                                    <label for="position" class="form-label">
                                        Cargo/Função <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="position"
                                        name="position"
                                        placeholder="Ex: Professor, Advogado, Estudante..."
                                        value="<?= sanitize_output($_POST['position'] ?? '') ?>"
                                        required
                                    >
                                    <small class="form-text text-muted">
                                        Informe seu cargo atual ou área de atuação
                                    </small>
                                </div>

                                <!-- Organização -->
                                <div class="mb-3">
                                    <label for="organization" class="form-label">
                                        Organização/Empresa
                                        <small class="text-muted">(opcional)</small>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="organization"
                                        name="organization"
                                        placeholder="Ex: IFRJ, Escritório Silva & Associados..."
                                        value="<?= sanitize_output($_POST['organization'] ?? '') ?>"
                                    >
                                </div>

                                <!-- Tamanho da Organização -->
                                <div class="mb-3">
                                    <label for="organization_size" class="form-label">
                                        Tamanho da Organização
                                        <small class="text-muted">(opcional)</small>
                                    </label>
                                    <select class="form-select" id="organization_size" name="organization_size">
                                        <option value="">Selecione...</option>
                                        <option value="pequena" <?= ($_POST['organization_size'] ?? '') === 'pequena' ? 'selected' : '' ?>>
                                            Pequena (até 50 pessoas)
                                        </option>
                                        <option value="media" <?= ($_POST['organization_size'] ?? '') === 'media' ? 'selected' : '' ?>>
                                            Média (51-500 pessoas)
                                        </option>
                                        <option value="grande" <?= ($_POST['organization_size'] ?? '') === 'grande' ? 'selected' : '' ?>>
                                            Grande (mais de 500 pessoas)
                                        </option>
                                    </select>
                                </div>

                                <!-- Área de Atuação -->
                                <div class="mb-4">
                                    <label for="area" class="form-label">
                                        Área de Atuação
                                        <small class="text-muted">(opcional)</small>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="area"
                                        name="area"
                                        placeholder="Ex: Educação, Direito Civil, Tecnologia..."
                                        value="<?= sanitize_output($_POST['area'] ?? '') ?>"
                                    >
                                </div>

                                <!-- Info Box -->
                                <div class="alert alert-info mb-4">
                                    <small>
                                        <strong>ℹ️ Por que pedimos esses dados?</strong><br>
                                        Para personalizar sua experiência e recomendar ferramentas adequadas ao seu perfil.
                                        Seus dados são protegidos conforme a LGPD.
                                    </small>
                                </div>

                                <!-- Submit Button -->
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        Continuar
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-right ms-2" viewBox="0 0 16 16">
                                            <path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8"/>
                                        </svg>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="text-center mt-3">
                        <small class="text-white">
                            &copy; <?= date('Y') ?> <?= COMPANY_NAME ?>
                        </small>
                        <div class="mt-2">
                            <a href="<?= BASE_URL ?>/logout.php" class="text-white text-decoration-none">
                                <small>Sair</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
