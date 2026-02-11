<?php
/**
 * Prompt Dictionary
 *
 * @package Sunyata
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

use Sunyata\Auth\GoogleAuth;
use Sunyata\Core\Database;

$auth = new GoogleAuth();
$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Get filters
$vertical = $_GET['vertical'] ?? '';
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$sql = "SELECT * FROM prompt_dictionary WHERE 1=1";
$params = [];

if ($vertical && $vertical !== 'all') {
    $sql .= " AND vertical = :vertical";
    $params['vertical'] = $vertical;
}

if ($category) {
    $sql .= " AND category = :category";
    $params['category'] = $category;
}

if ($search) {
    $sql .= " AND (title LIKE :search OR description LIKE :search2 OR prompt_text LIKE :search3)";
    $params['search'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search3'] = '%' . $search . '%';
}

// Access level filter
$accessLevels = ['free'];
if ($currentUser['access_level'] === 'student' || $currentUser['access_level'] === 'client' || $currentUser['access_level'] === 'admin') {
    $accessLevels[] = 'student';
}
if ($currentUser['access_level'] === 'client' || $currentUser['access_level'] === 'admin') {
    $accessLevels[] = 'client';
    $accessLevels[] = 'premium';
}
if ($currentUser['access_level'] === 'admin') {
    $accessLevels[] = 'premium';
}

$placeholders = implode(',', array_fill(0, count($accessLevels), '?'));
$sql .= " AND access_level IN ($placeholders)";

$sql .= " ORDER BY created_at DESC";

$stmt = $db->getConnection()->prepare($sql);
$i = 1;
foreach ($params as $value) {
    $stmt->bindValue($i++, $value);
}
foreach ($accessLevels as $level) {
    $stmt->bindValue($i++, $level);
}
$stmt->execute();
$prompts = $stmt->fetchAll();

// Get unique categories
$categoriesStmt = $db->getConnection()->query("SELECT DISTINCT category FROM prompt_dictionary ORDER BY category");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Dicionário de Prompts';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../src/views/navbar.php'; ?>

    <div class="container my-5">
        <div class="row mb-4">
            <div class="col">
                <h1 class="mb-3"><?= $pageTitle ?></h1>
                <p class="text-muted">Templates de prompts prontos para uso em IA generativa</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="vertical" class="form-label">Vertical</label>
                            <select name="vertical" id="vertical" class="form-select" onchange="this.form.submit()">
                                <option value="all" <?= $vertical === 'all' ? 'selected' : '' ?>>Todas</option>
                                <?php foreach (VERTICALS as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= $vertical === $key ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="category" class="form-label">Categoria</label>
                            <select name="category" id="category" class="form-select" onchange="this.form.submit()">
                                <option value="">Todas</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= sanitize_output($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                        <?= sanitize_output(ucfirst($cat)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="search" class="form-label">Buscar</label>
                            <div class="input-group">
                                <input type="text" name="search" id="search" class="form-control"
                                       placeholder="Palavras-chave..." value="<?= sanitize_output($search) ?>">
                                <button class="btn btn-primary" type="submit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16">
                                        <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/>
                                    </svg>
                                </button>
                                <?php if ($search || $vertical || $category): ?>
                                    <a href="<?= BASE_URL ?>/dicionario.php" class="btn btn-outline-secondary">Limpar</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Prompts Grid -->
        <?php if (empty($prompts)): ?>
            <div class="alert alert-info">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-info-circle me-2" viewBox="0 0 16 16">
                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
                    <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533z"/>
                    <path d="M9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>
                </svg>
                Nenhum prompt encontrado com os filtros selecionados.
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($prompts as $prompt): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 prompt-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="card-title"><?= sanitize_output($prompt['title']) ?></h5>
                                    <span class="badge bg-primary"><?= sanitize_output(VERTICALS[$prompt['vertical']]) ?></span>
                                </div>

                                <p class="card-text text-muted small mb-3"><?= sanitize_output($prompt['description']) ?></p>

                                <div class="mb-3">
                                    <small class="text-muted">Categoria:</small>
                                    <span class="badge bg-secondary"><?= sanitize_output($prompt['category']) ?></span>
                                    <span class="badge bg-info"><?= sanitize_output($prompt['access_level']) ?></span>
                                </div>

                                <button class="btn btn-sm btn-outline-primary w-100" type="button"
                                        data-bs-toggle="modal" data-bs-target="#promptModal<?= $prompt['id'] ?>">
                                    Ver Prompt Completo
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Modal for each prompt -->
                    <div class="modal fade" id="promptModal<?= $prompt['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><?= sanitize_output($prompt['title']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <span class="badge bg-primary me-2"><?= sanitize_output(VERTICALS[$prompt['vertical']]) ?></span>
                                        <span class="badge bg-secondary"><?= sanitize_output($prompt['category']) ?></span>
                                    </div>

                                    <h6>Descrição:</h6>
                                    <p><?= sanitize_output($prompt['description']) ?></p>

                                    <h6>Prompt:</h6>
                                    <div class="bg-light p-3 rounded position-relative">
                                        <pre class="mb-0" id="prompt-text-<?= $prompt['id'] ?>" style="white-space: pre-wrap; font-family: monospace;"><?= sanitize_output($prompt['prompt_text']) ?></pre>
                                        <button class="btn btn-sm btn-primary position-absolute top-0 end-0 m-2"
                                                onclick="copyPrompt(<?= $prompt['id'] ?>)">
                                            Copiar
                                        </button>
                                    </div>

                                    <?php if ($prompt['use_cases']): ?>
                                        <h6 class="mt-3">Casos de Uso:</h6>
                                        <p><?= sanitize_output($prompt['use_cases']) ?></p>
                                    <?php endif; ?>

                                    <?php
                                    $consentManager = new Sunyata\Compliance\ConsentManager();
                                    $disclaimer = $consentManager->getConsentText('terms_of_use', $prompt['vertical']);
                                    if (strpos($disclaimer, 'AVISO IMPORTANTE') !== false):
                                    ?>
                                        <div class="alert alert-warning mt-3">
                                            <strong>⚠️ Aviso Legal:</strong>
                                            <p class="mb-0 small"><?= nl2br(sanitize_output(substr($disclaimer, strpos($disclaimer, 'AVISO IMPORTANTE')))) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 text-muted text-center">
                <small>Total de <?= count($prompts) ?> prompt(s) encontrado(s)</small>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
