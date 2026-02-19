<?php
/**
 * Licitações - Renderizar Formulário SurveyJS
 * Renderiza um formulário (canvas_template) específico baseado no slug
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar acesso à vertical
$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

if ($user_vertical !== 'licitacoes' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Pegar slug do template
$template_slug = $_GET['template'] ?? null;

if (!$template_slug) {
    $_SESSION['error'] = 'Template não especificado';
    redirect(BASE_URL . '/areas/licitacoes/index.php');
}

$db = Database::getInstance();

// Buscar template
$canvas = $db->fetchOne("
    SELECT ct.id, ct.slug, ct.name, ct.form_config, ct.system_prompt, ct.user_prompt_template, ct.max_questions, ct.current_version
    FROM canvas_templates ct
    INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
    WHERE ct.slug = :slug AND cva.vertical_slug = 'licitacoes' AND ct.is_active = TRUE
", ['slug' => $template_slug]);

if (!$canvas) {
    $_SESSION['error'] = 'Formulário não encontrado: ' . sanitize_output($template_slug);
    redirect(BASE_URL . '/areas/licitacoes/index.php');
}

// Decodificar form_config JSON
$formConfig = json_decode($canvas['form_config'], true);
$formConfigError = null;
if ($formConfig === null && json_last_error() !== JSON_ERROR_NONE) {
    $formConfigError = json_last_error_msg();
}

// Detectar modo debug
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

// Verificar Mock Mode da sessão
$mock_mode_active = $_SESSION['canvas_mock_mode'] ?? false;

$canvasVersion = $canvas['current_version'] ?? 1;
$pageTitle = $canvas['name'];
$activeNav = 'licitacoes';

$headExtra = <<<'HEADEXTRA'
<link href="https://unpkg.com/survey-core@2.4.1/survey-core.min.css" type="text/css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
<style>
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .header h1 {
            color: #1e293b;
            margin-bottom: 10px;
        }

        .canvas-nav {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .canvas-nav .btn {
            margin: 5px;
        }

        #surveyContainer {
            margin-top: 20px;
            width: 100% !important;
            max-width: 100% !important;
        }

        .sd-element--html,
        .sd-element--html .sd-html,
        .sd-question,
        .sd-question__title,
        .sd-question__description {
            display: block !important;
        }

        .sd-element--html {
            min-height: auto !important;
            height: auto !important;
        }

        .sd-element--html .sd-html {
            min-height: auto !important;
            height: auto !important;
            overflow: visible !important;
            max-height: none !important;
        }

        .sd-question__title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .sd-question__description {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 10px;
        }

        .sd-input,
        .sd-text,
        .sd-comment {
            border: 1px solid #ced4da !important;
            border-radius: 6px !important;
            padding: 10px !important;
            font-size: 1rem !important;
            transition: border-color 0.2s ease !important;
        }

        .sd-input,
        .sd-text {
            padding: 14px 16px !important;
            font-size: 1.05rem !important;
            min-height: 50px !important;
            line-height: 1.5 !important;
        }

        .sd-comment {
            padding: 12px 16px !important;
            font-size: 1rem !important;
            line-height: 1.6 !important;
        }

        .sd-input:focus,
        .sd-text:focus,
        .sd-comment:focus {
            outline: none !important;
        }

        .sd-question {
            margin-bottom: 30px !important;
            padding: 20px !important;
            border-radius: 10px !important;
        }

        #surveyContainer .sd-progress__text {
            font-size: 0 !important;
        }

        #resultContainer {
            display: none;
            margin-top: 30px;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 5px solid #28a745;
        }

        #resultContainer h3 {
            color: #28a745;
            margin-bottom: 20px;
        }

        #claudeResponse {
            background: white;
            padding: 30px;
            border-radius: 8px;
            font-family: 'Georgia', 'Times New Roman', serif;
            font-size: 1.05rem;
            line-height: 1.8;
            color: #2c3e50;
        }

        #claudeResponse h1, #claudeResponse h2, #claudeResponse h3, #claudeResponse h4 {
            color: #1a365d;
            font-weight: 600;
            margin-top: 1.5em;
            margin-bottom: 0.75em;
            line-height: 1.3;
        }

        #claudeResponse h1 { font-size: 1.8rem; border-bottom: 2px solid #e9ecef; padding-bottom: 0.3em; }
        #claudeResponse h2 { font-size: 1.5rem; border-bottom: 1px solid #f1f3f5; padding-bottom: 0.3em; }
        #claudeResponse h3 { font-size: 1.3rem; }
        #claudeResponse h4 { font-size: 1.1rem; }

        #claudeResponse p { margin-bottom: 1.2em; text-align: justify; }
        #claudeResponse ul, #claudeResponse ol { margin-bottom: 1.2em; padding-left: 2em; }
        #claudeResponse li { margin-bottom: 0.5em; }
        #claudeResponse strong { font-weight: 700; color: #1a365d; }
        #claudeResponse code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; font-size: 0.9em; color: #e83e8c; }
        #claudeResponse pre { background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #1a365d; overflow-x: auto; margin-bottom: 1.2em; }

        .loading { text-align: center; padding: 40px; }
        .loading .spinner-border { width: 3rem; height: 3rem; color: #764ba2; }
        .btn-voltar { margin-top: 20px; }
        .export-actions { margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; margin-bottom: 20px; }
        .export-actions .btn { padding: 10px 20px; font-weight: 500; }

        #debugContainer { margin-top: 30px; margin-bottom: 30px; }
        .debug-box { background: #e7f3ff !important; border: 2px solid #0066cc !important; border-radius: 10px !important; }
        .debug-box h4 { color: #0066cc; margin-bottom: 20px; font-weight: bold; }
        .debug-prompt { background: #f8f9fa; padding: 15px; border-radius: 5px; border: 1px solid #dee2e6; white-space: pre-wrap; font-family: 'Courier New', monospace; font-size: 0.85rem; max-height: 300px; overflow-y: auto; line-height: 1.5; color: #333; }
    </style>
HEADEXTRA;

$pageContent = function () use ($canvas, $canvasVersion, $template_slug, $debug_mode, $mock_mode_active, $formConfig, $formConfigError) {
?>
    <div class="container-custom">

        <?php if ($formConfigError): ?>
            <div class="alert alert-danger">
                <strong>Erro no template do formulário.</strong><br>
                O JSON do SurveyJS está inválido: <?= sanitize_output($formConfigError) ?>
            </div>
        <?php endif; ?>
        <!-- Navegação Superior -->
        <div class="canvas-nav">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="<?= BASE_URL ?>/areas/licitacoes/index.php" class="btn btn-sm btn-outline-secondary">
                        ← Voltar para Licitações
                    </a>
                    <button id="openDraftsBtn" class="btn btn-sm btn-outline-warning ms-2">Meus Rascunhos</button>
                    <button id="saveDraftBtn" class="btn btn-sm btn-warning ms-2" style="display:none">Salvar Rascunho</button>
                    <span id="draftStatus" class="text-muted small ms-2"></span>
                </div>
                <div>
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-sm btn-outline-info">
                        🏠 Dashboard
                    </a>
                    <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-danger">
                        🚪 Sair
                    </a>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="header">
            <span style="font-size: 0.75rem; color: #9ca3af;">v<?= $canvasVersion ?>.0</span>
        </div>

        <?php if ($mock_mode_active): ?>
        <div id="mockModeAlert" class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>⚠️ MODO TESTE ATIVO</strong><br>
            Respostas simuladas (Lorem ipsum). Não consome créditos da API.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Survey Container -->
        <div id="surveyContainer"></div>

        <!-- Loading State -->
        <div id="loadingContainer" class="loading" style="display: none;">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Processando...</span>
            </div>
            <p class="mt-3">Analisando com IA... Isso pode levar alguns segundos.</p>
        </div>

        <?php if ($debug_mode): ?>
        <div id="debugContainer" style="display: none;">
            <div class="alert alert-info debug-box">
                <h4>🛠️ DEBUG - Prompt Enviado</h4>
                <h5 class="debug-section-title">System Prompt:</h5>
                <pre id="debugSystemPrompt" class="debug-prompt"></pre>
                <h5 class="debug-section-title">User Prompt:</h5>
                <pre id="debugUserPrompt" class="debug-prompt"></pre>
                <h5 class="debug-section-title">Metadata:</h5>
                <div id="debugMetadata" class="debug-metadata"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Result Container -->
        <div id="resultContainer">
            <h3>📋 Resultado da Análise</h3>
            <div id="claudeResponse"></div>

            <div class="export-actions">
                <button id="btnExportPDF" class="btn btn-success">
                    📄 Baixar PDF
                </button>
                <button id="btnCopyText" class="btn btn-info ms-2">
                    📋 Copiar Texto
                </button>
            </div>

            <a href="?template=<?= urlencode($template_slug) ?>" class="btn btn-primary btn-voltar">
                ← Nova Análise (Mesmo Formulário)
            </a>
            <a href="<?= BASE_URL ?>/areas/licitacoes/index.php" class="btn btn-secondary btn-voltar">
                ← Voltar para Licitações
            </a>
        </div>

        <!-- Error Container -->
        <div id="errorContainer" style="display: none;">
            <div class="alert alert-warning border-start border-4 border-warning" style="border-radius: 10px;">
                <h4 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Não foi possível completar a análise</h4>
                <p id="errorMessage" class="mb-3"></p>
                <hr>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-primary" onclick="window.location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Tentar Novamente
                    </button>
                    <a href="<?= BASE_URL ?>/areas/licitacoes/index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar para Licitações
                    </a>
                </div>
            </div>
        </div>

        <!-- Support Footer -->
        <div style="margin-top: 50px; padding: 30px 20px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-top: 3px solid #764ba2; border-radius: 10px; text-align: center;">
            <h4 style="color: #1a365d; font-size: 1.2rem; font-weight: 600; margin-bottom: 10px;">💬 Precisa de ajuda?</h4>
            <p style="color: #495057; margin-bottom: 15px; font-size: 0.95rem;">
                Para reportar erros, esclarecer dúvidas e sugestões:
            </p>
            <div style="display: flex; justify-content: center; gap: 20px; flex-wrap: wrap;">
                <a href="https://chat.whatsapp.com/HEyyAyoS4bb6ycTMs0kLWq?mode=wwc"
                   target="_blank"
                   style="padding: 12px 24px; background: #25D366; color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
                    📱 WhatsApp
                </a>
                <a href="mailto:contato@sunyataconsulting.com"
                   style="padding: 12px 24px; background: #0d6efd; color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
                    📧 Email
                </a>
            </div>
        </div>
    </div>

    <!-- SurveyJS (v2.4.1) -->
    <script src="https://unpkg.com/survey-core@2.4.1/survey.core.min.js"></script>
    <script src="https://unpkg.com/survey-js-ui@2.4.1/survey-js-ui.min.js"></script>

    <!-- SurveyJS Commercial License -->
    <script src="<?= BASE_URL ?>/assets/js/surveyjs-license.js"></script>

    <!-- Drafts Manager -->
    <script src="<?= BASE_URL ?>/assets/js/drafts.js"></script>

    <script>
        if (typeof Survey === 'undefined') {
            document.getElementById('surveyContainer').innerHTML = '<div class="alert alert-danger">Erro: Biblioteca SurveyJS não carregou.</div>';
        } else {
            try {
                const surveyJson = <?= json_encode($formConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const survey = new Survey.Model(surveyJson);

                // ========== SERVER-SIDE DRAFTS ==========
                const canvasId = '<?= $canvas['slug'] ?>';
                const userId = <?= $_SESSION['user_id'] ?>;

                const draftManager = new DraftManager({
                    canvasTemplateId: <?= $canvas['id'] ?>,
                    survey: survey,
                    csrfToken: '<?= csrf_token() ?>',
                    baseUrl: '<?= BASE_URL ?>',
                    onStatusChange: (msg) => document.getElementById('draftStatus').textContent = msg
                });
                draftManager.migrateLocalStorage(`canvas_draft_${canvasId}_${userId}`);

                document.getElementById('saveDraftBtn').addEventListener('click', () => draftManager.saveDraft());
                document.getElementById('openDraftsBtn').addEventListener('click', () => draftManager.openDraftModal());

                survey.onValueChanged.add(() => {
                    document.getElementById('saveDraftBtn').style.display = 'inline-block';
                    draftManager.scheduleAutoSave();
                });

                survey.onCurrentPageChanged.add(() => {
                    draftManager.scheduleAutoSave();
                });

                function renderResponse(responseText) {
                    if (!responseText || typeof responseText !== 'string') {
                        return '<p class="text-muted">Nenhuma resposta gerada</p>';
                    }
                    const trimmed = responseText.trim();
                    const isHTML = /^<[a-z][^>]*>/i.test(trimmed);
                    let html;
                    if (isHTML) {
                        const isFullDocument = /<!DOCTYPE|<html/i.test(trimmed);
                        if (isFullDocument) {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(responseText, 'text/html');
                            html = doc.body ? doc.body.innerHTML : responseText;
                        } else {
                            html = responseText;
                        }
                    } else {
                        html = marked.parse(responseText);
                    }
                    return DOMPurify.sanitize(html, {
                        ALLOWED_TAGS: ['h1','h2','h3','h4','h5','h6','p','br','strong','em','u','ul','ol','li','blockquote','code','pre','a','table','thead','tbody','tr','td','th','hr','div','span'],
                        ALLOWED_ATTR: ['href','target','class','id','style']
                    });
                }
                // ========== FIM DRAFTS ==========

                // File upload handler
                survey.onUploadFiles.add(async function(sender, options) {
                    const successFiles = [];
                    const errors = [];
                    for (const file of options.files) {
                        try {
                            const formData = new FormData();
                            formData.append("file", file);
                            const response = await fetch('<?= BASE_URL ?>/api/canvas/upload-file.php', { method: 'POST', body: formData });
                            const data = await response.json();
                            if (data.file) {
                                successFiles.push({ file: file, content: data.file.content });
                            } else {
                                errors.push(data.error || 'Erro desconhecido');
                            }
                        } catch (err) { errors.push('Erro de conexão'); }
                    }
                    if (successFiles.length > 0) { options.callback("success", successFiles); }
                    else { options.callback("error", errors.join(', ')); }
                });

                survey.onClearFiles.add(function(sender, options) { options.callback("success"); });

                survey.render(document.getElementById("surveyContainer"));

                // Submit handler
                survey.onComplete.add(async function (sender) {
                    const formData = sender.data;
                    if (draftManager.currentDraftId) {
                        draftManager.deleteDraft(draftManager.currentDraftId);
                    }

                    document.getElementById('surveyContainer').style.display = 'none';
                    document.getElementById('loadingContainer').style.display = 'block';

                    try {
                        const debugParam = window.location.search.includes('debug=1') ? '?debug=1' : '';
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 300000);
                        const slowWarningId = setTimeout(() => {
                            const el = document.getElementById('loadingContainer');
                            if (el && el.style.display !== 'none') {
                                el.querySelector('p').innerHTML = 'Analisando com IA... Isso pode levar alguns segundos.<br><small class="text-muted mt-2 d-block">Ainda processando... Análises de editais longos podem levar até 3 minutos.</small>';
                            }
                        }, 60000);

                        const response = await fetch('<?= BASE_URL ?>/api/ai/canvas/submit' + debugParam, {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json', 
                                'X-CSRF-Token': '<?= csrf_token() ?>',
                                'X-Internal-Key': '<?= getenv("INTERNAL_API_KEY") ?: "dev-key-change-in-production" ?>'
                            },
                            body: JSON.stringify({ 
                                vertical: 'licitacoes',
                                template_id: <?= $canvas['id'] ?>,
                                user_id: <?= $_SESSION['user']['id'] ?? 0 ?>,
                                data: formData,
                                stream: false
                            }),
                            signal: controller.signal
                        });

                        clearTimeout(timeoutId);
                        clearTimeout(slowWarningId);

                        const contentType = response.headers.get('content-type') || '';
                        if (!response.ok) {
                            if (contentType.includes('application/json')) { const err = await response.json(); throw new Error(err.error || 'Erro do servidor'); }
                            else { throw new Error(response.status >= 500 ? 'TIMEOUT_SERVER' : 'SERVER_HTML_ERROR'); }
                        }
                        if (!contentType.includes('application/json')) throw new Error('SERVER_HTML_ERROR');

                        const result = await response.json();
                        document.getElementById('loadingContainer').style.display = 'none';

                        if (result.success) {
                            const debugMode = <?= $debug_mode ? 'true' : 'false' ?>;
                            if (debugMode && result.model) {
                                document.getElementById('debugSystemPrompt').textContent = '(System prompt não disponível no modo FastAPI)';
                                document.getElementById('debugUserPrompt').textContent = '(User prompt não disponível no modo FastAPI)';
                                document.getElementById('debugMetadata').innerHTML = `
                                    <div class="debug-badge"><strong>Modelo:</strong> ${result.model || 'N/A'}</div>
                                    <div class="debug-badge"><strong>Input:</strong> ${result.usage?.input_tokens || 0} tokens</div>
                                    <div class="debug-badge"><strong>Output:</strong> ${result.usage?.output_tokens || 0} tokens</div>
                                    <div class="debug-badge"><strong>Total:</strong> ${result.usage?.total_tokens || 0} tokens</div>
                                    <div class="debug-badge"><strong>Tempo:</strong> ${result.response_time_ms || 0}ms</div>
                                    <div class="debug-badge"><strong>Custo:</strong> $${(result.cost_usd || 0).toFixed(4)}</div>
                                    <div class="debug-badge"><strong>History ID:</strong> ${result.history_id || 'N/A'}</div>
                                `;
                                document.getElementById('debugContainer').style.display = 'block';
                            }
                            document.getElementById('claudeResponse').innerHTML = renderResponse(result.response);
                            document.getElementById('resultContainer').style.display = 'block';

                            document.getElementById('btnExportPDF').addEventListener('click', async function() {
                                const el = document.getElementById('claudeResponse');
                                if (!el || !el.innerHTML.trim()) { alert('Nenhum conteúdo para exportar'); return; }
                                const orig = this.innerHTML;
                                this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Gerando PDF...';
                                this.disabled = true;
                                try {
                                    const r = await fetch('<?= BASE_URL ?>/api/canvas/export-pdf.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ html: el.innerHTML, filename: 'analise-licitacao-' + Date.now() + '.pdf' }) });
                                    if (!r.ok) throw new Error('Erro ao gerar PDF');
                                    const blob = await r.blob();
                                    const url = window.URL.createObjectURL(blob);
                                    const a = document.createElement('a'); a.href = url; a.download = 'analise-licitacao-' + Date.now() + '.pdf';
                                    document.body.appendChild(a); a.click(); window.URL.revokeObjectURL(url); document.body.removeChild(a);
                                    this.innerHTML = '✅ PDF baixado!';
                                    setTimeout(() => { this.innerHTML = orig; this.disabled = false; }, 2000);
                                } catch (e) { this.innerHTML = '❌ Erro'; setTimeout(() => { this.innerHTML = orig; this.disabled = false; }, 3000); }
                            });

                            document.getElementById('btnCopyText').addEventListener('click', async function() {
                                const el = document.getElementById('claudeResponse');
                                if (!el || !el.innerText.trim()) { alert('Nenhum conteúdo'); return; }
                                const orig = this.innerHTML;
                                try { await navigator.clipboard.writeText(el.innerText); this.innerHTML = '✅ Copiado!'; setTimeout(() => { this.innerHTML = orig; }, 2000); }
                                catch (e) { alert('Erro ao copiar'); }
                            });
                        } else {
                            document.getElementById('errorMessage').textContent = result.error || 'Erro desconhecido';
                            document.getElementById('errorContainer').style.display = 'block';
                        }
                    } catch (error) {
                        document.getElementById('loadingContainer').style.display = 'none';
                        let msg = '';
                        if (error.name === 'AbortError' || error.message === 'TIMEOUT_SERVER') msg = 'A análise demorou mais que o esperado. Tente novamente.';
                        else if (error.message === 'SERVER_HTML_ERROR') msg = 'Problema temporário no servidor. Tente novamente.';
                        else if (error.message === 'Failed to fetch') msg = 'Erro de conexão. Verifique sua internet.';
                        else msg = error.message || 'Erro inesperado.';
                        document.getElementById('errorMessage').textContent = msg;
                        document.getElementById('errorContainer').style.display = 'block';
                    }
                });

            } catch (error) {
                document.getElementById('surveyContainer').innerHTML = '<div class="alert alert-danger">Erro ao inicializar formulário: ' + error.message + '</div>';
            }
        }
    </script>
<?php
};

require __DIR__ . '/../../../src/views/layouts/user.php';
