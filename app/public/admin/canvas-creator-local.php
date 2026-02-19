<?php
/**
 * Survey Creator - Modo LOCAL (Desenvolvimento)
 *
 * PROPÓSITO: Criar e editar canvas localmente SEM afetar produção.
 *
 * WORKFLOW:
 * 1. Criar/editar formulário visualmente
 * 2. Exportar JSON (salvo em storage/canvas-exports/)
 * 3. Upload manual para servidor (como draft)
 * 4. Testar no servidor
 * 5. Publicar quando pronto
 *
 * ⚠️ MODO LOCAL: Mudanças NÃO vão direto para produção!
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

// Detectar ambiente LOCAL
$isLocal = (isset($_SERVER['HTTP_HOST']) && (
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false
));

// Configurar BASE_URL local se necessário
if ($isLocal) {
    $localPort = $_SERVER['SERVER_PORT'] ?? 8000;
    define('LOCAL_BASE_URL', 'http://localhost:' . $localPort);
    $baseUrl = LOCAL_BASE_URL;
} else {
    $baseUrl = BASE_URL;
}

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

// Em modo LOCAL, criar sessão de admin temporária se não houver
if ($isLocal) {
    if (!isset($_SESSION['user'])) {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'admin@local.dev',
            'name' => 'Admin Local',
            'access_level' => 'admin'
        ];
        $_SESSION['authenticated'] = true;
    }
} else {
    // Em produção, verificar autenticação normal
    require_login();

    // Verificar se é admin
    if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
        $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
        redirect($baseUrl . '/dashboard.php');
    }
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

// Obter ID do Canvas ou criar novo
$canvasId = $_GET['id'] ?? null;
$isNewCanvas = ($canvasId === 'new');

$canvas = null;

if ($isNewCanvas) {
    // Template vazio para novo canvas
    $canvas = [
        'id' => 'new',
        'slug' => 'novo-canvas-' . date('Ymd-His'),
        'name' => 'Novo Canvas',
        'vertical' => 'juridico',
        'status' => 'draft',
        'is_active' => 0,
        'form_config' => json_encode([
            'logoPosition' => 'right',
            'pages' => [
                [
                    'name' => 'page1',
                    'elements' => [
                        [
                            'type' => 'html',
                            'name' => 'intro',
                            'html' => '<div class="alert alert-info"><strong>Novo Canvas</strong><br>Configure os campos do formulário usando o editor visual.</div>'
                        ]
                    ]
                ]
            ],
            'showQuestionNumbers' => 'off',
            'questionsOnPageMode' => 'singlePage',
            'completeText' => 'Enviar',
            'completedHtml' => '<div class="alert alert-success">Formulário enviado com sucesso!</div>'
        ]),
        'system_prompt' => 'Você é um assistente especializado em [ÁREA].',
        'user_prompt_template' => 'Template de prompt',
        'max_questions' => 20,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
} elseif ($canvasId) {
    // Carregar canvas existente
    $canvas = $db->fetchOne("
        SELECT * FROM canvas_templates WHERE id = :id
    ", ['id' => $canvasId]);

    if (!$canvas) {
        $_SESSION['error'] = 'Canvas não encontrado';
        redirect($baseUrl . '/admin/canvas-templates.php');
    }
} else {
    // Listar templates disponíveis
    $availableTemplates = $db->fetchAll("
        SELECT id, slug, name, status, is_active
        FROM canvas_templates
        ORDER BY name
    ");
}

// Processar ação de EXPORTAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        die(json_encode(['success' => false, 'error' => 'CSRF token inválido']));
    }

    $jsonData = $_POST['json_data'] ?? '';
    $filename = $_POST['filename'] ?? 'canvas-' . date('Y-m-d-His') . '.json';

    // Criar diretório de exports se não existir
    $exportDir = __DIR__ . '/../../storage/canvas-exports';
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }

    $filepath = $exportDir . '/' . $filename;
    file_put_contents($filepath, $jsonData);

    // Retornar caminho do arquivo
    echo json_encode([
        'success' => true,
        'filepath' => $filepath,
        'filename' => $filename
    ]);
    exit;
}

$pageTitle = $isNewCanvas ? 'Novo Canvas (Local)' : 'Editar Canvas (Local): ' . ($canvas['name'] ?? '');

// Include header
include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
    #creatorElement {
        width: 100%;
        height: 800px;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
    }

    .local-mode-banner {
        background: linear-gradient(135deg, #20c997 0%, #17a589 100%);
        color: white;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        border-left: 5px solid #138d75;
    }

    .action-buttons {
        position: sticky;
        top: 20px;
        z-index: 1000;
        background: white;
        padding: 15px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }

    .export-history {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-top: 20px;
    }
</style>

<!-- Banner de Modo Local -->
<div class="local-mode-banner">
    <h4 class="mb-3">🏠 MODO LOCAL - Desenvolvimento Seguro</h4>
    <p class="mb-2"><strong>Ambiente:</strong> WSL (localhost:8000)</p>
    <p class="mb-0">
        ✅ Mudanças ficam APENAS no seu computador<br>
        ✅ Exportar JSON para enviar ao servidor (draft)<br>
        ✅ NUNCA afeta produção diretamente
    </p>
</div>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/">Admin</a></li>
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/canvas-templates.php">Canvas Templates</a></li>
        <li class="breadcrumb-item active">🏠 Modo Local</li>
    </ol>
</nav>

<?php if (!$canvas): ?>
<!-- Seleção de Template -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Selecione um Template ou Crie Novo</h5>
    </div>
    <div class="card-body">
        <div class="d-flex gap-3 mb-4">
            <a href="?id=new" class="btn btn-success btn-lg">
                <i class="bi bi-plus-circle"></i> Criar Novo Canvas
            </a>
        </div>

        <h6>Templates Existentes:</h6>
        <div class="list-group">
            <?php foreach ($availableTemplates as $tpl): ?>
            <a href="?id=<?= $tpl['id'] ?>" class="list-group-item list-group-item-action">
                <div class="d-flex w-100 justify-content-between">
                    <h6 class="mb-1"><?= sanitize_output($tpl['name']) ?></h6>
                    <small>
                        <span class="badge bg-info"><?= ucfirst($tpl['vertical']) ?></span>
                        <?php if ($tpl['status'] === 'draft'): ?>
                        <span class="badge bg-warning text-dark">Draft</span>
                        <?php elseif ($tpl['status'] === 'published'): ?>
                        <span class="badge bg-success">Published</span>
                        <?php endif; ?>
                    </small>
                </div>
                <small class="text-muted"><?= $tpl['slug'] ?></small>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php else: ?>

<h1 class="mb-3"><?= sanitize_output($canvas['name']) ?></h1>

<!-- Informações do Canvas -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Informações</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <p><strong>Slug:</strong> <code><?= sanitize_output($canvas['slug']) ?></code></p>
                <p><strong>Vertical:</strong> <span class="badge bg-primary"><?= ucfirst($canvas['vertical']) ?></span></p>
                <?php if (!$isNewCanvas): ?>
                <p><strong>Status no servidor:</strong>
                    <?php
                    $statusBadge = match($canvas['status'] ?? 'draft') {
                        'published' => 'bg-success',
                        'draft' => 'bg-warning text-dark',
                        'archived' => 'bg-secondary',
                        default => 'bg-info'
                    };
                    ?>
                    <span class="badge <?= $statusBadge ?>"><?= strtoupper($canvas['status'] ?? 'draft') ?></span>
                </p>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <?php if (!$isNewCanvas): ?>
                <p><strong>Última atualização (servidor):</strong> <?= date('d/m/Y H:i', strtotime($canvas['updated_at'])) ?></p>
                <?php endif; ?>
                <p><strong>Modo:</strong> <span class="badge bg-success">LOCAL</span></p>
            </div>
        </div>
    </div>
</div>

<!-- Botões de Ação (Sticky) -->
<div class="action-buttons">
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-success" id="exportJsonBtn">
            <i class="bi bi-download"></i> Exportar JSON (Local)
        </button>
        <button type="button" class="btn btn-primary" id="copyJsonBtn">
            <i class="bi bi-clipboard"></i> Copiar JSON
        </button>
        <button type="button" class="btn btn-info" id="viewJsonBtn">
            <i class="bi bi-code"></i> Ver JSON
        </button>
        <a href="canvas-creator-local.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Voltar
        </a>
    </div>
    <div id="actionStatus" class="mt-2"></div>
</div>

<!-- Survey Creator -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0">Survey Creator - Editor Visual</h5>
    </div>
    <div class="card-body p-0">
        <div id="creatorElement"></div>
    </div>
</div>

<!-- Histórico de Exports -->
<div class="export-history">
    <h5>📦 Exports Recentes (Local)</h5>
    <p class="text-muted">Arquivos salvos em: <code>storage/canvas-exports/</code></p>
    <div id="exportList">
        <em>Nenhum export nesta sessão ainda.</em>
    </div>
</div>

<?php endif; ?>

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

<?php if ($canvas): ?>
<script>
    console.log('🏠 Survey Creator - Modo LOCAL inicializando...');

    // Carregar JSON
    const surveyJson = <?= json_encode(json_decode($canvas['form_config']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Criar Creator
    const creator = new SurveyCreator.SurveyCreator({
        showLogicTab: true,
        showTranslationTab: true,
        showThemeTab: true,
        showJSONEditorTab: true,
        showDesignerTab: true,
        showPreviewTab: true,
        isAutoSave: false  // Não auto-salvar (modo local)
    });

    creator.JSON = surveyJson;
    creator.render("creatorElement");

    console.log('✅ Survey Creator carregado (modo local)');

    // Array para histórico de exports
    let exportHistory = [];

    // Exportar JSON (salvar localmente)
    document.getElementById('exportJsonBtn').addEventListener('click', async () => {
        const surveyJson = creator.JSON;
        const jsonString = JSON.stringify(surveyJson, null, 2);
        const filename = 'canvas-<?= $canvas['slug'] ?>-' + new Date().toISOString().replace(/[:.]/g, '-') + '.json';

        // Enviar para servidor via AJAX (salvar em storage/canvas-exports/)
        try {
            const formData = new FormData();
            formData.append('action', 'export');
            formData.append('json_data', jsonString);
            formData.append('filename', filename);
            formData.append('csrf_token', '<?= csrf_token() ?>');

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // Adicionar ao histórico
                exportHistory.push({
                    filename: result.filename,
                    filepath: result.filepath,
                    timestamp: new Date().toLocaleString('pt-BR')
                });

                // Atualizar lista
                updateExportList();

                // Também fazer download no navegador
                const blob = new Blob([jsonString], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.click();

                showStatus('✅ JSON exportado com sucesso!<br><small>Arquivo: ' + result.filename + '</small>', 'success');
            } else {
                showStatus('❌ Erro ao exportar: ' + result.error, 'danger');
            }
        } catch (error) {
            console.error('Erro:', error);
            showStatus('❌ Erro ao exportar JSON', 'danger');
        }
    });

    // Copiar JSON para clipboard
    document.getElementById('copyJsonBtn').addEventListener('click', () => {
        const surveyJson = creator.JSON;
        const jsonString = JSON.stringify(surveyJson, null, 2);

        navigator.clipboard.writeText(jsonString).then(() => {
            showStatus('✅ JSON copiado para a área de transferência!', 'success');
        }).catch(() => {
            showStatus('❌ Erro ao copiar JSON', 'danger');
        });
    });

    // Ver JSON
    document.getElementById('viewJsonBtn').addEventListener('click', () => {
        creator.activeTab = 'test';  // Mudar para tab JSON Editor
    });

    // Função auxiliar para mostrar status
    function showStatus(message, type) {
        const statusDiv = document.getElementById('actionStatus');
        statusDiv.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show mt-2">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;

        setTimeout(() => {
            statusDiv.innerHTML = '';
        }, 5000);
    }

    // Atualizar lista de exports
    function updateExportList() {
        const listDiv = document.getElementById('exportList');

        if (exportHistory.length === 0) {
            listDiv.innerHTML = '<em>Nenhum export nesta sessão ainda.</em>';
            return;
        }

        let html = '<ul class="list-group">';
        exportHistory.reverse().forEach((exp, idx) => {
            html += `
                <li class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <div>
                            <strong>${exp.filename}</strong><br>
                            <small class="text-muted">${exp.timestamp}</small>
                        </div>
                        <div>
                            <span class="badge bg-success">Exportado</span>
                        </div>
                    </div>
                    <small class="text-muted">${exp.filepath}</small>
                </li>
            `;
        });
        html += '</ul>';

        listDiv.innerHTML = html;
    }

    // Alertar se sair sem exportar mudanças
    let hasChanges = false;
    creator.onModified.add(() => {
        hasChanges = true;
    });

    document.getElementById('exportJsonBtn').addEventListener('click', () => {
        hasChanges = false;
    });

    window.addEventListener('beforeunload', (e) => {
        if (hasChanges) {
            e.preventDefault();
            e.returnValue = 'Você tem mudanças não exportadas. Deseja realmente sair?';
        }
    });
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
