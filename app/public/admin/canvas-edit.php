<?php
/**
 * Admin: Canvas Templates - Edição
 * Editar Canvas existente (form config + prompts)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Helpers\VerticalConfig;
use Sunyata\AI\ModelService;
use Sunyata\Services\CanvasService;
use Sunyata\Services\VerticalService;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

// IMPORTANTE: Inicializar $stats como array ANTES de usar
$stats = [];

// Stats for admin-header.php (pending requests badge)
try {
    $result = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM vertical_access_requests
        WHERE status = 'pending'
    ");
    $stats['pending_requests'] = $result['count'] ?? 0;
} catch (Exception $e) {
    // Table doesn't exist or query failed - set to 0
    $stats['pending_requests'] = 0;
}

// Obter ID do Canvas
$canvasId = $_GET['id'] ?? null;
if (!$canvasId) {
    $_SESSION['error'] = 'Canvas ID não fornecido';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Buscar Canvas
$canvas = $db->fetchOne("
    SELECT * FROM canvas_templates WHERE id = :id
", ['id' => $canvasId]);

if (!$canvas) {
    $_SESSION['error'] = 'Canvas não encontrado';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Processar formulário de edição
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $message = 'Token de segurança inválido';
        $message_type = 'danger';
    } else {
        try {
            // Validar JSON do form_config
            $formConfig = $_POST['form_config'] ?? '';
            $jsonDecoded = json_decode($formConfig, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Form Config JSON inválido: ' . json_last_error_msg());
            }

            // Validar e processar api_params_override
            $apiParamsOverride = trim($_POST['api_params_override'] ?? '{}');
            $apiParamsValue = null; // NULL = sem override (usa defaults da vertical)

            if ($apiParamsOverride !== '' && $apiParamsOverride !== '{}') {
                $apiParamsDecoded = json_decode($apiParamsOverride, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('API Params Override JSON inválido: ' . json_last_error_msg());
                }
                // Validar parâmetros usando VerticalConfig::validate() com contexto canvas
                $validation = VerticalConfig::validate($apiParamsDecoded, 'canvas');
                if (!$validation['valid']) {
                    throw new Exception('API Params inválidos: ' . implode(', ', $validation['errors']));
                }
                $apiParamsValue = json_encode($apiParamsDecoded, JSON_UNESCAPED_UNICODE);
            }

            // Atualizar Canvas
            $updated = $db->update('canvas_templates', [
                'name' => $_POST['name'] ?? $canvas['name'],
                'form_config' => $formConfig,
                'system_prompt' => $_POST['system_prompt'] ?? '',
                'user_prompt_template' => $_POST['user_prompt_template'] ?? '',
                'max_questions' => (int)($_POST['max_questions'] ?? 5),
                'api_params_override' => $apiParamsValue,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $canvasId]);

            // Update vertical assignments (many-to-many)
            $selectedVerticals = $_POST['verticals'] ?? [];
            $canvasService = CanvasService::getInstance();
            $verticalsUpdated = $canvasService->assignVerticals($canvasId, $selectedVerticals, true);

            if ($updated || $verticalsUpdated) {
                $message = 'Canvas atualizado com sucesso!';
                $message_type = 'success';

                // Recarregar canvas atualizado
                $canvas = $db->fetchOne("SELECT * FROM canvas_templates WHERE id = :id", ['id' => $canvasId]);
            } else {
                $message = 'Nenhuma mudança detectada';
                $message_type = 'info';
            }

        } catch (Exception $e) {
            $message = 'Erro ao atualizar Canvas: ' . $e->getMessage();
            $message_type = 'danger';
            error_log('Canvas edit error: ' . $e->getMessage());
        }
    }
}

// Formatar JSON para exibição (pretty print)
$formConfigFormatted = json_encode(json_decode($canvas['form_config']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Load vertical assignments and available verticals
$canvasService = CanvasService::getInstance();
$verticalService = VerticalService::getInstance();

$assignedVerticals = $canvasService->getAssignedVerticals($canvasId);
$assignedSlugs = array_column($assignedVerticals, 'vertical_slug');
$allVerticals = $verticalService->getAll(); // Get all available verticals

// For backward compatibility with old code that references $canvas['vertical']
// Use first assigned vertical as primary (or empty if none)
$primaryVertical = $assignedSlugs[0] ?? '';

// Preparar dados para seção de API Params Override
$verticalDefaults = $primaryVertical ? VerticalConfig::get($primaryVertical) : [];
$canvasApiOverrides = [];
if (!empty($canvas['api_params_override'])) {
    $canvasApiOverrides = json_decode($canvas['api_params_override'], true) ?? [];
}
// Parâmetros efetivos: vertical config + canvas overrides
$effectiveApiParams = array_merge($verticalDefaults, $canvasApiOverrides);

// ModelService para o partial de docs de parâmetros
$modelService = ModelService::getInstance();
$availableModels = $modelService->getAvailableModels();
$modelCacheInfo = $modelService->getCacheInfo();

$pageTitle = 'Editar Canvas: ' . $canvas['name'];

// Include header
include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
    /* Monaco Editor Container */
    #monaco-container {
        width: 100%;
        height: 600px;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
    }

    /* Textarea fallback (se Monaco falhar) */
    #form_config_textarea {
        font-family: 'Courier New', Courier, monospace;
        font-size: 13px;
    }

    .prompt-textarea {
        font-family: 'Courier New', Courier, monospace;
        font-size: 13px;
    }
