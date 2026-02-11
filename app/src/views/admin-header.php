<?php
/**
 * Admin Header Component - Mobile Responsive
 * Includes navbar with hamburger menu and offcanvas sidebar
 */

use Sunyata\Core\Database;

// Get pending requests count for badge
$pending_requests = 0;
if (isset($stats['pending_requests'])) {
    $pending_requests = $stats['pending_requests'];
} else {
    $db_temp = Database::getInstance();
    $result = $db_temp->fetchOne("SELECT COUNT(*) as count FROM vertical_access_requests WHERE status = 'pending'");
    $pending_requests = $result['count'] ?? 0;
}

// Determine active page
$current_page = basename($_SERVER['PHP_SELF'], '.php');
if ($current_page === 'index') {
    $current_page = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Admin' ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Desktop Sidebar */
        @media (min-width: 768px) {
            .admin-sidebar {
                min-height: calc(100vh - 56px);
                background: #f8f9fa;
                border-right: 1px solid #dee2e6;
            }
        }

        /* Mobile: Hide sidebar, show offcanvas */
        @media (max-width: 767px) {
            .admin-sidebar {
                display: none;
            }
        }

        .admin-nav-link {
            color: #495057;
            padding: 0.75rem 1rem;
            display: block;
            text-decoration: none;
            border-radius: 0.375rem;
            margin-bottom: 0.25rem;
        }
        .admin-nav-link:hover {
            background: #e9ecef;
            color: #212529;
        }
        .admin-nav-link.active {
            background: #0d6efd;
            color: white;
        }

        /* Offcanvas menu styling */
        .offcanvas-start {
            width: 280px;
        }

        /* Stat cards hover effect */
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }

        /* Responsive table improvements */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Mobile table adjustments */
        @media (max-width: 767px) {
            .table {
                font-size: 0.875rem;
            }
            .table th,
            .table td {
                padding: 0.5rem 0.25rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <!-- Mobile Menu Toggle -->
            <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar">
                <span class="navbar-toggler-icon"></span>
            </button>

            <a class="navbar-brand" href="<?= BASE_URL ?>/admin/">
                <i class="bi bi-shield-lock"></i>
                <span class="d-none d-sm-inline">Admin - <?= APP_NAME ?></span>
                <span class="d-inline d-sm-none">Admin</span>
            </a>

            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="<?= BASE_URL ?>/dashboard.php">
                    <i class="bi bi-box-arrow-left"></i>
                    <span class="d-none d-sm-inline">Voltar ao Portal</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Desktop Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 admin-sidebar">
                <div class="p-3">
                    <h6 class="text-muted text-uppercase small mb-3">Menu</h6>
                    <a href="<?= BASE_URL ?>/admin/" class="admin-nav-link <?= $current_page === 'dashboard' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a href="<?= BASE_URL ?>/admin/users.php" class="admin-nav-link <?= $current_page === 'users' ? 'active' : '' ?>">
                        <i class="bi bi-people"></i> Usuários
                    </a>
                    <a href="<?= BASE_URL ?>/admin/access-requests.php" class="admin-nav-link <?= $current_page === 'access-requests' ? 'active' : '' ?>">
                        <i class="bi bi-key"></i> Solicitações
                        <?php if ($pending_requests > 0): ?>
                            <span class="badge bg-danger"><?= $pending_requests ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/canvas-templates.php" class="admin-nav-link <?= $current_page === 'canvas-templates' ? 'active' : '' ?>">
                        <i class="bi bi-grid-3x3"></i> Canvas Templates
                    </a>
                    <a href="<?= BASE_URL ?>/admin/canvas-editor.php" class="admin-nav-link <?= $current_page === 'canvas-editor' ? 'active' : '' ?>">
                        <i class="bi bi-palette"></i> Survey Creator
                    </a>
                    <a href="<?= BASE_URL ?>/admin/verticals-config.php" class="admin-nav-link <?= $current_page === 'verticals-config' ? 'active' : '' ?>">
                        <i class="bi bi-sliders"></i> Config Verticais
                    </a>
                    <a href="<?= BASE_URL ?>/admin/portal-config.php" class="admin-nav-link <?= $current_page === 'portal-config' ? 'active' : '' ?>">
                        <i class="bi bi-globe"></i> Config Portal
                    </a>
                    <a href="<?= BASE_URL ?>/admin/prompt-history.php" class="admin-nav-link <?= $current_page === 'prompt-history' ? 'active' : '' ?>">
                        <i class="bi bi-chat-dots"></i> Histórico de Prompts
                    </a>
                    <a href="<?= BASE_URL ?>/admin/user-report.php" class="admin-nav-link <?= $current_page === 'user-report' ? 'active' : '' ?>">
                        <i class="bi bi-bar-chart-line"></i> Relatório de Uso
                    </a>
                    <a href="<?= BASE_URL ?>/admin/audit-logs.php" class="admin-nav-link <?= $current_page === 'audit-logs' ? 'active' : '' ?>">
                        <i class="bi bi-journal-text"></i> Logs de Auditoria
                    </a>
                    <a href="<?= BASE_URL ?>/admin/system-logs.php" class="admin-nav-link <?= $current_page === 'system-logs' ? 'active' : '' ?>">
                        <i class="bi bi-terminal"></i> System Logs
                    </a>
                </div>
            </div>

            <!-- Mobile Offcanvas Sidebar -->
            <div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="adminSidebar" aria-labelledby="adminSidebarLabel">
                <div class="offcanvas-header bg-dark text-white">
                    <h5 class="offcanvas-title" id="adminSidebarLabel">
                        <i class="bi bi-shield-lock"></i> Menu Admin
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
                </div>
                <div class="offcanvas-body">
                    <h6 class="text-muted text-uppercase small mb-3">Navegação</h6>
                    <a href="<?= BASE_URL ?>/admin/" class="admin-nav-link <?= $current_page === 'dashboard' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a href="<?= BASE_URL ?>/admin/users.php" class="admin-nav-link <?= $current_page === 'users' ? 'active' : '' ?>">
                        <i class="bi bi-people"></i> Usuários
                    </a>
                    <a href="<?= BASE_URL ?>/admin/access-requests.php" class="admin-nav-link <?= $current_page === 'access-requests' ? 'active' : '' ?>">
                        <i class="bi bi-key"></i> Solicitações
                        <?php if ($pending_requests > 0): ?>
                            <span class="badge bg-danger"><?= $pending_requests ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/canvas-templates.php" class="admin-nav-link <?= $current_page === 'canvas-templates' ? 'active' : '' ?>">
                        <i class="bi bi-grid-3x3"></i> Canvas Templates
                    </a>
                    <a href="<?= BASE_URL ?>/admin/canvas-editor.php" class="admin-nav-link <?= $current_page === 'canvas-editor' ? 'active' : '' ?>">
                        <i class="bi bi-palette"></i> Survey Creator
                    </a>
                    <a href="<?= BASE_URL ?>/admin/verticals-config.php" class="admin-nav-link <?= $current_page === 'verticals-config' ? 'active' : '' ?>">
                        <i class="bi bi-sliders"></i> Config Verticais
                    </a>
                    <a href="<?= BASE_URL ?>/admin/portal-config.php" class="admin-nav-link <?= $current_page === 'portal-config' ? 'active' : '' ?>">
                        <i class="bi bi-globe"></i> Config Portal
                    </a>
                    <a href="<?= BASE_URL ?>/admin/prompt-history.php" class="admin-nav-link <?= $current_page === 'prompt-history' ? 'active' : '' ?>">
                        <i class="bi bi-chat-dots"></i> Histórico de Prompts
                    </a>
                    <a href="<?= BASE_URL ?>/admin/user-report.php" class="admin-nav-link <?= $current_page === 'user-report' ? 'active' : '' ?>">
                        <i class="bi bi-bar-chart-line"></i> Relatório de Uso
                    </a>
                    <a href="<?= BASE_URL ?>/admin/audit-logs.php" class="admin-nav-link <?= $current_page === 'audit-logs' ? 'active' : '' ?>">
                        <i class="bi bi-journal-text"></i> Logs de Auditoria
                    </a>
                    <a href="<?= BASE_URL ?>/admin/system-logs.php" class="admin-nav-link <?= $current_page === 'system-logs' ? 'active' : '' ?>">
                        <i class="bi bi-terminal"></i> System Logs
                    </a>

                    <hr>

                    <a href="<?= BASE_URL ?>/dashboard.php" class="admin-nav-link">
                        <i class="bi bi-box-arrow-left"></i> Voltar ao Portal
                    </a>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 px-3 px-md-4 py-4">
