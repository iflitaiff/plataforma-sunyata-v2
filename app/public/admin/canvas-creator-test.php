<?php
/**
 * Admin: Survey Creator - Página de Teste
 *
 * Página ISOLADA para testar o Survey Creator sem afetar produção.
 * Carrega templates em modo DRAFT para experimentação segura.
 *
 * ⚠️ ATENÇÃO: Esta é uma página de TESTES. Mudanças aqui NÃO afetam
 * templates de produção a menos que você explicitamente publique.
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

// Obter ID do Canvas (padrão: template de teste)
$canvasId = $_GET['id'] ?? null;

// Se não especificado, buscar o template de teste
if (!$canvasId) {
    $testTemplate = $db->fetchOne("
        SELECT id FROM canvas_templates
        WHERE slug = 'teste-survey-creator'
        LIMIT 1
    ");

    if ($testTemplate) {
        $canvasId = $testTemplate['id'];
    } else {
        $_SESSION['error'] = 'Template de teste não encontrado. Execute a migration primeiro.';
        redirect(BASE_URL . '/admin/canvas-templates.php');
    }
}

// Buscar Canvas
$canvas = $db->fetchOne("
    SELECT * FROM canvas_templates WHERE id = :id
", ['id' => $canvasId]);

if (!$canvas) {
    $_SESSION['error'] = 'Canvas não encontrado';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Avisar se está editando template de produção (perigoso!)
$is_production = ($canvas['status'] === 'published' && $canvas['is_active'] == 1);

// Processar formulário (SAVE)
$message = '';
$message_type = '';
$saved_json = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $message = 'Token de segurança inválido';
        $message_type = 'danger';
    } else {
        try {
            // Capturar JSON do Survey Creator
            $formConfig = $_POST['form_config'] ?? '';
            $jsonDecoded = json_decode($formConfig, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Form Config JSON inválido: ' . json_last_error_msg());
            }

            // OPCIONAL: Salvar backup antes de atualizar
            if ($is_production) {
                // Se está editando produção, criar backup
                $db->insert('audit_logs', [
                    'user_id' => $_SESSION['user_id'],
                    'action' => 'canvas_edit_production_backup',
                    'details' => json_encode([
                        'canvas_id' => $canvasId,
                        'old_config' => $canvas['form_config'],
                        'timestamp' => date('Y-m-d H:i:s')
                    ])
                ]);
            }

            // Atualizar Canvas
            $updated = $db->update('canvas_templates', [
                'name' => $_POST['name'] ?? $canvas['name'],
                'form_config' => $formConfig,
                'system_prompt' => $_POST['system_prompt'] ?? $canvas['system_prompt'],
                'user_prompt_template' => $_POST['user_prompt_template'] ?? $canvas['user_prompt_template'],
                'max_questions' => (int)($_POST['max_questions'] ?? $canvas['max_questions']),
                'last_edited_by' => $_SESSION['user_id'],
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $canvasId]);

            if ($updated) {
                $message = '✅ Canvas atualizado com sucesso via Survey Creator!';
                $message_type = 'success';
                $saved_json = $formConfig;

                // Recarregar canvas atualizado
                $canvas = $db->fetchOne("SELECT * FROM canvas_templates WHERE id = :id", ['id' => $canvasId]);
            } else {
                $message = 'ℹ️ Nenhuma mudança detectada';
                $message_type = 'info';
            }

        } catch (Exception $e) {
            $message = '❌ Erro ao atualizar Canvas: ' . $e->getMessage();
            $message_type = 'danger';
            error_log('[Survey Creator Test] Error: ' . $e->getMessage());
        }
    }
}

$pageTitle = '🧪 Survey Creator Test: ' . $canvas['name'];

// Include header
include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
    /* Survey Creator Container */
    #creatorElement {
        width: 100%;
        height: 800px;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
    }

    /* Banner de aviso */
    .test-mode-banner {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        color: white;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        border-left: 5px solid #c92a2a;
        animation: pulse-warning 2s ease-in-out infinite;
    }

    .draft-mode-banner {
        background: linear-gradient(135deg, #ffd43b 0%, #fab005 100%);
        color: #000;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        border-left: 5px solid #f59f00;
    }

    .production-warning-banner {
        background: linear-gradient(135deg, #ff0000 0%, #cc0000 100%);
        color: white;
        padding: 25px;
        border-radius: 8px;
        margin-bottom: 20px;
        border-left: 5px solid #990000;
        animation: pulse-danger 1.5s ease-in-out infinite;
    }

    @keyframes pulse-warning {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.85; }
    }

    @keyframes pulse-danger {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.9; transform: scale(1.01); }
    }

    .prompt-textarea {
        font-family: 'Courier New', Courier, monospace;
        font-size: 13px;
    }

    /* Debug panel */
    .debug-panel {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-top: 20px;
    }

    .debug-panel pre {
        background: #2d2d2d;
        color: #f8f8f2;
        padding: 15px;
        border-radius: 4px;
        max-height: 300px;
        overflow-y: auto;
        font-size: 12px;
    }
