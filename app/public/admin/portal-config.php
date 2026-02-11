<?php
/**
 * Admin - Configuração do Portal (Nível 0)
 *
 * System prompt genérico cross-vertical + parâmetros API default do portal.
 * Armazenado na tabela settings:
 *   - portal_system_prompt (string) — Nível 0 da hierarquia de system prompts
 *   - portal_api_params (json) — Fallback de parâmetros API para todas as verticais
 *
 * @package Sunyata
 * @since 2026-02-06
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Core\Settings;
use Sunyata\Helpers\VerticalConfig;
use Sunyata\AI\ModelService;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    http_response_code(403);
    die('Acesso negado. Área restrita a administradores.');
}

$db = Database::getInstance();
$settings = Settings::getInstance();
$modelService = ModelService::getInstance();
$availableModels = $modelService->getAvailableModels();
$modelCacheInfo = $modelService->getCacheInfo();
$error = null;
$success = null;

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
    $stats['pending_requests'] = 0;
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_prompt') {
            // Salvar system prompt
            $portalPrompt = trim($_POST['portal_system_prompt'] ?? '');
            $settings->set('portal_system_prompt', $portalPrompt, $_SESSION['user']['id']);
            $success = 'System prompt do portal salvo com sucesso!';

        } elseif ($action === 'save_api_params') {
            // Salvar parâmetros API
            $apiParamsJson = trim($_POST['portal_api_params'] ?? '{}');
            $apiParamsDecoded = json_decode($apiParamsJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'JSON inválido: ' . json_last_error_msg();
            } else {
                // Validar parâmetros (sem system_prompt — contexto canvas)
                $validation = VerticalConfig::validate($apiParamsDecoded, 'canvas');
                if (!$validation['valid']) {
                    $error = 'Erros de validação:<br>' . implode('<br>', $validation['errors']);
                } else {
                    $settings->set('portal_api_params', $apiParamsDecoded, $_SESSION['user']['id']);
                    $success = 'Parâmetros API do portal salvos com sucesso!';
                }
            }
        }
    }
}

// Obter valores atuais
$currentPrompt = $settings->get('portal_system_prompt', '');
$currentApiParams = $settings->get('portal_api_params', []);
if (!is_array($currentApiParams)) {
    $currentApiParams = [];
}

// Hardcoded defaults (fallback final se portal_api_params estiver vazio)
$hardcodedDefaults = [
    'claude_model' => 'claude-haiku-4-5-20251001',
    'temperature' => 1.0,
    'max_tokens' => 4096
];

$pageTitle = 'Configuração do Portal';

require __DIR__ . '/../../src/views/admin-header.php';
?>

<h1><i class="bi bi-globe"></i> Configuração do Portal</h1>
<p class="text-muted">Configurações globais aplicadas a toda a plataforma (Nível 0)</p>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- Documentação inline -->
<div class="alert alert-info">
    <strong><i class="bi bi-info-circle"></i> Hierarquias do Portal:</strong>
    <div class="row mt-2">
        <div class="col-md-6">
            <strong>System Prompts (4 níveis):</strong>
            <ol class="mb-0 mt-1" style="font-size: 0.9rem;">
                <li><strong>Portal (aqui)</strong> — cross-vertical</li>
                <li>Vertical — config + overrides do banco</li>
                <li>Canvas Template — campo system_prompt</li>
                <li>Form Config — ajSystemPrompt</li>
            </ol>
        </div>
        <div class="col-md-6">
            <strong>Parâmetros API (4 níveis):</strong>
            <ol class="mb-0 mt-1" style="font-size: 0.9rem;">
                <li><strong>Portal (aqui)</strong> — fallback global</li>
                <li>Vertical — config/verticals.php</li>
                <li>Vertical — overrides do banco</li>
                <li>Canvas — api_params_override</li>
            </ol>
        </div>
    </div>
</div>

<!-- SEÇÃO 1: System Prompt -->
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_prompt">

    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="bi bi-chat-square-text"></i> 1. System Prompt do Portal</h5>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Prompt genérico aplicado a <strong>todos</strong> os canvas de todas as verticais.
                Ideal para regras gerais: tom de voz, idioma, compliance, identidade da plataforma.
            </p>

            <textarea
                name="portal_system_prompt"
                id="portalPrompt"
                rows="10"
                class="form-control font-monospace"
                style="font-size: 0.9rem;"
                placeholder="Ex: Você é um assistente da Sunyata Consulting. Responda sempre em português brasileiro..."
            ><?= htmlspecialchars($currentPrompt) ?></textarea>

            <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted">
                    <span id="charCount"><?= strlen($currentPrompt) ?></span> caracteres
                </small>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="clearPromptBtn">
                        <i class="bi bi-trash"></i> Limpar
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-save"></i> Salvar Prompt
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- SEÇÃO 2: Parâmetros API -->
<form method="POST" id="apiParamsForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_api_params">

    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="bi bi-sliders"></i> 2. Parâmetros API Padrão</h5>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Defaults globais da API Claude. Se uma vertical não definir um parâmetro, o valor aqui será usado.
                Deixe <code>{}</code> vazio para usar os defaults do sistema.
            </p>

            <div class="row">
                <!-- Coluna Esquerda: Editor JSON -->
                <div class="col-md-6">
                    <label class="form-label"><i class="bi bi-code-square"></i> Parâmetros (JSON)</label>
                    <textarea
                        name="portal_api_params"
                        id="apiParamsTextarea"
                        rows="10"
                        class="form-control font-monospace"
                        style="font-size: 0.9rem;"
                    ><?= htmlspecialchars(json_encode($currentApiParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}') ?></textarea>

                    <div class="mt-2">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-save"></i> Salvar Parâmetros
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="resetApiBtn">
                            <i class="bi bi-arrow-counterclockwise"></i> Resetar
                        </button>
                    </div>
                </div>

                <!-- Coluna Direita: Preview + Docs -->
                <div class="col-md-6">
                    <label class="form-label"><i class="bi bi-eye"></i> Preview: Defaults Efetivos</label>
                    <pre id="apiParamsPreview" class="bg-light p-3 border rounded" style="max-height: 250px; overflow-y: auto; font-size: 0.85rem;"></pre>

                    <!-- Parâmetros Disponíveis (partial reutilizável) -->
                    <div class="mt-3">
                        <?php include __DIR__ . '/../../src/views/partials/api-params-docs.php'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
(function() {
    // === System Prompt ===
    const promptTextarea = document.getElementById('portalPrompt');
    const charCount = document.getElementById('charCount');
    const clearPromptBtn = document.getElementById('clearPromptBtn');

    promptTextarea.addEventListener('input', function() {
        charCount.textContent = this.value.length;
    });

    clearPromptBtn.addEventListener('click', function() {
        if (confirm('Limpar o system prompt do portal? Isso afetará todos os canvas.')) {
            promptTextarea.value = '';
            charCount.textContent = '0';
        }
    });

    // === API Params ===
    const hardcodedDefaults = <?= json_encode($hardcodedDefaults, JSON_UNESCAPED_UNICODE) ?>;
    const apiTextarea = document.getElementById('apiParamsTextarea');
    const apiPreview = document.getElementById('apiParamsPreview');
    const resetApiBtn = document.getElementById('resetApiBtn');

    function updateApiPreview() {
        try {
            const overrides = JSON.parse(apiTextarea.value || '{}');
            const effective = {...hardcodedDefaults, ...overrides};
            apiPreview.textContent = JSON.stringify(effective, null, 2);
            apiPreview.classList.remove('border-danger');
            apiPreview.classList.add('border-success');
        } catch (e) {
            apiPreview.textContent = 'JSON inválido: ' + e.message;
            apiPreview.classList.remove('border-success');
            apiPreview.classList.add('border-danger');
        }
    }

    apiTextarea.addEventListener('input', updateApiPreview);
    updateApiPreview();

    resetApiBtn.addEventListener('click', function() {
        if (confirm('Resetar parâmetros API para defaults do sistema?')) {
            apiTextarea.value = '{}';
            updateApiPreview();
        }
    });

    // Refresh de modelos: agora no partial api-params-docs.php
})();
</script>

<?php require __DIR__ . '/../../src/views/admin-footer.php'; ?>
