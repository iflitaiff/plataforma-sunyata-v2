<?php
/**
 * Profile - Página de Perfil do Usuário
 *
 * Exibe dados do usuário, consentimentos LGPD e opções de conta
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Core\VerticalManager;
use Sunyata\Compliance\ConsentManager;

require_login();

$db = Database::getInstance();
$verticalManager = VerticalManager::getInstance();
$consentManager = new ConsentManager();

// Buscar dados completos do usuário
$user = $db->fetchOne("
    SELECT u.*, up.phone, up.position, up.organization, up.organization_size, up.area
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE u.id = :id
", ['id' => $_SESSION['user_id']]);

// Buscar histórico de consentimentos
$consents = $consentManager->getConsentHistory($_SESSION['user_id']);

// Obter informações da vertical
$verticalInfo = null;
if ($user['selected_vertical']) {
    $verticalInfo = $verticalManager->get($user['selected_vertical']);
}

$pageTitle = 'Meu Perfil';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 3rem 0;
        }
        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2.5rem;
            color: #667eea;
            font-weight: bold;
        }
        .profile-body {
            padding: 2rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #6c757d;
            font-weight: 500;
        }
        .info-value {
            color: #1a365d;
            font-weight: 600;
        }
        .consent-item {
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }
        .consent-item.active {
            border-left: 4px solid #28a745;
        }
        .consent-item.revoked {
            border-left: 4px solid #dc3545;
            opacity: 0.7;
        }
        .danger-zone {
            background: #fff5f5;
            border: 2px solid #fed7d7;
            border-radius: 15px;
            padding: 1.5rem;
        }
        .nav-buttons {
            text-align: center;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="container" style="max-width: 800px;">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= sanitize_output($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= sanitize_output($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Perfil Principal -->
        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                </div>
                <h2 class="mb-1"><?= sanitize_output($user['name']) ?></h2>
                <p class="mb-0 opacity-75"><?= sanitize_output($user['email']) ?></p>
            </div>
            <div class="profile-body">
                <h5 class="mb-3"><i class="bi bi-person-badge"></i> Informações Pessoais</h5>

                <div class="info-row">
                    <span class="info-label">Nome</span>
                    <span class="info-value"><?= sanitize_output($user['name']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email</span>
                    <span class="info-value"><?= sanitize_output($user['email']) ?></span>
                </div>
                <?php if ($user['phone']): ?>
                <div class="info-row">
                    <span class="info-label">Telefone</span>
                    <span class="info-value"><?= sanitize_output($user['phone']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($user['position']): ?>
                <div class="info-row">
                    <span class="info-label">Cargo/Função</span>
                    <span class="info-value"><?= sanitize_output($user['position']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($user['organization']): ?>
                <div class="info-row">
                    <span class="info-label">Organização</span>
                    <span class="info-value"><?= sanitize_output($user['organization']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($user['area']): ?>
                <div class="info-row">
                    <span class="info-label">Área de Atuação</span>
                    <span class="info-value"><?= sanitize_output($user['area']) ?></span>
                </div>
                <?php endif; ?>

                <hr class="my-4">

                <h5 class="mb-3"><i class="bi bi-grid"></i> Acesso na Plataforma</h5>

                <div class="info-row">
                    <span class="info-label">Vertical</span>
                    <span class="info-value">
                        <?php if ($verticalInfo): ?>
                            <?= $verticalInfo['icone'] ?? '' ?> <?= sanitize_output($verticalInfo['nome']) ?>
                        <?php else: ?>
                            <span class="text-muted">Nenhuma selecionada</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Nível de Acesso</span>
                    <span class="info-value">
                        <span class="badge bg-primary"><?= sanitize_output(ucfirst($user['access_level'])) ?></span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Membro desde</span>
                    <span class="info-value"><?= date('d/m/Y', strtotime($user['created_at'])) ?></span>
                </div>
            </div>
        </div>

        <!-- Consentimentos LGPD -->
        <div class="profile-card">
            <div class="profile-body">
                <h5 class="mb-3"><i class="bi bi-shield-check"></i> Consentimentos LGPD</h5>
                <p class="text-muted small mb-3">
                    Conforme a Lei Geral de Proteção de Dados (LGPD), você pode visualizar e gerenciar seus consentimentos.
                </p>

                <?php if (empty($consents)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i> Nenhum consentimento registrado ainda.
                    </div>
                <?php else: ?>
                    <?php
                    $consentTypes = [
                        'terms_of_use' => 'Termos de Uso',
                        'privacy_policy' => 'Política de Privacidade',
                        'data_processing' => 'Processamento de Dados',
                        'marketing' => 'Comunicações de Marketing'
                    ];
                    foreach ($consents as $consent):
                        $isRevoked = !empty($consent['revoked_at']);
                    ?>
                    <div class="consent-item <?= $isRevoked ? 'revoked' : 'active' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong><?= $consentTypes[$consent['consent_type']] ?? ucfirst($consent['consent_type']) ?></strong>
                                <?php if ($isRevoked): ?>
                                    <span class="badge bg-danger ms-2">Revogado</span>
                                <?php else: ?>
                                    <span class="badge bg-success ms-2">Ativo</span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <?= date('d/m/Y H:i', strtotime($consent['created_at'])) ?>
                            </small>
                        </div>
                        <?php if ($isRevoked): ?>
                            <small class="text-muted d-block mt-1">
                                Revogado em: <?= date('d/m/Y H:i', strtotime($consent['revoked_at'])) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Zona de Perigo -->
        <div class="danger-zone">
            <h5 class="text-danger mb-3"><i class="bi bi-exclamation-triangle"></i> Zona de Perigo</h5>
            <p class="text-muted mb-3">
                Ações irreversíveis relacionadas à sua conta. Proceda com cuidado.
            </p>
            <a href="<?= BASE_URL ?>/delete-account.php" class="btn btn-outline-danger">
                <i class="bi bi-trash"></i> Remover Minha Conta
            </a>
            <p class="small text-muted mt-2 mb-0">
                <i class="bi bi-info-circle"></i> Conforme LGPD Art. 18, você tem direito à eliminação dos seus dados pessoais.
            </p>
        </div>

        <!-- Navegação -->
        <div class="nav-buttons">
            <?php if ($user['selected_vertical']): ?>
                <a href="<?= BASE_URL ?>/areas/<?= $user['selected_vertical'] ?>/" class="btn btn-light me-2">
                    <i class="bi bi-grid"></i> Minha Vertical
                </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-light me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-light">
                <i class="bi bi-box-arrow-right"></i> Sair
            </a>
        </div>

        <!-- Footer -->
        <div class="text-center mt-4">
            <p class="text-white-50 small mb-0">
                <i class="bi bi-shield-check"></i> Seus dados estão protegidos pela LGPD
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