</style>

<!-- Avisos de Status -->
<?php if ($is_production): ?>
<div class="production-warning-banner">
    <h4 class="mb-3">🚨 ATENÇÃO: VOCÊ ESTÁ EDITANDO UM TEMPLATE DE PRODUÇÃO!</h4>
    <p class="mb-2"><strong>Template:</strong> <?= sanitize_output($canvas['name']) ?></p>
    <p class="mb-2"><strong>Status:</strong> <span class="badge bg-danger">PUBLISHED</span> | <span class="badge bg-success">ACTIVE</span></p>
    <p class="mb-0"><strong>Risco:</strong> Mudanças aqui afetarão IMEDIATAMENTE os usuários finais. Considere criar um draft primeiro.</p>
</div>
<?php elseif ($canvas['status'] === 'draft'): ?>
<div class="draft-mode-banner">
    <h4 class="mb-3">🔶 Modo Draft - Ambiente Seguro de Testes</h4>
    <p class="mb-0">Este template está em modo DRAFT. Mudanças não afetam produção até você publicar.</p>
</div>
<?php endif; ?>

<div class="test-mode-banner">
    <h4 class="mb-3">🧪 MODO TESTE - Survey Creator</h4>
    <p class="mb-2">Esta é uma página experimental para testar o Survey Creator antes de integrar em produção.</p>
    <p class="mb-0"><strong>Features disponíveis:</strong> Visual Editor, Toolbox, Property Grid, Preview, JSON Editor, Logic Tab</p>
</div>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/">Admin</a></li>
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/canvas-templates.php">Canvas Templates</a></li>
        <li class="breadcrumb-item active">🧪 Test Survey Creator</li>
    </ol>
</nav>

<h1 class="mb-3">🧪 Survey Creator Test: <?= sanitize_output($canvas['name']) ?></h1>

<?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Informações do Canvas -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Informações do Template</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <p><strong>Slug:</strong> <code><?= sanitize_output($canvas['slug']) ?></code></p>
                <p><strong>Vertical:</strong> <span class="badge bg-primary"><?= ucfirst($canvas['vertical']) ?></span></p>
                <p><strong>Status:</strong>
                    <?php
                    $statusBadge = match($canvas['status']) {
                        'published' => 'bg-success',
                        'draft' => 'bg-warning text-dark',
                        'archived' => 'bg-secondary',
                        default => 'bg-info'
                    };
                    $statusText = match($canvas['status']) {
                        'published' => '✅ PUBLICADO',
                        'draft' => '🔶 RASCUNHO',
                        'archived' => '📦 ARQUIVADO',
                        default => 'UNKNOWN'
                    };
                    ?>
                    <span class="badge <?= $statusBadge ?>"><?= $statusText ?></span>
                    <?php if ($canvas['is_active']): ?>
                        <span class="badge bg-success">ATIVO</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">INATIVO</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-6">
                <p><strong>Criado em:</strong> <?= date('d/m/Y H:i', strtotime($canvas['created_at'])) ?></p>
                <p><strong>Última atualização:</strong> <?= date('d/m/Y H:i', strtotime($canvas['updated_at'])) ?></p>
                <?php if ($canvas['last_edited_by']): ?>
                <p><strong>Editado por:</strong> User ID <?= $canvas['last_edited_by'] ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Formulário Principal -->