</style>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/">Admin</a></li>
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/canvas-templates.php">Canvas Templates</a></li>
        <li class="breadcrumb-item active"><?= sanitize_output($canvas['name']) ?></li>
    </ol>
</nav>

<h1 class="mb-3">Editar Canvas: <?= sanitize_output($canvas['name']) ?></h1>

<?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
        <?= sanitize_output($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Informações do Canvas -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Informações do Canvas</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <p><strong>Slug:</strong> <code><?= sanitize_output($canvas['slug']) ?></code></p>
                <p><strong>Verticais:</strong>
                    <?php if (!empty($assignedSlugs)): ?>
                        <?php foreach ($assignedSlugs as $slug): ?>
                            <span class="badge bg-primary"><?= ucfirst(sanitize_output($slug)) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Nenhuma vertical atribuída</span>
                    <?php endif; ?>
                </p>
                <p><strong>Status:</strong>
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
            </div>
        </div>
    </div>
</div>

<!-- Formulário de Edição -->
<form method="POST" id="editForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

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
                <div class="form-text">Nome exibido aos usuários (ex: "Canvas Jurídico Geral")</div>
            </div>
        </div>
    </div>

    <!-- Vertical Assignments (Many-to-Many) -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">2. Atribuição de Verticais</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                <i class="bi bi-info-circle"></i> Selecione em quais verticais este canvas aparecerá.
                Um canvas pode aparecer em <strong>múltiplas verticais</strong> simultaneamente.
            </p>

            <div class="row">
                <?php if (empty($allVerticals)): ?>
                    <div class="col-12">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Nenhuma vertical disponível.
                            <a href="<?= BASE_URL ?>/admin/verticals.php" class="alert-link">Criar vertical</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($allVerticals as $vertical): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="verticals[]"
                                       value="<?= sanitize_output($vertical['slug']) ?>"
                                       id="vertical_<?= sanitize_output($vertical['slug']) ?>"
                                       <?= in_array($vertical['slug'], $assignedSlugs) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vertical_<?= sanitize_output($vertical['slug']) ?>">
                                    <?php if (!empty($vertical['icone'])): ?>
                                        <?= $vertical['icone'] ?>
                                    <?php endif; ?>
                                    <strong><?= sanitize_output($vertical['nome']) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= sanitize_output($vertical['slug']) ?></small>
                                    <?php if (!$vertical['disponivel']): ?>
                                        <span class="badge bg-warning text-dark">Indisponível</span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($assignedSlugs)): ?>
                <div class="alert alert-info mt-3">
                    <strong><i class="bi bi-check-circle"></i> Atualmente atribuído a:</strong>
                    <?php foreach ($assignedSlugs as $slug): ?>
                        <span class="badge bg-primary"><?= sanitize_output($slug) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mt-3">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Canvas sem verticais atribuídas!</strong>
                    Selecione pelo menos uma vertical para que o canvas apareça no portal.
                </div>
            <?php endif; ?>

            <div class="form-text mt-2">
                <i class="bi bi-lightbulb"></i> <strong>Dica:</strong> Canvas compartilhados entre verticais
                evitam duplicação e facilitam manutenção. Configure parâmetros específicos por vertical
                usando "API Params Override" abaixo.
            </div>
        </div>
    </div>

    <!-- Form Configuration (Monaco Editor) -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">3. Form Configuration (SurveyJS JSON)</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Configure os campos do formulário usando a sintaxe SurveyJS. Use o Monaco Editor abaixo para editar o JSON.
            </p>

            <!-- Monaco Editor -->
            <div id="monaco-container"></div>

            <!-- Hidden textarea (para enviar o JSON) -->
            <textarea id="form_config" name="form_config" style="display: none;"><?= htmlspecialchars($canvas['form_config']) ?></textarea>

            <!-- Fallback textarea (se Monaco falhar) -->
            <noscript>
                <textarea id="form_config_textarea" name="form_config" class="form-control"
                          rows="20"><?= htmlspecialchars($canvas['form_config']) ?></textarea>
            </noscript>

            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-secondary" id="validateJson">
                    <i class="bi bi-check-circle"></i> Validar JSON
                </button>
                <span id="jsonStatus" class="ms-2"></span>
            </div>
        </div>
    </div>

    <!-- System Prompt -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">4. System Prompt (Nível 2 - Opcional)</h5>
        </div>
        <div class="card-body">
            <!-- Orientação sobre hierarquia -->
            <div class="alert alert-info mb-3">
                <strong><i class="bi bi-info-circle"></i> Hierarquia de System Prompts (4 níveis):</strong>
                <ol class="mb-2 mt-2">
                    <li><strong>Nível 0:</strong> Prompt do Portal (genérico, cross-vertical — Config Portal no admin)</li>
                    <li><strong>Nível 1:</strong> Prompts da Vertical (config/verticals.php + overrides no banco)</li>
                    <li><strong>Nível 2 (este campo):</strong> Instruções específicas deste Canvas Template</li>
                    <li><strong>Nível 3:</strong> <code>ajSystemPrompt</code> configurado no Survey Creator (mais específico)</li>
                </ol>
                <small class="text-muted">
                    <i class="bi bi-lightbulb"></i> <strong>Este campo é opcional.</strong> Se deixado vazio, o sistema usará Portal (Nível 0) + Vertical (Nível 1) + ajSystemPrompt (Nível 3).
                    O sistema <strong>concatena automaticamente</strong> todos os níveis não vazios.
                </small>
            </div>

            <p class="text-muted">
                Instruções enviadas ao Claude sobre como se comportar. Use <code>[PERGUNTA-N]</code> e <code>[RESPOSTA-FINAL]</code> como marcadores quando aplicável.
            </p>

            <textarea class="form-control prompt-textarea" id="system_prompt" name="system_prompt"
                      rows="15" placeholder="Deixe vazio para usar apenas prompts da vertical + ajSystemPrompt do Survey Creator"><?= htmlspecialchars($canvas['system_prompt']) ?></textarea>

            <div class="form-text">
                <strong>Marcadores opcionais:</strong> <code>[PERGUNTA-1]</code> até <code>[PERGUNTA-5]</code>
                e <code>[RESPOSTA-FINAL]</code> podem ser usados para conversas multi-turn.
            </div>
        </div>
    </div>

    <!-- User Prompt Template -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">5. User Prompt Template (Opcional)</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-warning mb-3">
                <strong><i class="bi bi-magic"></i> Geração Automática:</strong>
                <p class="mb-0">
                    <small>
                        Se este campo estiver vazio, o sistema <strong>gera automaticamente</strong> o prompt do usuário
                        a partir dos dados do formulário + custom properties (Manus v2.0).
                        <br>
                        <strong>Recomendação:</strong> Deixe vazio para MVP. Configure manualmente apenas quando precisar
                        de controle fino sobre o formato do prompt.
                    </small>
                </p>
            </div>

            <p class="text-muted">
                Template do prompt do usuário com placeholders. Use <code>{{campo}}</code> para inserir valores do formulário.
            </p>

            <textarea class="form-control prompt-textarea" id="user_prompt_template" name="user_prompt_template"
                      rows="12" placeholder="Deixe vazio para geração automática a partir do formulário"><?= htmlspecialchars($canvas['user_prompt_template']) ?></textarea>

            <div class="form-text">
                <strong>Placeholders disponíveis:</strong> Os nomes dos campos definidos no Form Configuration.
                Ex: <code>{{tarefa}}</code>, <code>{{contexto}}</code>, <code>{{documentos}}</code>
            </div>
        </div>
    </div>

    <!-- Configurações -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">6. Configurações (Opcional)</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-secondary mb-3">
                <strong><i class="bi bi-clock-history"></i> Funcionalidade Futura:</strong>
                <p class="mb-0">
                    <small>
                        Estas configurações estão preparadas para customizações futuras (ex: IATR).
                        No MVP atual, são apenas armazenadas mas não afetam o comportamento do sistema.
                    </small>
                </p>
            </div>

            <div class="mb-3">
                <label for="max_questions" class="form-label">Máximo de Perguntas Contextuais</label>
                <input type="number" class="form-control" id="max_questions" name="max_questions"
                       value="<?= $canvas['max_questions'] ?>" min="1" max="20">
                <div class="form-text">
                    Número máximo de perguntas que Claude pode fazer antes da resposta final (padrão: 5).
                    <br>
                    <small class="text-muted"><i class="bi bi-info-circle"></i> Atualmente não implementado. Será ativado conforme necessidade.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- API Params Override (por canvas) -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="bi bi-sliders"></i> 6. Parâmetros API (Override por Canvas)</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <strong><i class="bi bi-info-circle"></i> Hierarquia de parâmetros API:</strong>
                <ol class="mb-0 mt-2">
                    <li><strong>Defaults do arquivo</strong> (<code>config/verticals.php</code>)</li>
                    <li><strong>Overrides da Vertical</strong> (Config Verticais no admin)</li>
                    <li><strong>Override deste Canvas</strong> (este campo) — sobrescreve os anteriores</li>
                </ol>
                <small class="text-muted mt-2 d-block">
                    <i class="bi bi-lightbulb"></i> Deixe <code>{}</code> vazio para usar os parâmetros da vertical.
                    Configure apenas os parâmetros que este canvas específico precisa alterar (ex: modelo, max_tokens).
                </small>
                <small class="text-warning mt-1 d-block">
                    <i class="bi bi-exclamation-triangle"></i> <strong>system_prompt NÃO é aceito aqui.</strong>
                    System prompts usam hierarquia própria de 4 níveis (Portal → Vertical → Canvas → ajSystemPrompt).
                </small>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <label class="form-label"><i class="bi bi-code-square"></i> Overrides (JSON)</label>
                    <textarea
                        name="api_params_override"
                        id="apiParamsTextarea"
                        rows="8"
                        class="form-control font-monospace"
                        style="font-size: 0.9rem;"
                    ><?= htmlspecialchars(json_encode($canvasApiOverrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}') ?></textarea>

                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="resetApiParams">
                            <i class="bi bi-arrow-counterclockwise"></i> Resetar (usar da vertical)
                        </button>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label"><i class="bi bi-eye"></i> Parâmetros Efetivos</label>
                    <pre id="apiParamsPreview" class="bg-light p-3 border rounded" style="max-height: 300px; overflow-y: auto; font-size: 0.85rem;"></pre>

                    <!-- Parâmetros Disponíveis (partial reutilizável) -->
                    <div class="mt-3">
                        <?php include __DIR__ . '/../../src/views/partials/api-params-docs.php'; ?>
                    </div>

                    <div class="alert alert-danger mt-2 mb-0 py-2" style="font-size: 0.85rem;">
                        <i class="bi bi-x-circle"></i> <code>system_prompt</code> <strong>não</strong> é aceito aqui. Use a Seção 3 acima.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Botões de Ação -->
    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-save"></i> Salvar Alterações
        </button>
        <a href="canvas-templates.php" class="btn btn-secondary btn-lg">
            <i class="bi bi-x-circle"></i> Cancelar
        </a>
    </div>
</form>

<!-- API Params Override: Preview em tempo real -->
<script>
(function() {
    const verticalConfig = <?= json_encode($verticalDefaults, JSON_UNESCAPED_UNICODE) ?>;
    const textarea = document.getElementById('apiParamsTextarea');
    const preview = document.getElementById('apiParamsPreview');
    const resetBtn = document.getElementById('resetApiParams');

    function updateApiPreview() {
        try {
            const overrides = JSON.parse(textarea.value || '{}');
            const effective = {...verticalConfig, ...overrides};
            preview.textContent = JSON.stringify(effective, null, 2);
            preview.classList.remove('border-danger');
            preview.classList.add('border-success');
        } catch (e) {
            preview.textContent = '❌ JSON inválido: ' + e.message;
            preview.classList.remove('border-success');
            preview.classList.add('border-danger');
        }
    }

    textarea.addEventListener('input', updateApiPreview);
    updateApiPreview();

    resetBtn.addEventListener('click', function() {
        if (confirm('Resetar para usar parâmetros da vertical?')) {
            textarea.value = '{}';
            updateApiPreview();
        }
    });
})();
</script>

<!-- Monaco Editor (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.52.0/min/vs/loader.js"></script>

<script>
    // Inicializar Monaco Editor
    require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.0/min/vs' } });

    require(['vs/editor/editor.main'], function () {
        const editor = monaco.editor.create(document.getElementById('monaco-container'), {
            value: <?= json_encode($formConfigFormatted) ?>,
            language: 'json',
            theme: 'vs-dark',
            automaticLayout: true,
            minimap: { enabled: true },
            fontSize: 13,
            wordWrap: 'on',
            formatOnPaste: true,
            formatOnType: true
        });

        // Sincronizar editor com textarea hidden
        editor.onDidChangeModelContent(() => {
            document.getElementById('form_config').value = editor.getValue();
        });

        // Validar JSON
        document.getElementById('validateJson').addEventListener('click', () => {
            const jsonValue = editor.getValue();
            try {
                JSON.parse(jsonValue);
                document.getElementById('jsonStatus').innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> JSON válido!</span>';
            } catch (e) {
                document.getElementById('jsonStatus').innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> JSON inválido: ' + e.message + '</span>';
            }
        });

        // Validar antes de enviar
        document.getElementById('editForm').addEventListener('submit', (e) => {
            const jsonValue = editor.getValue();
            try {
                JSON.parse(jsonValue);
            } catch (error) {
                e.preventDefault();
                alert('Erro no JSON: ' + error.message + '\n\nPor favor, corrija o JSON antes de salvar.');
            }
        });
    });
</script>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
