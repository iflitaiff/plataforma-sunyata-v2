<?php
/**
 * Área IATR - Menu Principal
 * Vertical de testes limitada a 5 usuários
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar acesso à vertical IATR
if (!isset($_SESSION['user']['selected_vertical'])) {
    $_SESSION['error'] = 'Por favor, complete o onboarding primeiro';
    redirect(BASE_URL . '/onboarding-step1.php');
}

$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

// Verificar se tem acesso (iatr, demo ou admin)
if ($user_vertical !== 'iatr' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Buscar dados da vertical IATR do banco
$db = Database::getInstance();
$verticalData = $db->fetchOne("
    SELECT name FROM verticals
    WHERE slug = 'iatr' AND is_active = TRUE
");

// Buscar Canvas da vertical IATR do banco, agrupados por categoria
$canvas_list = $db->fetchAll("
    SELECT * FROM canvas_templates
    WHERE vertical = 'iatr' AND is_active = TRUE
    ORDER BY category ASC, display_order ASC, name ASC
");

// Agrupar por categoria
$categories = [];
foreach ($canvas_list as $canvas) {
    $cat = $canvas['category'] ?? 'geral';
    $categories[$cat][] = $canvas;
}

// Labels e ícones para as categorias
$categoryMeta = [
    'analise'     => ['label' => 'Análise & Pesquisa',       'icon' => 'bi-search',       'color' => '#667eea'],
    'documentos'  => ['label' => 'Documentos & Pareceres',   'icon' => 'bi-file-earmark-text', 'color' => '#764ba2'],
    'gestao'      => ['label' => 'Gestão & Compliance',      'icon' => 'bi-shield-check', 'color' => '#28a745'],
    'ferramentas' => ['label' => 'Ferramentas de Documento',  'icon' => 'bi-tools',        'color' => '#fd7e14'],
    'geral'       => ['label' => 'Geral',                    'icon' => 'bi-grid',         'color' => '#6c757d'],
];

// Ordem fixa das categorias
$categoryOrder = ['geral', 'analise', 'documentos', 'gestao', 'ferramentas'];

$verticalName = $verticalData['name'] ?? 'IATR';
$pageTitle = $verticalName;
$activeNav = 'iatr';

$headExtra = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
    .category-section {
        margin-bottom: 1.5rem;
    }
    .category-header {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #e6e8eb;
    }
    .category-header h4 {
        color: #1e293b;
        margin: 0;
        font-size: 1.1rem;
        font-weight: 600;
    }
    .category-header i {
        font-size: 1.1rem;
    }
    .category-count {
        color: #94a3b8;
        font-size: 0.8rem;
        margin-left: auto;
    }
    .tool-card {
        background: #fff;
        border-radius: 8px;
        padding: 1rem 1.25rem;
        margin-bottom: 0.75rem;
        border: 1px solid #e6e8eb;
        transition: all 0.2s ease;
        text-decoration: none;
        color: inherit;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border-left: 4px solid transparent;
    }
    .tool-card:hover {
        transform: translateX(4px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        color: inherit;
    }
    .tool-card-icon {
        font-size: 1.5rem;
        flex-shrink: 0;
        width: 2.5rem;
        text-align: center;
    }
    .tool-card-body {
        flex: 1;
        min-width: 0;
    }
    .tool-card-title {
        font-size: 0.95rem;
        font-weight: 600;
        color: #1e293b;
        margin: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .tool-card-arrow {
        color: #ccc;
        font-size: 0.85rem;
        flex-shrink: 0;
    }
    .tool-card:hover .tool-card-arrow {
        color: var(--tblr-primary);
    }
</style>
HTML;

$pageContent = function () use ($verticalName, $canvas_list, $categories, $categoryOrder, $categoryMeta) {
?>
        <!-- Page Header -->
        <div class="page-header mb-4">
            <div class="row align-items-center">
                <div class="col-auto">
                    <h2 class="page-title"><?= sanitize_output($verticalName) ?></h2>
                    <div class="text-secondary mt-1"><?= count($canvas_list) ?> ferramentas disponíveis</div>
                </div>
            </div>
        </div>

        <?php if (empty($canvas_list)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Nenhum Canvas disponível no momento. Entre em contato com o suporte.
            </div>
        <?php else: ?>
            <?php foreach ($categoryOrder as $catKey): ?>
                <?php if (!isset($categories[$catKey])) continue; ?>
                <?php
                    $items = $categories[$catKey];
                    $meta = $categoryMeta[$catKey] ?? $categoryMeta['geral'];
                ?>
                <div class="category-section">
                    <div class="category-header">
                        <i class="bi <?= $meta['icon'] ?>" style="color: <?= $meta['color'] ?>;"></i>
                        <h4><?= $meta['label'] ?></h4>
                        <span class="category-count"><?= count($items) ?></span>
                    </div>
                    <div class="row">
                        <?php foreach ($items as $canvas): ?>
                            <?php
                            // Determinar URL baseado no tipo
                            // Rota B: link direto para formulario.php (sem agrupador canvas)
                            if ($canvas['type'] === 'forms') {
                                $canvas_url = BASE_URL . "/areas/iatr/formulario.php?template=" . $canvas['slug'];
                            } elseif ($canvas['type'] === 'page') {
                                $canvas_url = BASE_URL . $canvas['page_url'];
                            } else {
                                $canvas_url = $canvas['external_url'];
                            }
                            // Extrair emoji do nome (primeiro caractere multibyte)
                            $nameClean = preg_replace('/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{2702}-\x{27B0}\x{E0020}-\x{E007F}]+\s*/u', '', $canvas['name']);
                            $emoji = $canvas['icon'] ?? mb_substr($canvas['name'], 0, 1);
                            ?>
                            <div class="col-md-6">
                                <a href="<?= $canvas_url ?>" hx-boost="false" class="tool-card" style="border-left-color: <?= $meta['color'] ?>;">
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

        <!-- Suporte -->
        <div class="card mt-4">
            <div class="card-body text-center py-3">
                <span class="text-secondary me-2">Precisa de ajuda?</span>
                <a href="https://chat.whatsapp.com/HEyyAyoS4bb6ycTMs0kLWq?mode=wwc"
                   target="_blank"
                   class="btn btn-sm btn-success me-2">
                    WhatsApp
                </a>
                <a href="mailto:flitaiff@gmail.com"
                   class="btn btn-sm btn-outline-primary">
                    Email
                </a>
            </div>
        </div>
<?php
};

require __DIR__ . '/../../../src/views/layouts/user.php';
