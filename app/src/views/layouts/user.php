<?php
/**
 * User Layout — Tabler navbar + sidebar + content area.
 *
 * Variables expected:
 *   $pageTitle  (string)  — page title
 *   $headExtra  (string)  — extra <head> HTML (optional)
 *   $activeNav  (string)  — active nav item slug (optional)
 *
 * Wraps content in Tabler page structure with user sidebar.
 */

$bodyClass = 'layout-fluid';

// HTMX partial request: return only page content (no navbar/sidebar shell).
// Without this, the full page structure (navbar + sidebar + #page-content) would
// be swapped INTO the existing #page-content, creating a broken nested layout.
if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
    if (isset($_SESSION['success'])) {
        echo '<div class="alert alert-success alert-dismissible" role="alert"><div class="d-flex"><div><i class="ti ti-check alert-icon"></i></div><div>' . htmlspecialchars($_SESSION['success']) . '</div></div><a class="btn-close" data-bs-dismiss="alert"></a></div>';
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-danger alert-dismissible" role="alert"><div class="d-flex"><div><i class="ti ti-alert-circle alert-icon"></i></div><div>' . htmlspecialchars($_SESSION['error']) . '</div></div><a class="btn-close" data-bs-dismiss="alert"></a></div>';
        unset($_SESSION['error']);
    }
    if (isset($pageContent)) call_user_func($pageContent);
    return;
}

$contentCallback = function () use (&$pageContent) {
    global $activeNav;
    $user = $_SESSION['user'] ?? [];
    $isAdmin = ($user['access_level'] ?? '') === 'admin';
?>
    <div class="page">
        <!-- Navbar -->
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-xl">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <a href="<?= BASE_URL ?>/dashboard.php" class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
                    <span class="text-primary fw-bold"><?= APP_NAME ?></span>
                </a>

                <div class="navbar-nav flex-row order-md-last">
                    <?php if ($isAdmin): ?>
                    <div class="nav-item d-none d-md-flex me-3">
                        <a href="<?= BASE_URL ?>/admin/" class="btn btn-ghost-dark btn-sm" hx-boost="false">
                            <i class="ti ti-shield-lock me-1"></i> Admin
                        </a>
                    </div>
                    <?php endif; ?>

                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown">
                            <?php if (!empty($_SESSION['picture'])): ?>
                                <span class="avatar avatar-sm" style="background-image: url(<?= htmlspecialchars($_SESSION['picture']) ?>)"></span>
                            <?php else: ?>
                                <span class="avatar avatar-sm bg-primary-lt">
                                    <?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?>
                                </span>
                            <?php endif; ?>
                            <div class="d-none d-xl-block ps-2">
                                <div><?= htmlspecialchars($_SESSION['name'] ?? '') ?></div>
                                <div class="mt-1 small text-secondary"><?= htmlspecialchars(ucfirst($user['access_level'] ?? 'guest')) ?></div>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                            <a href="<?= BASE_URL ?>/profile.php" class="dropdown-item">
                                <i class="ti ti-user me-2"></i> Perfil
                            </a>
                            <a href="<?= BASE_URL ?>/privacidade.php" class="dropdown-item">
                                <i class="ti ti-shield-check me-2"></i> Privacidade (LGPD)
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="<?= BASE_URL ?>/logout.php" class="dropdown-item" hx-boost="false">
                                <i class="ti ti-logout me-2"></i> Sair
                            </a>
                        </div>
                    </div>
                </div>

                <div class="collapse navbar-collapse" id="navbar-menu">
                    <!-- Empty — navigation is in sidebar -->
                </div>
            </div>
        </header>

        <!-- Sidebar + Content -->
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <div class="row g-4">
                        <!-- Sidebar (collapsible on mobile) -->
                        <div class="col-lg-3 col-xl-2 d-none d-lg-block">
                            <?php include __DIR__ . '/../components/user-sidebar.php'; ?>
                        </div>

                        <!-- Main Content -->
                        <div class="col-lg-9 col-xl-10">
                            <div id="page-content">
                                <!-- Flash messages -->
                                <?php if (isset($_SESSION['success'])): ?>
                                    <div class="alert alert-success alert-dismissible" role="alert">
                                        <div class="d-flex">
                                            <div><i class="ti ti-check alert-icon"></i></div>
                                            <div><?= htmlspecialchars($_SESSION['success']) ?></div>
                                        </div>
                                        <a class="btn-close" data-bs-dismiss="alert"></a>
                                    </div>
                                    <?php unset($_SESSION['success']); ?>
                                <?php endif; ?>

                                <?php if (isset($_SESSION['error'])): ?>
                                    <div class="alert alert-danger alert-dismissible" role="alert">
                                        <div class="d-flex">
                                            <div><i class="ti ti-alert-circle alert-icon"></i></div>
                                            <div><?= htmlspecialchars($_SESSION['error']) ?></div>
                                        </div>
                                        <a class="btn-close" data-bs-dismiss="alert"></a>
                                    </div>
                                    <?php unset($_SESSION['error']); ?>
                                <?php endif; ?>

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
