<?php
/**
 * Prompt Builder - Administrativo - Menu Principal
 * Ferramentas para construção e otimização de prompts
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar acesso à vertical prompt_builder
if (!isset($_SESSION['user']['selected_vertical'])) {
    $_SESSION['error'] = 'Por favor, complete o onboarding primeiro';
    redirect(BASE_URL . '/onboarding-step1.php');
}

$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

// Verificar se tem acesso (prompt-builder, demo ou admin)
if ($user_vertical !== 'prompt-builder' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Buscar dados da vertical prompt-builder do banco
$db = Database::getInstance();
$verticalData = $db->fetchOne("
    SELECT name FROM verticals
    WHERE slug = 'prompt-builder' AND is_active = 1
");

// Buscar Canvas/ferramentas da vertical prompt-builder do banco
$canvas_list = $db->fetchAll("
    SELECT * FROM canvas
    WHERE vertical = 'prompt-builder' AND is_active = 1
    ORDER BY display_order ASC
");

$verticalName = $verticalData['name'] ?? 'Prompt Builder - Administrativo';
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            border-color: #0d6efd;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            <p class="text-muted small">Ferramentas para construção e otimização de prompts</p>
        </div>

        <!-- Info -->
        <div class="alert alert-info-custom">
            <h5 class="mb-2"><i class="bi bi-info-circle"></i> Sobre esta vertical</h5>
            <p class="mb-0">
                Esta vertical oferece ferramentas especializadas para ajudar na construção de prompts
                efetivos para uso com modelos de IA como Claude, Gemini e GPT. As ferramentas aqui
                disponíveis ajudam a estruturar suas solicitações de forma clara e precisa.
            </p>
        </div>

        <!-- Ferramentas Disponíveis -->
        <div class="row">
            <div class="col-12">
                <h3 class="text-white mb-3"><i class="bi bi-tools"></i> Ferramentas Disponíveis</h3>
            </div>

            <?php if (empty($canvas_list)): ?>
                <div class="col-12">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        Nenhuma ferramenta disponível no momento. Entre em contato com o suporte.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($canvas_list as $canvas): ?>
                    <div class="col-md-12">
                        <?php
                        // Determinar URL baseado no tipo
                        if ($canvas['type'] === 'forms') {
                            $canvas_url = BASE_URL . "/areas/prompt-builder/canvas.php?id=" . $canvas['id'];
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
                                <?php if ($canvas['type'] === 'page'): ?>
                                    <span class="tool-badge"><i class="bi bi-window"></i> Aplicativo Standalone</span>
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
                <i class="bi bi-house"></i> Dashboard
            </a>
            <a href="<?= BASE_URL ?>/profile.php" class="btn btn-light me-2">
                <i class="bi bi-person"></i> Perfil
            </a>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-light">
                <i class="bi bi-box-arrow-right"></i> Sair
            </a>
        </div>

        <!-- Footer de Suporte -->
        <div style="margin-top: 3rem; text-align: center;">
            <div style="background: white; border-radius: 10px; padding: 1.5rem;">
                <h5 style="color: #1a365d;"><i class="bi bi-chat-dots"></i> Precisa de ajuda?</h5>
                <p style="color: #6c757d; margin-bottom: 1rem;">
                    Para reportar erros, esclarecer dúvidas ou sugestões:
                </p>
                <a href="https://chat.whatsapp.com/HEyyAyoS4bb6ycTMs0kLWq?mode=wwc"
                   target="_blank"
                   class="btn btn-success me-2">
                    <i class="bi bi-whatsapp"></i> WhatsApp
                </a>
                <a href="mailto:contato@sunyataconsulting.com"
                   class="btn btn-primary">
                    <i class="bi bi-envelope"></i> Email
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
