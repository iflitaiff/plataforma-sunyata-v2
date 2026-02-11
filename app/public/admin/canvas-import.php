<?php
/**
 * Admin: Canvas Import
 * Importar JSON do Survey Creator para criar novo Canvas
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

// Stats for admin-header.php
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

// Buscar verticais disponíveis
$verticals = [
    'juridico' => 'Jurídico',
    'compliance' => 'Compliance',
    'financeiro' => 'Financeiro',
    'rh' => 'Recursos Humanos'
];

$pageTitle = 'Importar Canvas JSON';

// Include header
include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
    #preview-container {
        display: none;
    }

    .field-preview {
        background: #f8f9fa;
        padding: 8px 12px;
        border-radius: 4px;
        margin-bottom: 8px;
        border-left: 3px solid #0d6efd;
    }

    .field-preview code {
        background: #e9ecef;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 12px;
    }

    #json-drop-zone {
        border: 3px dashed #dee2e6;
        border-radius: 8px;
        padding: 40px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s;
        background: #f8f9fa;
    }

    #json-drop-zone:hover {
        border-color: #0d6efd;
        background: #e7f1ff;
    }

    #json-drop-zone.drag-over {
        border-color: #198754;
        background: #d1e7dd;
    }
</style>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/">Admin</a></li>
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/canvas-templates.php">Canvas Templates</a></li>
        <li class="breadcrumb-item active">Importar JSON</li>
    </ol>
</nav>

<h1 class="mb-3"><i class="bi bi-upload"></i> Importar Canvas JSON</h1>

<p class="text-muted mb-4">
    Faça upload de um JSON exportado do Survey Creator para criar automaticamente um novo Canvas Template.
</p>

<!-- Etapa 1: Upload do JSON -->
<div id="upload-section">
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">1. Selecione o arquivo JSON</h5>
        </div>
        <div class="card-body">
            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload-pane" type="button" role="tab">
                        <i class="bi bi-upload"></i> Upload de Arquivo
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="paste-tab" data-bs-toggle="tab" data-bs-target="#paste-pane" type="button" role="tab">
                        <i class="bi bi-clipboard"></i> Colar JSON
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Tab 1: Upload de Arquivo -->
                <div class="tab-pane fade show active" id="upload-pane" role="tabpanel">
                    <!-- Drop Zone -->
                    <div id="json-drop-zone">
                        <i class="bi bi-cloud-upload" style="font-size: 48px; color: #6c757d;"></i>
                        <h5 class="mt-3">Arraste um arquivo JSON aqui</h5>
                        <p class="text-muted mb-3">ou clique para selecionar</p>
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('json-file').click()">
                            <i class="bi bi-folder2-open"></i> Escolher Arquivo
                        </button>
                        <input type="file" id="json-file" accept=".json" style="display: none;">
                    </div>

                    <div class="mt-3 text-center">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            Arquivos JSON exportados do Survey Creator (localização: <code>~/dev-tools/survey-creator/creator-exports/</code>)
                        </small>
                    </div>
                </div>

                <!-- Tab 2: Colar JSON -->
                <div class="tab-pane fade" id="paste-pane" role="tabpanel">
                    <div class="mb-3">
                        <label for="json-textarea" class="form-label">
                            <strong>Cole o JSON do Survey Creator aqui:</strong>
                        </label>
                        <textarea class="form-control font-monospace" id="json-textarea" rows="15"
                                  placeholder='{"title": "Meu Canvas", "pages": [...], "ajSystemPrompt": "..."}'></textarea>
                        <div class="form-text">
                            Copie o JSON diretamente do Survey Creator (botão "Copiar JSON") e cole aqui.
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary" onclick="pasteFromClipboard()">
                            <i class="bi bi-clipboard-check"></i> Colar da Área de Transferência
                        </button>
                        <button type="button" class="btn btn-success" onclick="processJSONFromTextarea()">
                            <i class="bi bi-arrow-right-circle"></i> Processar JSON
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="clearTextarea()">
                            <i class="bi bi-x-circle"></i> Limpar
                        </button>
                    </div>

                    <div id="paste-status" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Etapa 2: Preview e Configuração -->
<div id="preview-container">
    <form id="import-form" method="POST" action="canvas-import-process.php">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="json_data" id="json-data-input">

        <!-- Preview dos Campos -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">2. Preview do Formulário</h5>
            </div>
            <div class="card-body">
                <div id="fields-preview">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
        </div>

        <!-- Configuração do Canvas -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">3. Configuração do Canvas</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="canvas-name" class="form-label">Nome do Canvas <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="canvas-name" name="canvas_name" required>
                        <div class="form-text">Nome exibido aos usuários (ex: "Canvas Jurídico - Análise Rápida")</div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="canvas-slug" class="form-label">Slug (Identificador Único) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="canvas-slug" name="canvas_slug" required pattern="[a-z0-9-]+">
                        <div class="form-text">Apenas letras minúsculas, números e hífens (ex: "juridico-analise-rapida")</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="canvas-vertical" class="form-label">Vertical <span class="text-danger">*</span></label>
                        <select class="form-select" id="canvas-vertical" name="canvas_vertical" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($verticals as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="max-questions" class="form-label">Máx. Perguntas Contextuais</label>
                        <input type="number" class="form-control" id="max-questions" name="max_questions" value="20" min="1" max="20">
                        <div class="form-text">Número máximo de perguntas que Claude pode fazer (padrão: 5)</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="system-prompt" class="form-label">System Prompt (Nível 2 - Opcional)</label>
                    <textarea class="form-control font-monospace" id="system-prompt" name="system_prompt" rows="8" placeholder="Opcional - deixe vazio para usar apenas prompts da vertical + ajSystemPrompt"></textarea>
                    <div class="form-text">
                        <strong>Detectado automaticamente:</strong> Se o JSON contém <code>ajSystemPrompt</code> (Nível 3), será preenchido automaticamente.
                        <br>
                        <small class="text-muted"><i class="bi bi-info-circle"></i> Sistema concatena: Vertical (Nível 1) → Este campo (Nível 2) → ajSystemPrompt (Nível 3)</small>
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="use-auto-generation" name="use_auto_generation" checked>
                    <label class="form-check-label" for="use-auto-generation">
                        <strong>Usar geração automática de prompt</strong> (recomendado)
                    </label>
                    <div class="form-text">
                        Com esta opção ativa, o sistema gerará automaticamente o prompt do usuário baseado nos campos do formulário.
                        Deixe desmarcado apenas se você pretende criar um template Handlebars customizado manualmente depois.
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="activate-now" name="activate_now">
                    <label class="form-check-label" for="activate-now">
                        Ativar Canvas imediatamente
                    </label>
                    <div class="form-text">
                        Se desmarcado, o Canvas será criado como rascunho (inativo) para revisão posterior.
                    </div>
                </div>
            </div>
        </div>

        <!-- Botões de Ação -->
        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-check-circle"></i> Criar Canvas
            </button>
            <a href="canvas-templates.php" class="btn btn-secondary btn-lg">
                <i class="bi bi-x-circle"></i> Cancelar
            </a>
            <button type="button" class="btn btn-outline-secondary btn-lg" onclick="resetForm()">
                <i class="bi bi-arrow-counterclockwise"></i> Carregar Outro JSON
            </button>
        </div>
    </form>
</div>

<script>
// Global variable to store parsed JSON
let parsedJSON = null;

// Elements
const dropZone = document.getElementById('json-drop-zone');
const fileInput = document.getElementById('json-file');
const uploadSection = document.getElementById('upload-section');
const previewContainer = document.getElementById('preview-container');
const fieldsPreview = document.getElementById('fields-preview');
const canvasNameInput = document.getElementById('canvas-name');
const canvasSlugInput = document.getElementById('canvas-slug');
const systemPromptInput = document.getElementById('system-prompt');
const jsonDataInput = document.getElementById('json-data-input');

// Drag and drop handlers
dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('drag-over');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('drag-over');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');

    const files = e.dataTransfer.files;
    if (files.length > 0) {
        handleFile(files[0]);
    }
});

dropZone.addEventListener('click', () => {
    fileInput.click();
});

fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        handleFile(e.target.files[0]);
    }
});

// Auto-suggest canvas name when title changes
canvasNameInput.addEventListener('blur', () => {
    if (canvasSlugInput.value === '') {
        const slug = canvasNameInput.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // Remove accents
            .replace(/[^\w\s-]/g, '') // Remove special chars
            .replace(/\s+/g, '-') // Spaces to hyphens
            .slice(0, 50);
        canvasSlugInput.value = slug;
    }
});

function handleFile(file) {
    // Accept both .json and .JSON (case-insensitive)
    if (!file.name.toLowerCase().endsWith('.json')) {
        alert('❌ Por favor, selecione um arquivo JSON válido.');
        return;
    }

    const reader = new FileReader();

    reader.onload = (e) => {
        try {
            parsedJSON = JSON.parse(e.target.result);

            // Validate JSON structure
            if (!parsedJSON.pages || !Array.isArray(parsedJSON.pages)) {
                throw new Error('JSON inválido: propriedade "pages" não encontrada ou não é array');
            }

            // Process JSON
            processJSON(parsedJSON);

        } catch (error) {
            alert('❌ Erro ao processar JSON:\n\n' + error.message);
            console.error('JSON parse error:', error);
        }
    };

    reader.readAsText(file);
}

function processJSON(json) {
    // Extract fields from pages
    const fields = [];
    let totalElements = 0;

    json.pages.forEach((page, pageIndex) => {
        if (page.elements && Array.isArray(page.elements)) {
            page.elements.forEach(element => {
                totalElements++;

                if (element.name && element.type !== 'html') {
                    fields.push({
                        name: element.name,
                        title: element.title || element.name,
                        type: element.type || 'text',
                        required: element.isRequired || false,
                        promptLabel: element.promptLabel || '',
                        includeInPrompt: element.includeInPrompt !== false
                    });
                }
            });
        }
    });

    // Display preview
    let previewHTML = `<p><strong>Total de elementos:</strong> ${totalElements}</p>`;
    previewHTML += `<p><strong>Campos detectados:</strong> ${fields.length}</p><hr>`;

    if (fields.length > 0) {
        previewHTML += '<div class="row">';
        fields.forEach(field => {
            previewHTML += `
                <div class="col-md-6">
                    <div class="field-preview">
                        <div><code>${field.name}</code> ${field.required ? '<span class="badge bg-danger">obrigatório</span>' : ''}</div>
                        <div class="small text-muted">${field.title}</div>
                        <div class="small">Tipo: <em>${field.type}</em></div>
                        ${field.promptLabel ? `<div class="small">Prompt Label: <strong>${field.promptLabel}</strong></div>` : ''}
                        ${!field.includeInPrompt ? '<div class="small text-warning">⚠️ Não incluído no prompt</div>' : ''}
                    </div>
                </div>
            `;
        });
        previewHTML += '</div>';
    } else {
        previewHTML += '<div class="alert alert-warning">⚠️ Nenhum campo detectado. Verifique a estrutura do JSON.</div>';
    }

    fieldsPreview.innerHTML = previewHTML;

    // Auto-fill metadata
    if (json.title) {
        canvasNameInput.value = json.title;

        // Auto-generate slug
        const slug = json.title
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^\w\s-]/g, '')
            .replace(/\s+/g, '-')
            .slice(0, 50);
        canvasSlugInput.value = slug;
    }

    // Check for ajSystemPrompt
    if (json.ajSystemPrompt && json.ajSystemPrompt.trim() !== '') {
        systemPromptInput.value = json.ajSystemPrompt.trim();
    } else {
        systemPromptInput.value = 'Você é um assistente especializado em [ÁREA]. Analise cuidadosamente as informações fornecidas e forneça uma resposta detalhada e profissional.';
    }

    // Store JSON data
    jsonDataInput.value = JSON.stringify(json);

    // Show preview section
    uploadSection.style.display = 'none';
    previewContainer.style.display = 'block';

    // Scroll to preview
    previewContainer.scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
    if (confirm('Deseja realmente carregar outro JSON? As configurações atuais serão perdidas.')) {
        parsedJSON = null;
        fileInput.value = '';
        document.getElementById('json-textarea').value = '';
        uploadSection.style.display = 'block';
        previewContainer.style.display = 'none';
        document.getElementById('import-form').reset();
    }
}

// Clipboard paste functions
async function pasteFromClipboard() {
    try {
        const text = await navigator.clipboard.readText();
        document.getElementById('json-textarea').value = text;
        document.getElementById('paste-status').innerHTML = '<div class="alert alert-success">✅ JSON colado da área de transferência!</div>';

        // Auto-clear status after 3 seconds
        setTimeout(() => {
            document.getElementById('paste-status').innerHTML = '';
        }, 3000);
    } catch (err) {
        document.getElementById('paste-status').innerHTML = '<div class="alert alert-danger">❌ Erro ao acessar área de transferência. Use Ctrl+V manualmente.</div>';
        console.error('Clipboard error:', err);
    }
}

function processJSONFromTextarea() {
    const jsonText = document.getElementById('json-textarea').value.trim();

    if (!jsonText) {
        document.getElementById('paste-status').innerHTML = '<div class="alert alert-warning">⚠️ Por favor, cole um JSON antes de processar.</div>';
        return;
    }

    try {
        const json = JSON.parse(jsonText);

        // Validate structure
        if (!json.pages || !Array.isArray(json.pages)) {
            throw new Error('JSON inválido: propriedade "pages" não encontrada ou não é array');
        }

        // Process using existing function
        processJSON(json);

    } catch (error) {
        document.getElementById('paste-status').innerHTML = '<div class="alert alert-danger">❌ Erro ao processar JSON:<br><code>' + error.message + '</code></div>';
        console.error('JSON parse error:', error);
    }
}

function clearTextarea() {
    document.getElementById('json-textarea').value = '';
    document.getElementById('paste-status').innerHTML = '';
}

// Form validation before submit
document.getElementById('import-form').addEventListener('submit', (e) => {
    const slug = canvasSlugInput.value;

    // Validate slug format
    if (!/^[a-z0-9-]+$/.test(slug)) {
        e.preventDefault();
        alert('❌ Slug inválido. Use apenas letras minúsculas, números e hífens.');
        canvasSlugInput.focus();
        return;
    }

    // Confirm creation
    if (!confirm('Deseja criar o Canvas "' + canvasNameInput.value + '"?')) {
        e.preventDefault();
    }
});
</script>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
