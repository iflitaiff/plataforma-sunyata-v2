<?php
/**
 * Admin: Canvas Editor - Survey Creator
 * Editor visual para Canvas Templates usando SurveyJS Creator
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

// Stats para admin-header.php
$stats = [];
try {
    $result = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM vertical_access_requests
        WHERE status = 'pending'
    ");
    $stats['pending_requests'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['pending_requests'] = 0;
}

// Processar salvamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'CSRF token inválido']);
        exit;
    }

    if ($_POST['action'] === 'save') {
        $canvasId = $_POST['canvas_id'] ?? null;
        $formConfig = $_POST['form_config'] ?? '';

        // Validar JSON
        $jsonData = json_decode($formConfig);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'error' => 'JSON inválido']);
            exit;
        }

        try {
            if ($canvasId && $canvasId !== 'new') {
                // Atualizar existente
                $db->update('canvas_templates', [
                    'form_config' => $formConfig,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = :id', ['id' => $canvasId]);

                echo json_encode(['success' => true, 'message' => 'Canvas atualizado com sucesso!', 'id' => $canvasId]);
            } else {
                // Criar novo (se necessário no futuro)
                echo json_encode(['success' => false, 'error' => 'Criação de novos canvas não implementada ainda']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'export') {
        $formConfig = $_POST['form_config'] ?? '';
        $filename = $_POST['filename'] ?? 'canvas-export-' . date('Y-m-d-His') . '.json';

        // Criar diretório se não existir
        $exportDir = __DIR__ . '/../../storage/canvas-exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filepath = $exportDir . '/' . $filename;
        file_put_contents($filepath, $formConfig);

        echo json_encode(['success' => true, 'filepath' => $filepath, 'filename' => $filename]);
        exit;
    }
}

// Obter canvas
$canvasId = $_GET['id'] ?? null;
$canvas = null;

if ($canvasId && $canvasId !== 'new') {
    $canvas = $db->fetchOne("
        SELECT * FROM canvas_templates WHERE id = :id
    ", ['id' => $canvasId]);

    if (!$canvas) {
        $_SESSION['error'] = 'Canvas não encontrado';
        redirect(BASE_URL . '/admin/canvas-templates.php');
    }
} else {
    // Lista de templates para seleção
    $availableTemplates = $db->fetchAll("
        SELECT id, slug, name, is_active
        FROM canvas_templates
        ORDER BY name
    ");
}

$pageTitle = $canvas ? 'Editor Visual: ' . $canvas['name'] : 'Survey Creator';

include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
    #creatorElement {
        width: 100%;
        height: calc(100vh - 300px);
        min-height: 600px;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
    }

    .action-bar {
        position: sticky;
        top: 20px;
        z-index: 1000;
        background: white;
        padding: 15px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }

    .canvas-info {
        background: #f8f9fa;
        border-left: 4px solid #0d6efd;
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 20px;
    }
</style>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/">Admin</a></li>
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/canvas-templates.php">Canvas Templates</a></li>
        <li class="breadcrumb-item active">Survey Creator</li>
    </ol>
</nav>

<?php if (!$canvas): ?>
<!-- Seleção de Template -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-palette"></i> Survey Creator - Editor Visual</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">Selecione um Canvas Template para editar visualmente com o SurveyJS Creator.</p>

        <h6 class="mt-4">Templates Disponíveis:</h6>
        <div class="list-group">
            <?php foreach ($availableTemplates as $tpl): ?>
            <a href="?id=<?= $tpl['id'] ?>" class="list-group-item list-group-item-action">
                <div class="d-flex w-100 justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1"><?= sanitize_output($tpl['name']) ?></h6>
                        <small class="text-muted"><?= $tpl['slug'] ?></small>
                    </div>
                    <div>
                        <span class="badge bg-info"><?= ucfirst($tpl['vertical']) ?></span>
                        <?php if ($tpl['is_active']): ?>
                        <span class="badge bg-success">Ativo</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Inativo</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php else: ?>

<h1 class="mb-3"><i class="bi bi-palette"></i> <?= sanitize_output($canvas['name']) ?></h1>

<!-- Info do Canvas -->
<div class="canvas-info">
    <div class="row">
        <div class="col-md-6">
            <strong>Slug:</strong> <code><?= sanitize_output($canvas['slug']) ?></code><br>
            <strong>Vertical:</strong> <span class="badge bg-primary"><?= ucfirst($canvas['vertical']) ?></span>
            <span class="badge <?= $canvas['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                <?= $canvas['is_active'] ? 'ATIVO' : 'INATIVO' ?>
            </span>
        </div>
        <div class="col-md-6">
            <strong>Última atualização:</strong> <?= date('d/m/Y H:i', strtotime($canvas['updated_at'])) ?>
        </div>
    </div>
</div>

<!-- Barra de Ação -->
<div class="action-bar">
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <button type="button" class="btn btn-success" id="saveBtn">
            <i class="bi bi-check-circle"></i> Salvar no Banco
        </button>
        <button type="button" class="btn btn-primary" id="exportBtn">
            <i class="bi bi-download"></i> Exportar JSON
        </button>
        <button type="button" class="btn btn-info" id="copyBtn">
            <i class="bi bi-clipboard"></i> Copiar JSON
        </button>
        <a href="<?= BASE_URL ?>/admin/canvas-templates.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Voltar
        </a>
        <div id="statusMsg" class="ms-auto"></div>
    </div>
</div>

<!-- Survey Creator -->
<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0">Editor Visual</h5>
    </div>
    <div class="card-body p-0">
        <div id="creatorElement"></div>
    </div>
</div>

<!-- SurveyJS Core (v2.4.1 - latest stable) -->
<link href="https://unpkg.com/survey-core@2.4.1/survey-core.min.css" rel="stylesheet">
<script src="https://unpkg.com/survey-core@2.4.1/survey.core.min.js"></script>
<script src="https://unpkg.com/survey-js-ui@2.4.1/survey-js-ui.min.js"></script>

<!-- SurveyJS Creator (v2.4.1 - latest stable) -->
<link href="https://unpkg.com/survey-creator-core@2.4.1/survey-creator-core.min.css" rel="stylesheet">
<script src="https://unpkg.com/survey-creator-core@2.4.1/survey-creator-core.min.js"></script>
<link href="https://unpkg.com/survey-creator-js@2.4.1/survey-creator-js.min.css" rel="stylesheet">
<script src="https://unpkg.com/survey-creator-js@2.4.1/survey-creator-js.min.js"></script>

<!-- SurveyJS License -->
<script src="<?= BASE_URL ?>/assets/js/surveyjs-license.js"></script>

<script>
console.log('🎨 Survey Creator inicializando...');

// Carregar JSON do Canvas
const surveyJson = <?= json_encode(json_decode($canvas['form_config']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Criar Creator
const creator = new SurveyCreator.SurveyCreator({
    showLogicTab: true,
    showTranslationTab: true,
    showThemeTab: true,
    showJSONEditorTab: true,
    showDesignerTab: true,
    showPreviewTab: true,
    isAutoSave: false
});

creator.JSON = surveyJson;
creator.render("creatorElement");

console.log('✅ Survey Creator carregado');

// Rastrear mudanças
let hasUnsavedChanges = false;
creator.onModified.add(() => {
    hasUnsavedChanges = true;
});

// Salvar no banco
document.getElementById('saveBtn').addEventListener('click', async () => {
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Salvando...';

    try {
        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('canvas_id', '<?= $canvas['id'] ?>');
        formData.append('form_config', JSON.stringify(creator.JSON, null, 2));
        formData.append('csrf_token', '<?= csrf_token() ?>');

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            showStatus('✅ Canvas salvo com sucesso!', 'success');
            hasUnsavedChanges = false;
        } else {
            showStatus('❌ Erro: ' + result.error, 'danger');
        }
    } catch (error) {
        console.error('Erro:', error);
        showStatus('❌ Erro ao salvar', 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Salvar no Banco';
    }
});

// Exportar JSON
document.getElementById('exportBtn').addEventListener('click', async () => {
    const jsonString = JSON.stringify(creator.JSON, null, 2);
    const filename = 'canvas-<?= $canvas['slug'] ?>-' + new Date().toISOString().slice(0,19).replace(/[:.]/g, '-') + '.json';

    // Download no navegador
    const blob = new Blob([jsonString], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();

    // Salvar também no servidor
    try {
        const formData = new FormData();
        formData.append('action', 'export');
        formData.append('form_config', jsonString);
        formData.append('filename', filename);
        formData.append('csrf_token', '<?= csrf_token() ?>');

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            showStatus('✅ JSON exportado: ' + result.filename, 'success');
        }
    } catch (error) {
        console.warn('Erro ao salvar no servidor:', error);
    }
});

// Copiar JSON
document.getElementById('copyBtn').addEventListener('click', () => {
    const jsonString = JSON.stringify(creator.JSON, null, 2);
    navigator.clipboard.writeText(jsonString).then(() => {
        showStatus('✅ JSON copiado!', 'success');
    }).catch(() => {
        showStatus('❌ Erro ao copiar', 'danger');
    });
});

// Mostrar status
function showStatus(message, type) {
    const statusDiv = document.getElementById('statusMsg');
    statusDiv.innerHTML = `<div class="alert alert-${type} py-2 px-3 mb-0">${message}</div>`;
    setTimeout(() => {
        statusDiv.innerHTML = '';
    }, 5000);
}

// Alertar ao sair com mudanças não salvas
window.addEventListener('beforeunload', (e) => {
    if (hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = 'Você tem mudanças não salvas. Deseja realmente sair?';
    }
});
</script>

<?php endif; ?>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
