<?php
/**
 * Meu Trabalho — user workspace listing page.
 *
 * Shows all user submissions with filters (vertical, canvas, status, search, favorites).
 * Uses HTMX for filter changes and pagination without full page reload.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

use Sunyata\Core\Database;
use Sunyata\Core\VerticalManager;

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

// Get verticals for filter dropdown
$verticalManager = VerticalManager::getInstance();
$verticals = $verticalManager->getAllDisplayData();

// Get canvas templates the user has used
$usedCanvases = $db->fetchAll("
    SELECT DISTINCT ct.id, ct.name, ct.vertical
    FROM user_submissions us
    JOIN canvas_templates ct ON ct.id = us.canvas_template_id
    WHERE us.user_id = :user_id AND us.status != 'draft'
    ORDER BY ct.name
", ['user_id' => $userId]);

$pageTitle = 'Meu Trabalho';
$activeNav = 'meu-trabalho';

$pageContent = function () use ($verticals, $usedCanvases) {
?>

<?php
$pageHeaderTitle = 'Meu Trabalho';
$pageHeaderPretitle = 'Workspace';
include __DIR__ . '/../../src/views/components/page-header.php';
?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form id="filters-form">
            <div class="row g-3 align-items-end">
                <!-- Search -->
                <div class="col-lg-4">
                    <label class="form-label">Buscar</label>
                    <div class="input-icon">
                        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                        <input type="text" class="form-control" name="search" id="search-input"
                               placeholder="Buscar nas submissoes..."
                               value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                    </div>
                </div>

                <!-- Vertical filter -->
                <div class="col-lg-2">
                    <label class="form-label">Vertical</label>
                    <select class="form-select" name="vertical" id="filter-vertical">
                        <option value="">Todas</option>
                        <?php foreach ($verticals as $slug => $info): ?>
                            <?php if ($info['disponivel']): ?>
                            <option value="<?= htmlspecialchars($slug) ?>"
                                <?= ($_GET['vertical'] ?? '') === $slug ? 'selected' : '' ?>>
                                <?= $info['icone'] ?> <?= htmlspecialchars($info['nome']) ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Canvas filter -->
                <div class="col-lg-2">
                    <label class="form-label">Canvas</label>
                    <select class="form-select" name="canvas_id" id="filter-canvas">
                        <option value="">Todos</option>
                        <?php foreach ($usedCanvases as $ct): ?>
                        <option value="<?= $ct['id'] ?>"
                            <?= ($_GET['canvas_id'] ?? '') == $ct['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ct['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status filter -->
                <div class="col-lg-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status" id="filter-status">
                        <option value="">Todos</option>
                        <option value="completed" <?= ($_GET['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Concluido</option>
                        <option value="error" <?= ($_GET['status'] ?? '') === 'error' ? 'selected' : '' ?>>Erro</option>
                        <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pendente</option>
                        <option value="archived" <?= ($_GET['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Arquivado</option>
                    </select>
                </div>

                <!-- Quick filters -->
                <div class="col-lg-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="btn-group w-100">
                        <button type="button" class="btn btn-outline-warning btn-sm" id="btn-favorites"
                                title="Apenas favoritos">
                            <i class="ti ti-star"></i>
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btn-this-month"
                                title="Este mes">
                            <i class="ti ti-calendar"></i> Mes
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-clear-filters"
                                title="Limpar filtros">
                            <i class="ti ti-x"></i>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-body p-0" id="submissions-list"
         hx-get="<?= BASE_URL ?>/api/submissions/list.php?format=table&limit=20"
         hx-trigger="load"
         hx-swap="innerHTML"
         hx-indicator="#list-loading">
        <div id="list-loading" class="text-center p-4 text-secondary">
            <span class="spinner-border spinner-border-sm"></span> Carregando submissoes...
        </div>
    </div>
</div>

<script>
(function() {
    const listContainer = document.getElementById('submissions-list');
    const searchInput = document.getElementById('search-input');
    let favoriteOnly = false;
    let periodMonth = false;

    function buildFilterUrl() {
        const params = new URLSearchParams();
        params.set('format', 'table');
        params.set('limit', '20');

        const vertical = document.getElementById('filter-vertical').value;
        const canvasId = document.getElementById('filter-canvas').value;
        const status = document.getElementById('filter-status').value;
        const search = searchInput.value.trim();

        if (vertical) params.set('vertical', vertical);
        if (canvasId) params.set('canvas_id', canvasId);
        if (status) params.set('status', status);
        if (search.length >= 2) params.set('search', search);
        if (favoriteOnly) params.set('is_favorite', '1');
        if (periodMonth) params.set('period', 'month');

        return '<?= BASE_URL ?>/api/submissions/list.php?' + params.toString();
    }

    function refreshList() {
        htmx.ajax('GET', buildFilterUrl(), { target: '#submissions-list', swap: 'innerHTML' });
    }

    // Debounced search
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(refreshList, 400);
    });

    // Select filters
    document.getElementById('filter-vertical').addEventListener('change', refreshList);
    document.getElementById('filter-canvas').addEventListener('change', refreshList);
    document.getElementById('filter-status').addEventListener('change', refreshList);

    // Quick filter buttons
    document.getElementById('btn-favorites').addEventListener('click', function() {
        favoriteOnly = !favoriteOnly;
        this.classList.toggle('active', favoriteOnly);
        refreshList();
    });

    document.getElementById('btn-this-month').addEventListener('click', function() {
        periodMonth = !periodMonth;
        this.classList.toggle('active', periodMonth);
        refreshList();
    });

    document.getElementById('btn-clear-filters').addEventListener('click', function() {
        searchInput.value = '';
        document.getElementById('filter-vertical').value = '';
        document.getElementById('filter-canvas').value = '';
        document.getElementById('filter-status').value = '';
        favoriteOnly = false;
        periodMonth = false;
        document.getElementById('btn-favorites').classList.remove('active');
        document.getElementById('btn-this-month').classList.remove('active');
        refreshList();
    });
})();
</script>

<?php
};

include __DIR__ . '/../../src/views/layouts/user.php';