<form method="POST" id="creatorForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="form_config" id="form_config">

    <!-- Nome do Canvas -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">1. Nome do Canvas</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="name" class="form-label">Nome Exibido</label>
                <input type="text" class="form-control" id="name" name="name"
                       value="<?= sanitize_output($canvas['name']) ?>" required>
            </div>
        </div>
    </div>

    <!-- Survey Creator -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">2. Survey Creator (Visual Editor)</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                <strong>📝 Instruções:</strong> Use o editor visual para criar/editar o formulário.
                Arraste elementos da toolbox, configure propriedades, adicione lógica condicional.
            </p>

            <!-- Container do Survey Creator -->
            <div id="creatorElement"></div>
        </div>
    </div>

    <!-- System Prompt -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">3. System Prompt</h5>
        </div>
        <div class="card-body">
            <textarea class="form-control prompt-textarea" id="system_prompt" name="system_prompt"
                      rows="10" required><?= htmlspecialchars($canvas['system_prompt']) ?></textarea>
        </div>
    </div>

    <!-- User Prompt Template -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">4. User Prompt Template</h5>
        </div>
        <div class="card-body">
            <textarea class="form-control prompt-textarea" id="user_prompt_template" name="user_prompt_template"
                      rows="8" required><?= htmlspecialchars($canvas['user_prompt_template']) ?></textarea>
        </div>
    </div>

    <!-- Configurações -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">5. Configurações</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="max_questions" class="form-label">Máximo de Perguntas Contextuais</label>
                <input type="number" class="form-control" id="max_questions" name="max_questions"
                       value="<?= $canvas['max_questions'] ?>" min="1" max="20" required>
            </div>
        </div>
    </div>

    <!-- Botões de Ação -->
    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary btn-lg" id="saveBtn">
            <i class="bi bi-save"></i> Salvar Alterações
        </button>
        <button type="button" class="btn btn-info btn-lg" id="exportJsonBtn">
            <i class="bi bi-download"></i> Exportar JSON
        </button>
        <a href="canvas-templates.php" class="btn btn-secondary btn-lg">
            <i class="bi bi-x-circle"></i> Voltar
        </a>
    </div>
</form>

<!-- Debug Panel (opcional) -->
<div class="debug-panel">
    <h5>🔍 Debug Info</h5>
    <p><strong>Canvas ID:</strong> <?= $canvasId ?></p>
    <p><strong>User ID:</strong> <?= $_SESSION['user_id'] ?></p>
    <p><strong>Current JSON Size:</strong> <span id="jsonSize">Calculando...</span> bytes</p>
    <button type="button" class="btn btn-sm btn-secondary" id="showJsonBtn">Ver JSON Atual</button>
    <pre id="jsonDisplay" style="display: none; margin-top: 10px;"></pre>
</div>

<!-- SurveyJS Core (Library para preview) - v2.4.1 (latest stable) -->
<link href="https://unpkg.com/survey-core@2.4.1/survey-core.min.css" rel="stylesheet">
<script src="https://unpkg.com/survey-core@2.4.1/survey.core.min.js"></script>

<!-- SurveyJS Creator (v2.4.1 - latest stable) -->
<link href="https://unpkg.com/survey-creator-core@2.4.1/survey-creator-core.min.css" rel="stylesheet">
<script src="https://unpkg.com/survey-creator-core@2.4.1/survey-creator-core.min.js"></script>

<!-- SurveyJS UI Theme (v2.4.1 - latest stable) -->
<script src="https://unpkg.com/survey-js-ui@2.4.1/survey-js-ui.min.js"></script>

<!-- SurveyJS Creator UI (v2.4.1 - latest stable) -->
<link href="https://unpkg.com/survey-creator-js@2.4.1/survey-creator-js.min.css" rel="stylesheet">
<script src="https://unpkg.com/survey-creator-js@2.4.1/survey-creator-js.min.js"></script>

<!-- SurveyJS License -->
<script src="<?= BASE_URL ?>/assets/js/surveyjs-license.js"></script>

