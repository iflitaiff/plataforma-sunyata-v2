<?php
/**
 * Nicolay Advogados - Menu Principal
 * Vertical dedicada para escritório Nicolay Advogados
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar acesso à vertical
if (!isset($_SESSION['user']['selected_vertical'])) {
    $_SESSION['error'] = 'Por favor, complete o onboarding primeiro';
    redirect(BASE_URL . '/onboarding-step1.php');
}

$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

// Verificar se tem acesso
if ($user_vertical !== 'nicolay-advogados' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Buscar dados da vertical do banco
$db = Database::getInstance();
$verticalData = $db->fetchOne("
    SELECT name FROM verticals
    WHERE slug = 'nicolay-advogados' AND is_active = TRUE
");

// Buscar Canvas da vertical do banco
$canvas_list = $db->fetchAll("
    SELECT * FROM canvas
    WHERE vertical = 'nicolay-advogados' AND is_active = TRUE
    ORDER BY display_order ASC
");

$verticalName = $verticalData['name'] ?? 'Nicolay Advogados';
$pageTitle = $verticalName;
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
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            min-height: 100vh;
            padding: 3rem 0;
        }
        .container-custom {
            max-width: 1200px;
        }
        .header {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 3rem;
            text-align: center;
        }
        .header h1 {
            color: #1a365d;
            margin-bottom: 0.5rem;
        }
        .header p {
            color: #6c757d;
            margin-bottom: 0;
        }
        .tool-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
            border: 2px solid transparent;
        }
        .tool-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
            border-color: #1a365d;
        }
        .tool-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .tool-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 0.5rem;
        }
        .tool-description {
            color: #6c757d;
            margin-bottom: 1rem;
        }
        .tool-badge {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .nav-buttons {
            text-align: center;
            margin-top: 2rem;
        }
        .alert-info-custom {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: none;
            border-left: 4px solid #1976d2;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <div class="container container-custom">
        <!-- Header -->
        <div class="header">
            <h1><?= sanitize_output($verticalName) ?></h1>
            <p>Bem-vindo(a), <strong><?= sanitize_output($_SESSION['user']['name']) ?></strong></p>
            <p class="text-muted small">Ferramentas de IA para análise jurídica</p>
        </div>

        <!-- Canvas Disponíveis -->
        <div class="row">
            <div class="col-12">
                <h3 class="text-white mb-3">Ferramentas Disponíveis</h3>
            </div>

            <?php if (empty($canvas_list)): ?>
                <div class="col-12">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        Nenhum Canvas disponível no momento. Entre em contato com o suporte.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($canvas_list as $canvas): ?>
                    <div class="col-md-12">
                        <?php
                        // Rota B: link direto para formulario.php (sem agrupador canvas)
                        if ($canvas['type'] === 'forms') {
                            $canvas_url = BASE_URL . "/areas/nicolay-advogados/formulario.php?template=" . $canvas['slug'];
                        } elseif ($canvas['type'] === 'page') {
                            $canvas_url = BASE_URL . $canvas['page_url'];
                        } else {
                            $canvas_url = $canvas['external_url'];
                        }
                        ?>
                        <a href="<?= $canvas_url ?>" class="tool-card">
                            <div class="text-center">
                                <div class="tool-icon"><?= $canvas['icon'] ?></div>
                                <div class="tool-title"><?= sanitize_output($canvas['name']) ?></div>
                                <?php if ($canvas['description']): ?>
                                    <p class="tool-description">
                                        <?= sanitize_output($canvas['description']) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($canvas['type'] === 'forms'): ?>
                                    <span class="tool-badge">Formulários Dinâmicos</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Navegação -->
        <div class="nav-buttons">
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-light me-2">
                Dashboard
            </a>
            <a href="<?= BASE_URL ?>/profile.php" class="btn btn-light me-2">
                Perfil
            </a>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-light">
                Sair
            </a>
        </div>

        <!-- Footer de Suporte -->
        <div style="margin-top: 3rem; text-align: center;">
            <div style="background: white; border-radius: 10px; padding: 1.5rem;">
                <h5 style="color: #1a365d;">Precisa de ajuda?</h5>
                <p style="color: #6c757d; margin-bottom: 1rem;">
                    Para reportar erros, esclarecer dúvidas ou sugestões:
                </p>
                <a href="https://chat.whatsapp.com/HEyyAyoS4bb6ycTMs0kLWq?mode=wwc"
                   target="_blank"
                   class="btn btn-success me-2">
                    WhatsApp
                </a>
                <a href="mailto:contato@sunyataconsulting.com"
                   class="btn btn-primary">
                    Email
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
