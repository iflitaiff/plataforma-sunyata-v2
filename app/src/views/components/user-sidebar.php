<?php
/**
 * User Sidebar Component — vertical tools + workspace navigation.
 *
 * Expects $activeNav to be set for highlighting.
 */

$user = $_SESSION['user'] ?? [];
$userVertical = $user['selected_vertical'] ?? null;
$isAdmin = ($user['access_level'] ?? '') === 'admin' || is_admin_email($user['email'] ?? '');

// Build navigation items
$navItems = [
    ['slug' => 'dashboard',     'icon' => 'ti-dashboard',      'label' => 'Dashboard',          'url' => '/dashboard.php'],
    ['slug' => 'meu-trabalho',  'icon' => 'ti-briefcase',      'label' => 'Meu Trabalho',       'url' => '/meu-trabalho/'],
    ['slug' => 'meus-documentos','icon' => 'ti-files',          'label' => 'Meus Documentos',    'url' => '/meus-documentos/'],
];

// Helper to add vertical tools to navItems
$addVerticalTools = function (array $verticalInfo, string $slug) use (&$navItems) {
    if (empty($verticalInfo['ferramentas'])) {
        return;
    }

    $navItems[] = ['divider' => true, 'label' => $verticalInfo['nome'] ?? ucfirst($slug)];

    $seenTools = [];
    foreach ($verticalInfo['ferramentas'] as $tool) {
        // Accept both legacy string entries and structured arrays
        if (is_string($tool)) {
            $label = $tool;
            $key = 'str:' . $label;
            if (isset($seenTools[$key])) {
                continue;
            }
            $seenTools[$key] = true;

            $slugified = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
            $slugified = trim($slugified, '-');

            $navItems[] = [
                'slug'  => $slugified ?: 'tool',
                'icon'  => 'ti-tool',
                'label' => $label,
                'url'   => "/areas/{$slug}/",
            ];
            continue;
        }

        $navItems[] = [
            'slug'  => $tool['slug'] ?? '',
            'icon'  => $tool['icone'] ?? 'ti-tool',
            'label' => $tool['nome'] ?? 'Ferramenta',
            'url'   => $tool['url'] ?? '#',
        ];
    }
};

// Vertical tools
$verticalManager = \Sunyata\Core\VerticalManager::getInstance();

if ($isAdmin) {
    // Admin sees ALL verticals
    $allVerticals = $verticalManager->getAllDisplayData();
    foreach ($allVerticals as $slug => $verticalInfo) {
        $addVerticalTools($verticalInfo, $slug);
    }
} elseif ($userVertical) {
    // Regular user sees only their selected vertical
    $verticalInfo = $verticalManager->getDisplayData($userVertical);
    if ($verticalInfo) {
        $addVerticalTools($verticalInfo, $userVertical);
    }
}

$activeSlug = $activeNav ?? '';
?>
<div class="card">
    <div class="card-body p-2">
        <div class="list-group list-group-flush">
            <?php foreach ($navItems as $item): ?>
                <?php if (!empty($item['divider'])): ?>
                    <div class="list-group-item bg-transparent border-0 pt-3 pb-1">
                        <small class="text-uppercase text-secondary fw-bold"><?= htmlspecialchars($item['label']) ?></small>
                    </div>
                <?php else: ?>
                    <?php
                    // Pages under /areas/ load SurveyJS and need full page loads (not HTMX partial)
                    $needsFullLoad = str_contains($item['url'] ?? '', '/areas/');
                    ?>
                    <a href="<?= BASE_URL . $item['url'] ?>"
                       <?= $needsFullLoad ? 'hx-boost="false"' : '' ?>
                       class="list-group-item list-group-item-action d-flex align-items-center <?= $activeSlug === $item['slug'] ? 'active' : '' ?>">
                        <i class="ti <?= $item['icon'] ?> me-2"></i>
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
