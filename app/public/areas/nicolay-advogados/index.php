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

if (!isset($_SESSION['user']['selected_vertical'])) {
    $_SESSION['error'] = 'Por favor, complete o onboarding primeiro';
    redirect(BASE_URL . '/onboarding-step1.php');
}

$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

if ($user_vertical !== 'nicolay-advogados' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();
$verticalData = $db->fetchOne("
    SELECT name FROM verticals
    WHERE slug = 'nicolay-advogados' AND is_active = TRUE
");

$canvas_list = $db->fetchAll("
    SELECT * FROM canvas_templates
    WHERE vertical = 'nicolay-advogados' AND is_active = TRUE
    ORDER BY category ASC, display_order ASC, name ASC
");

// Agrupar por categoria
$categories = [];
foreach ($canvas_list as $canvas) {
    $cat = $canvas['category'] ?? 'geral';
    $categories[$cat][] = $canvas;
}

$categoryMeta = [
    'analise'     => ['label' => 'Análise & Pesquisa',       'icon' => 'bi-search',       'color' => '#667eea'],
    'documentos'  => ['label' => 'Documentos & Pareceres',   'icon' => 'bi-file-earmark-text', 'color' => '#764ba2'],
    'gestao'      => ['label' => 'Gestão & Compliance',      'icon' => 'bi-shield-check', 'color' => '#28a745'],
    'ferramentas' => ['label' => 'Ferramentas de Documento',  'icon' => 'bi-tools',        'color' => '#fd7e14'],
    'geral'       => ['label' => 'Geral',                    'icon' => 'bi-grid',         'color' => '#6c757d'],
];
$categoryOrder = ['geral', 'analise', 'documentos', 'gestao', 'ferramentas'];

$verticalName = $verticalData['name'] ?? 'Nicolay Advogados';
$verticalSlug = 'nicolay-advogados';
$pageTitle = $verticalName;
$bgGradient = 'linear-gradient(135deg, #1a365d 0%, #2d3748 100%)';
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
            background: <?= $bgGradient ?>;
            min-height: 100vh;
            padding: 2rem 0;
        }
        .container-custom { max-width: 1200px; }
        .header {
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            text-align: center;
        }
        .header h1 { color: #1a365d; margin-bottom: 0.25rem; font-size: 1.8rem; }
        .header p { color: #6c757d; margin-bottom: 0; }
        .category-section { margin-bottom: 1.5rem; }
        .category-header {
            display: flex; align-items: center; gap: 0.5rem;
            margin-bottom: 0.75rem; padding-bottom: 0.5rem;
            border-bottom: 2px solid rgba(255,255,255,0.3);
        }
        .category-header h4 { color: white; margin: 0; font-size: 1.1rem; font-weight: 600; }
        .category-header i { color: white; font-size: 1.1rem; }
        .category-count { color: rgba(255,255,255,0.7); font-size: 0.8rem; margin-left: auto; }
        .tool-card {
            background: white; border-radius: 10px; padding: 1rem 1.25rem;
            margin-bottom: 0.75rem; box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            transition: all 0.2s ease; text-decoration: none; color: inherit;
            display: flex; align-items: center; gap: 0.75rem;
            border-left: 4px solid transparent;
        }
        .tool-card:hover { transform: translateX(4px); box-shadow: 0 4px 20px rgba(0,0,0,0.12); color: inherit; }
        .tool-card-icon { font-size: 1.5rem; flex-shrink: 0; width: 2.5rem; text-align: center; }
        .tool-card-body { flex: 1; min-width: 0; }
        .tool-card-title {
            font-size: 0.95rem; font-weight: 600; color: #1a365d; margin: 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .tool-card-arrow { color: #ccc; font-size: 0.85rem; flex-shrink: 0; }
        .tool-card:hover .tool-card-arrow { color: #667eea; }
        .nav-buttons { text-align: center; margin-top: 1.5rem; }
    </style>
</head>
<body>
    <div class="container container-custom">
        <div class="header">
            <h1><?= sanitize_output($verticalName) ?></h1>
            <p>Bem-vindo(a), <strong><?= sanitize_output($_SESSION['user']['name']) ?></strong></p>
            <p class="text-muted small mt-1"><?= count($canvas_list) ?> ferramentas disponíveis</p>
        </div>

        <?php if (empty($canvas_list)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Nenhum Canvas disponível no momento. Entre em contato com o suporte.
            </div>
        <?php else: ?>
            <?php foreach ($categoryOrder as $catKey): ?>
                <?php if (!isset($categories[$catKey])) continue; ?>
                <?php $items = $categories[$catKey]; $meta = $categoryMeta[$catKey] ?? $categoryMeta['geral']; ?>
                <div class="category-section">
                    <div class="category-header">
                        <i class="bi <?= $meta['icon'] ?>"></i>
                        <h4><?= $meta['label'] ?></h4>
                        <span class="category-count"><?= count($items) ?></span>
                    </div>
                    <div class="row">
                        <?php foreach ($items as $canvas): ?>
                            <?php
                            if ($canvas['type'] === 'forms') {
                                $canvas_url = BASE_URL . "/areas/{$verticalSlug}/formulario.php?template=" . $canvas['slug'];
                            } elseif ($canvas['type'] === 'page') {
                                $canvas_url = BASE_URL . $canvas['page_url'];
                            } else {
                                $canvas_url = $canvas['external_url'];
                            }
                            $nameClean = preg_replace('/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{2702}-\x{27B0}\x{E0020}-\x{E007F}]+\s*/u', '', $canvas['name']);
                            $emoji = $canvas['icon'] ?? mb_substr($canvas['name'], 0, 1);
                            ?>
                            <div class="col-md-6">
                                <a href="<?= $canvas_url ?>" class="tool-card" style="border-left-color: <?= $meta['color'] ?>;">
                                    <div class="tool-card-icon"><?= $emoji ?></div>
                                    <div class="tool-card-body">
                                        <p class="tool-card-title"><?= sanitize_output($nameClean) ?></p>
                                    </div>
                                    <div class="tool-card-arrow"><i class="bi bi-chevron-right"></i></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="nav-buttons">
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-light me-2">Dashboard</a>
            <a href="<?= BASE_URL ?>/profile.php" class="btn btn-light me-2">Perfil</a>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-light">Sair</a>
        </div>

        <div style="margin-top: 2rem; text-align: center;">
            <div style="background: white; border-radius: 10px; padding: 1rem 1.5rem;">
                <p style="color: #6c757d; margin-bottom: 0.5rem; font-size: 0.9rem;">Precisa de ajuda?</p>
                <a href="https://chat.whatsapp.com/HEyyAyoS4bb6ycTMs0kLWq?mode=wwc" target="_blank" class="btn btn-sm btn-success me-2">WhatsApp</a>
                <a href="mailto:flitaiff@gmail.com" class="btn btn-sm btn-primary">Email</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
