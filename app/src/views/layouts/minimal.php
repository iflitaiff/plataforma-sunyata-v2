<?php
/**
 * Minimal Layout — no chrome (login, onboarding, error pages).
 *
 * Variables expected:
 *   $pageTitle  (string)  — page title
 *   $headExtra  (string)  — extra <head> HTML (optional)
 */

$bodyClass = 'bg-muted d-flex flex-column';

$contentCallback = function () use (&$pageContent) {
?>
    <div class="page page-center">
        <div class="container container-tight py-4">
            <?php if (isset($pageContent)) call_user_func($pageContent); ?>
        </div>
    </div>
<?php
};

include __DIR__ . '/base.php';
