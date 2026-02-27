<?php
/**
 * Admin Layout — Tabler dark navbar + admin sidebar + content.
 *
 * Variables expected:
 *   $pageTitle  (string)  — page title
 *   $headExtra  (string)  — extra <head> HTML (optional)
 *   $activeNav  (string)  — active sidebar item slug (optional)
 */

use Sunyata\Core\Database;

// Pending requests badge
$pending_requests = 0;
try {
    $db_temp = Database::getInstance();
    $result = $db_temp->fetchOne("SELECT COUNT(*) as count FROM vertical_access_requests WHERE status = 'pending'");
    $pending_requests = $result['count'] ?? 0;
} catch (\Exception $e) {
    // non-fatal
}

$current_page = $activeNav ?? basename($_SERVER['PHP_SELF'], '.php');
if ($current_page === 'index') $current_page = 'dashboard';

$bodyClass = 'layout-fluid';

$adminNavItems = [
    ['slug' => 'dashboard',         'icon' => 'ti-dashboard',          'label' => 'Dashboard',           'url' => '/admin/'],
    ['slug' => 'users',             'icon' => 'ti-users',              'label' => 'Usuarios',            'url' => '/admin/users.php'],
    ['slug' => 'access-requests',   'icon' => 'ti-key',               'label' => 'Solicitacoes',        'url' => '/admin/access-requests.php', 'badge' => $pending_requests],
    ['slug' => 'canvas-templates',  'icon' => 'ti-layout-grid',       'label' => 'Canvas Templates',    'url' => '/admin/canvas-templates.php'],
    ['slug' => 'canvas-editor',     'icon' => 'ti-palette',           'label' => 'Survey Creator',      'url' => '/admin/canvas-editor.php'],
    ['slug' => 'verticals-config',  'icon' => 'ti-adjustments',       'label' => 'Config Verticais',    'url' => '/admin/verticals-config.php'],
    ['slug' => 'portal-config',     'icon' => 'ti-world',             'label' => 'Config Portal',       'url' => '/admin/portal-config.php'],
    ['slug' => 'prompt-history',    'icon' => 'ti-message-dots',      'label' => 'Historico Prompts',   'url' => '/admin/prompt-history.php'],
    ['slug' => 'user-report',       'icon' => 'ti-chart-bar',         'label' => 'Relatorio de Uso',    'url' => '/admin/user-report.php'],
    ['slug' => 'audit-logs',        'icon' => 'ti-file-text',         'label' => 'Logs Auditoria',      'url' => '/admin/audit-logs.php'],
    ['slug' => 'system-events',     'icon' => 'ti-activity',          'label' => 'System Events',       'url' => '/areas/admin/system-logs.php'],
    ['slug' => 'system-logs',       'icon' => 'ti-terminal',          'label' => 'System Logs',         'url' => '/admin/system-logs.php'],
];

$contentCallback = function () use (&$pageContent, $current_page, $adminNavItems) {
?>
    <div class="page">
        <!-- Admin Navbar -->
        <header class="navbar navbar-expand-md navbar-dark d-print-none" data-bs-theme="dark">
            <div class="container-xl">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#admin-navbar">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <a href="<?= BASE_URL ?>/admin/" class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
                    <i class="ti ti-shield-lock me-2"></i>
                    <span class="fw-bold">Admin - <?= APP_NAME ?></span>
                </a>

                <div class="navbar-nav flex-row order-md-last">
                    <a class="nav-link" href="<?= BASE_URL ?>/dashboard.php" hx-boost="false">
                        <i class="ti ti-arrow-left me-1"></i>
                        <span class="d-none d-sm-inline">Voltar ao Portal</span>
                    </a>
                </div>

                <!-- Mobile nav collapse -->
                <div class="collapse navbar-collapse" id="admin-navbar">
                    <div class="d-md-none py-2">
                        <?php foreach ($adminNavItems as $item): ?>
                            <a href="<?= BASE_URL . $item['url'] ?>"
                               class="dropdown-item <?= $current_page === $item['slug'] ? 'active' : '' ?>">
                                <i class="ti <?= $item['icon'] ?> me-2"></i>
                                <?= $item['label'] ?>
                                <?php if (!empty($item['badge'])): ?>
                                    <span class="badge bg-danger ms-auto"><?= $item['badge'] ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </header>

        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <div class="row g-4">
                        <!-- Desktop Sidebar -->
                        <div class="col-md-3 col-lg-2 d-none d-md-block">
                            <div class="card">
                                <div class="card-body p-2">
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($adminNavItems as $item): ?>
                                            <a href="<?= BASE_URL . $item['url'] ?>"
                                               class="list-group-item list-group-item-action d-flex align-items-center <?= $current_page === $item['slug'] ? 'active' : '' ?>">
                                                <i class="ti <?= $item['icon'] ?> me-2"></i>
                                                <span class="flex-grow-1"><?= $item['label'] ?></span>
                                                <?php if (!empty($item['badge'])): ?>
                                                    <span class="badge bg-danger"><?= $item['badge'] ?></span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Main Content -->
                        <div class="col-md-9 col-lg-10">
                            <div id="page-content">
                                <?php if (isset($pageContent)) call_user_func($pageContent); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
};

include __DIR__ . '/base.php';
