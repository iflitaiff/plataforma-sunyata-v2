<?php
/**
 * IATR - Visualizar Formulários de um Canvas
 * Lista todos os formulários (canvas_templates) de um Canvas específico
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar acesso à vertical IATR
$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

if ($user_vertical !== 'iatr' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Pegar ID do Canvas
$canvas_id = $_GET['id'] ?? null;

if (!$canvas_id || !is_numeric($canvas_id)) {
    $_SESSION['error'] = 'Canvas inválido';
    redirect(BASE_URL . '/areas/iatr/index.php');
}

$db = Database::getInstance();

// Buscar Canvas
$canvas = $db->fetchOne("
    SELECT * FROM canvas
    WHERE id = :id AND vertical = 'iatr' AND is_active = TRUE
", ['id' => $canvas_id]);

if (!$canvas) {
    $_SESSION['error'] = 'Canvas não encontrado ou inativo';
    redirect(BASE_URL . '/areas/iatr/index.php');
}

// Buscar Formulários deste Canvas
$formularios = $db->fetchAll("
    SELECT * FROM canvas_templates
    WHERE canvas_id = :canvas_id AND is_active = TRUE
    ORDER BY name ASC
", ['canvas_id' => $canvas_id]);

$pageTitle = $canvas['name'];
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
            background: linear-gradient(135deg, <?= $canvas['color'] ?? '#667eea' ?> 0%, #764ba2 100%);
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
        .form-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
            border-left: 4px solid <?= $canvas['color'] ?? '#667eea' ?>;
        }
        .form-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 25px rgba(0,0,0,0.15);
        }
        .form-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 0.25rem;
        }
        .form-version {
            font-size: 0.7rem;
            font-weight: 400;
            color: #9ca3af;
            margin-left: 0.4rem;
            vertical-align: middle;
        }
        .form-slug {
            font-size: 0.85rem;
            color: #6c757d;
            font-family: monospace;
        }
        .nav-buttons {
            text-align: center;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="container container-custom">
        <!-- Header -->
        <div class="header">
            <h1><?= $canvas['icon'] ?> <?= sanitize_output($canvas['name']) ?></h1>
            <?php if ($canvas['description']): ?>
                <p><?= sanitize_output($canvas['description']) ?></p>
            <?php endif; ?>
            <p class="text-muted small">
                <?= count($formularios) ?> formulário(s) disponível(is)
            </p>
        </div>

        <!-- Formulários -->
        <div class="row">
            <div class="col-12">
                <h3 class="text-white mb-3">📋 Formulários</h3>
            </div>

            <?php if (empty($formularios)): ?>
                <div class="col-12">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        Nenhum formulário disponível neste Canvas no momento.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($formularios as $form): ?>
                    <div class="col-md-6">
                        <a href="<?= BASE_URL ?>/areas/iatr/formulario.php?template=<?= $form['slug'] ?>" class="form-card">
                            <div class="form-title">
                                <?= sanitize_output($form['name']) ?>
                                <?php if (!empty($form['current_version'])): ?>
                                    <span class="form-version">v<?= $form['current_version'] ?>.0</span>
                                <?php endif; ?>
                            </div>
                            <div class="form-slug"><?= $form['slug'] ?></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Navegação -->
        <div class="nav-buttons">
            <a href="<?= BASE_URL ?>/areas/iatr/index.php" class="btn btn-light me-2">
                ← Voltar para Canvas
            </a>
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-light me-2">
                🏠 Dashboard
            </a>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-light">
                🚪 Sair
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
