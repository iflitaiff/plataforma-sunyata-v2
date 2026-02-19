<?php
/**
 * Canvas Result Viewer — displays a saved submission result.
 *
 * Usage: /canvas/result.php?id=submission_id
 * Shows the rendered markdown result with metadata.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

use Sunyata\Core\Database;

$submissionId = (int)($_GET['id'] ?? 0);
if (!$submissionId) {
    $_SESSION['error'] = 'Submissao nao especificada.';
    redirect(BASE_URL . '/meu-trabalho/');
}

$db = Database::getInstance();
$submission = $db->fetchOne("
    SELECT us.*, ct.name as canvas_name, ct.slug as canvas_slug
    FROM user_submissions us
    JOIN canvas_templates ct ON ct.id = us.canvas_template_id
    WHERE us.id = :id AND us.user_id = :user_id
", ['id' => $submissionId, 'user_id' => $_SESSION['user_id']]);

if (!$submission) {
    $_SESSION['error'] = 'Submissao nao encontrada.';
    redirect(BASE_URL . '/meu-trabalho/');
}

$pageTitle = $submission['title'] ?: $submission['canvas_name'];
$activeNav = 'meu-trabalho';

$pageContent = function () use ($submission) {
?>

<?php
$pageHeaderTitle = htmlspecialchars($submission['title'] ?: $submission['canvas_name']);
$pageHeaderPretitle = htmlspecialchars($submission['vertical']) . ' / ' . htmlspecialchars($submission['canvas_name']);
$pageHeaderActions = '
    <a href="' . BASE_URL . '/canvas/form.php?id=' . $submission['canvas_template_id'] . '" class="btn btn-primary btn-sm">
        <i class="ti ti-refresh me-1"></i> Nova Submissao
    </a>
    <a href="' . BASE_URL . '/meu-trabalho/" class="btn btn-outline-secondary btn-sm">
        <i class="ti ti-arrow-left me-1"></i> Voltar
    </a>
';
include __DIR__ . '/../../src/views/components/page-header.php';
?>

<div class="row g-4">
    <div class="col-lg-8">
        <!-- Result -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Resultado</h3>
                <div class="btn-list">
                    <button class="btn btn-sm btn-ghost-primary" onclick="copyResult()">
                        <i class="ti ti-copy me-1"></i> Copiar
                    </button>
                    <button class="btn btn-sm btn-ghost-primary" onclick="window.print()">
                        <i class="ti ti-printer me-1"></i> Imprimir
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="result-content" class="stream-result">
                    <?= $submission['result_markdown'] ? '' : '<p class="text-secondary">Sem resultado (submissao em andamento ou com erro).</p>' ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Metadata -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Detalhes</h3>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Canvas</dt>
                    <dd class="col-7"><?= htmlspecialchars($submission['canvas_name']) ?></dd>

                    <dt class="col-5">Vertical</dt>
                    <dd class="col-7"><?= htmlspecialchars($submission['vertical']) ?></dd>

                    <dt class="col-5">Status</dt>
                    <dd class="col-7">
                        <span class="badge bg-<?= $submission['status'] === 'completed' ? 'success' : ($submission['status'] === 'error' ? 'danger' : 'warning') ?>">
                            <?= htmlspecialchars($submission['status']) ?>
                        </span>
                    </dd>

                    <dt class="col-5">Criado</dt>
                    <dd class="col-7"><?= date('d/m/Y H:i', strtotime($submission['created_at'])) ?></dd>

                    <?php
                    $metadata = $submission['result_metadata'];
                    if (is_string($metadata)) $metadata = json_decode($metadata, true);
                    if ($metadata):
                    ?>
                        <?php if (!empty($metadata['tokens_total'])): ?>
                        <dt class="col-5">Tokens</dt>
                        <dd class="col-7"><?= number_format($metadata['tokens_total']) ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($metadata['cost_usd'])): ?>
                        <dt class="col-5">Custo</dt>
                        <dd class="col-7">$<?= number_format($metadata['cost_usd'], 4) ?></dd>
                        <?php endif; ?>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Version History -->
        <?php if (!empty($submission['parent_id'])): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Historico de Versoes</h3>
            </div>
            <div class="card-body p-0"
                 hx-get="<?= BASE_URL ?>/api/submissions/list.php?parent_id=<?= $submission['parent_id'] ?>"
                 hx-trigger="load"
                 hx-target="this"
                 hx-swap="innerHTML">
                <div class="text-center p-3 text-secondary">Carregando...</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Render markdown
document.addEventListener('DOMContentLoaded', function() {
    const resultMd = <?= json_encode($submission['result_markdown'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
    if (resultMd && typeof marked !== 'undefined') {
        document.getElementById('result-content').innerHTML = marked.parse(resultMd);
    }
});

function copyResult() {
    const text = <?= json_encode($submission['result_markdown'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
    navigator.clipboard.writeText(text).then(() => {
        alert('Copiado para a area de transferencia!');
    });
}
</script>

<?php
};

include __DIR__ . '/../../src/views/layouts/user.php';