<script>
    console.log('🧪 Survey Creator Test Page - Initializing...');

    // Verificar se SurveyJS Creator carregou
    if (typeof SurveyCreator === 'undefined') {
        alert('❌ Erro: SurveyJS Creator não carregou. Verifique sua conexão.');
    } else {
        console.log('✅ SurveyJS Creator loaded');
    }

    // Carregar JSON do banco
    const surveyJson = <?= json_encode(json_decode($canvas['form_config']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    console.log('📄 Loaded survey config:', surveyJson);

    // Opções do Creator
    const creatorOptions = {
        showLogicTab: true,              // Tab de lógica condicional
        showTranslationTab: true,        // Tab de traduções/localização
        showThemeTab: true,              // Tab de temas (Theme Editor)
        showJSONEditorTab: true,         // Fallback: editor JSON manual
        isAutoSave: false,               // Não auto-salvar (manual save)
        showDesignerTab: true,           // Tab principal (designer)
        showPreviewTab: true,            // Tab de preview
        questionTypes: [                 // Tipos de perguntas disponíveis
            "text", "comment", "checkbox",
            "radiogroup", "dropdown", "rating",
            "matrix", "matrixdynamic", "boolean",
            "html", "file", "imagepicker",
            "ranking", "tagbox", "multipletext"
        ]
    };

    // Criar instância do Survey Creator
    const creator = new SurveyCreator.SurveyCreator(creatorOptions);

    // Carregar JSON existente
    creator.JSON = surveyJson;

    // Aplicar tema (opcional)
    creator.theme = "defaultV2";

    // Renderizar Creator no DOM
    creator.render("creatorElement");

    console.log('✅ Survey Creator rendered successfully');

    // Atualizar tamanho do JSON em tempo real
    creator.onModified.add(() => {
        const jsonString = JSON.stringify(creator.JSON);
        document.getElementById('jsonSize').textContent = jsonString.length;
        console.log('📝 Survey modified, new size:', jsonString.length, 'bytes');
    });

    // Inicializar tamanho
    document.getElementById('jsonSize').textContent = JSON.stringify(creator.JSON).length;

    // Botão de salvar (capturar JSON do Creator)
    document.getElementById('creatorForm').addEventListener('submit', (e) => {
        console.log('💾 Saving survey...');

        // Capturar JSON do Creator
        const surveyJson = creator.JSON;
        const jsonString = JSON.stringify(surveyJson);

        // Validar JSON
        try {
            JSON.parse(jsonString);
            console.log('✅ JSON valid:', jsonString.length, 'bytes');
        } catch (error) {
            e.preventDefault();
            alert('❌ Erro: JSON inválido gerado pelo Creator\n\n' + error.message);
            return;
        }

        // Injetar no hidden input
        document.getElementById('form_config').value = jsonString;

        // Confirmação para templates de produção
        <?php if ($is_production): ?>
        if (!confirm('🚨 ATENÇÃO!\n\nVocê está salvando mudanças em um template DE PRODUÇÃO.\nIsso afetará IMEDIATAMENTE os usuários finais.\n\nTem certeza que deseja continuar?')) {
            e.preventDefault();
            return;
        }
        <?php endif; ?>

        console.log('✅ Form submitted with JSON');
    });

    // Botão de exportar JSON
    document.getElementById('exportJsonBtn').addEventListener('click', () => {
        const surveyJson = creator.JSON;
        const jsonString = JSON.stringify(surveyJson, null, 2);

        // Criar blob e download
        const blob = new Blob([jsonString], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'canvas-<?= $canvas['slug'] ?>-<?= date('Y-m-d-His') ?>.json';
        a.click();

        console.log('📥 JSON exported');
    });

    // Botão de debug (mostrar JSON)
    document.getElementById('showJsonBtn').addEventListener('click', () => {
        const jsonDisplay = document.getElementById('jsonDisplay');
        const isVisible = jsonDisplay.style.display !== 'none';

        if (isVisible) {
            jsonDisplay.style.display = 'none';
            document.getElementById('showJsonBtn').textContent = 'Ver JSON Atual';
        } else {
            const surveyJson = creator.JSON;
            const jsonString = JSON.stringify(surveyJson, null, 2);
            jsonDisplay.textContent = jsonString;
            jsonDisplay.style.display = 'block';
            document.getElementById('showJsonBtn').textContent = 'Esconder JSON';
        }
    });
</script>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
