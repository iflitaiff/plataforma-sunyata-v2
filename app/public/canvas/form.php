<?php
/**
 * Universal Canvas Form — single entry point for all SurveyJS forms.
 *
 * Usage: /canvas/form.php?template=slug
 * Replaces 15+ per-vertical formulario.php files.
 *
 * Features:
 * - SurveyJS renderer from canvas_template form_config
 * - SSE streaming responses via canvas-stream.js
 * - Session reuse panel (last N submissions from user_submissions)
 * - Document picker integration
 * - localStorage draft autosave
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

use Sunyata\Core\Database;
use Sunyata\Core\Settings;

$templateSlug = $_GET['template'] ?? null;
$canvasId = $_GET['id'] ?? null;

if (!$templateSlug && !$canvasId) {
    $_SESSION['error'] = 'Template nao especificado.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

// Load canvas template
if ($canvasId) {
    $canvas = $db->fetchOne("
        SELECT id, slug, name, vertical, description, icon, color, form_config,
               system_prompt, user_prompt_template, max_questions, api_params_override
        FROM canvas_templates
        WHERE id = :id AND is_active = TRUE AND status = 'published'
    ", ['id' => (int)$canvasId]);
} else {
    $canvas = $db->fetchOne("
        SELECT id, slug, name, vertical, description, icon, color, form_config,
               system_prompt, user_prompt_template, max_questions, api_params_override
        FROM canvas_templates
        WHERE slug = :slug AND is_active = TRUE AND status = 'published'
    ", ['slug' => $templateSlug]);
}

if (!$canvas) {
    $_SESSION['error'] = 'Canvas nao encontrado ou inativo.';
    redirect(BASE_URL . '/dashboard.php');
}

$formConfig = $canvas['form_config'];
if (is_string($formConfig)) {
    $formConfig = json_decode($formConfig, true);
}

// Check if streaming is available
$aiServiceMode = Settings::getInstance()->get('ai_service_mode', 'direct');
$streamingEnabled = ($aiServiceMode === 'microservice');

$pageTitle = $canvas['name'];
$activeNav = $canvas['slug'];

$headExtra = '
<link rel="stylesheet" href="https://unpkg.com/survey-core/defaultV2.min.css">
<link rel="stylesheet" href="' . BASE_URL . '/assets/css/surveyjs-tabler.css">
';

$pageContent = function () use ($canvas, $formConfig, $streamingEnabled) {
    $canvasJson = htmlspecialchars(json_encode($formConfig, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    $canvasId = $canvas['id'];
    $canvasSlug = $canvas['slug'];
?>

<?php
$pageHeaderTitle = $canvas['name'];
$pageHeaderPretitle = htmlspecialchars($canvas['vertical']);
include __DIR__ . '/../../src/views/components/page-header.php';
?>

<div class="row g-4">
    <!-- Form Column -->
    <div class="col-lg-8">
        <div class="card">
            <?php if (!empty($canvas['description'])): ?>
            <div class="card-header">
                <div class="card-title"><?= htmlspecialchars($canvas['description']) ?></div>
            </div>
            <?php endif; ?>
            <div class="card-body">
                <div id="surveyContainer"></div>
            </div>
        </div>

        <!-- Result Area (hidden until submission) -->
        <div id="result-area" class="card mt-4" style="display: none;">
            <div class="card-header">
                <h3 class="card-title">Resultado</h3>
            </div>
            <div class="card-body">
                <div id="stream-status" class="text-secondary mb-3" style="display: none;">
                    <span class="spinner-border spinner-border-sm me-2"></span>
                    <span></span>
                </div>
                <div id="stream-result" class="stream-result"></div>
                <div id="stream-actions" style="display: none;"></div>
            </div>
        </div>
    </div>

    <!-- Sidebar Column -->
    <div class="col-lg-4">
        <!-- Previous Sessions Panel -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Sessoes Anteriores</h3>
            </div>
            <div class="card-body p-0"
                 hx-get="<?= BASE_URL ?>/api/submissions/recent.php?canvas_id=<?= $canvasId ?>&limit=5"
                 hx-trigger="load"
                 hx-swap="innerHTML">
                <div class="text-center p-3 text-secondary">
                    <span class="spinner-border spinner-border-sm"></span> Carregando...
                </div>
            </div>
        </div>

        <!-- Draft Info -->
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <i class="ti ti-device-floppy text-secondary me-2"></i>
                    <span id="draft-status" class="text-secondary small">Rascunho salvo automaticamente</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SurveyJS Scripts -->
<script src="https://unpkg.com/survey-core/survey.core.min.js"></script>
<script src="https://unpkg.com/survey-js-ui/survey-js-ui.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/canvas-stream.js"></script>
<script src="<?= BASE_URL ?>/assets/js/document-picker.js"></script>

<script>
(function() {
    window.SUNYATA_BASE_URL = '<?= BASE_URL ?>';
    const CANVAS_ID = <?= $canvasId ?>;
    const CANVAS_SLUG = '<?= htmlspecialchars($canvasSlug) ?>';
    const SUBMIT_URL = '<?= BASE_URL ?>/api/canvas/submit.php';
    const DRAFT_KEY = 'sunyata_draft_' + CANVAS_SLUG;
    const STREAMING = <?= $streamingEnabled ? 'true' : 'false' ?>;

    // Initialize SurveyJS
    const surveyJson = <?= json_encode($formConfig, JSON_UNESCAPED_UNICODE) ?>;
    const survey = new Survey.Model(surveyJson);

    // Restore draft from localStorage
    const savedDraft = localStorage.getItem(DRAFT_KEY);
    if (savedDraft) {
        try {
            const draftData = JSON.parse(savedDraft);
            survey.data = draftData;
            document.getElementById('draft-status').textContent = 'Rascunho restaurado';
        } catch (e) { /* ignore */ }
    }

    // Autosave draft on value change
    survey.onValueChanged.add(function(sender) {
        localStorage.setItem(DRAFT_KEY, JSON.stringify(sender.data));
        document.getElementById('draft-status').textContent =
            'Rascunho salvo ' + new Date().toLocaleTimeString('pt-BR');
    });

    // Initialize stream handler
    canvasStream = new CanvasStream({
        resultContainer: document.getElementById('stream-result'),
        statusContainer: document.getElementById('stream-status'),
        actionsContainer: document.getElementById('stream-actions'),
        onComplete: function(result) {
            // Clear draft after successful submission
            localStorage.removeItem(DRAFT_KEY);
            document.getElementById('draft-status').textContent = 'Rascunho limpo (submetido com sucesso)';
        }
    });

    // Handle form completion
    survey.onComplete.add(async function(sender) {
        const resultArea = document.getElementById('result-area');
        resultArea.style.display = 'block';
        resultArea.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Build plain data with display values
        const plainData = [];
        survey.getAllQuestions().forEach(function(q) {
            if (q.getType() === 'html') return;
            plainData.push({
                name: q.name,
                value: q.value,
                displayValue: q.displayValue,
                title: q.title,
                type: q.getType()
            });
        });

        const payload = {
            canvas_id: CANVAS_ID,
            form_data: sender.data,
            plain_data: plainData
        };

        if (STREAMING) {
            await canvasStream.start(SUBMIT_URL, payload);
        } else {
            // Sync fallback
            canvasStream.showStatus('Gerando resposta...');
            try {
                const resp = await fetch(SUBMIT_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await resp.json();
                if (data.success) {
                    canvasStream.renderSync(data);
                } else {
                    canvasStream.handleError(new Error(data.error || 'Erro desconhecido'));
                }
            } catch (err) {
                canvasStream.handleError(err);
            }
        }
    });

    // Render survey
    survey.render(document.getElementById('surveyContainer'));

    // Initialize document picker for file fields
    if (typeof SunyataDocPicker !== 'undefined') {
        survey.onAfterRenderQuestion.add(function() {
            SunyataDocPicker.init(survey);
        });
        SunyataDocPicker.init(survey);
    }

    // Load session data handler (called from HTMX-loaded panel)
    window.loadSessionData = function(submissionId) {
        fetch('<?= BASE_URL ?>/api/submissions/data.php?id=' + submissionId)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.form_data) {
                    survey.data = data.form_data;
                    survey.render(document.getElementById('surveyContainer'));
                    document.getElementById('draft-status').textContent = 'Dados carregados da sessao anterior';
                }
            });
    };
})();
</script>

<?php
};

include __DIR__ . '/../../src/views/layouts/user.php';
