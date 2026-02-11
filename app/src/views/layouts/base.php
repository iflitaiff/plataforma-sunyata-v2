<?php
/**
 * Base Layout — HTML shell with Tabler CDN + HTMX.
 *
 * Variables expected:
 *   $pageTitle       (string)  — page title
 *   $bodyClass       (string)  — extra body classes (optional)
 *   $headExtra       (string)  — extra <head> HTML (optional)
 *   $contentCallback (callable) — renders page content
 *
 * Supports ?partial=1 for HTMX partial loads (returns only content block).
 */

$isPartial = isset($_GET['partial']) && $_GET['partial'] === '1';
if (!$isPartial && isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true') {
    $isPartial = true;
}

if ($isPartial && isset($contentCallback)) {
    call_user_func($contentCallback);
    return;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Sunyata') ?> - <?= APP_NAME ?></title>

    <!-- Tabler CSS (superset of Bootstrap 5) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.31.0/dist/tabler-icons.min.css">

    <!-- Sunyata theme overrides -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/sunyata-theme.css">

    <?= $headExtra ?? '' ?>
</head>
<body class="<?= $bodyClass ?? '' ?>"
      hx-boost="true"
      hx-target="#page-content"
      hx-swap="innerHTML"
      hx-push-url="true"
      hx-indicator="#page-loader">

    <!-- Global page loader for HTMX navigation -->
    <div id="page-loader" class="page-loader htmx-indicator">
        <div class="page-loader-content">
            <div class="spinner-border text-primary" role="status"></div>
        </div>
    </div>

    <?php if (isset($contentCallback)) call_user_func($contentCallback); ?>

    <!-- Tabler JS -->
    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/js/tabler.min.js"></script>

    <!-- HTMX + SSE extension -->
    <script src="https://unpkg.com/htmx.org@2.0.4"></script>
    <script src="https://unpkg.com/htmx-ext-sse@2.2.3/sse.js"></script>

    <!-- Marked.js for markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked@15.0.0/marked.min.js"></script>

    <!-- App JS -->
    <script src="<?= BASE_URL ?>/assets/js/app.js"></script>

    <?= $footerExtra ?? '' ?>
</body>
</html>
