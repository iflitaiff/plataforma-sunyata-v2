<?php
/**
 * Page Header Component — Tabler page header with breadcrumb + title + actions.
 *
 * Variables:
 *   $pageHeaderTitle   (string) — page title
 *   $pageHeaderPretitle (string) — small text above title (optional)
 *   $pageHeaderActions (string) — HTML for right-side action buttons (optional)
 */
?>
<div class="page-header d-print-none mb-4">
    <div class="row align-items-center">
        <div class="col-auto">
            <?php if (!empty($pageHeaderPretitle)): ?>
                <div class="page-pretitle"><?= htmlspecialchars($pageHeaderPretitle) ?></div>
            <?php endif; ?>
            <h2 class="page-title"><?= htmlspecialchars($pageHeaderTitle ?? $pageTitle ?? 'Pagina') ?></h2>
        </div>
        <?php if (!empty($pageHeaderActions)): ?>
            <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                    <?= $pageHeaderActions ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
