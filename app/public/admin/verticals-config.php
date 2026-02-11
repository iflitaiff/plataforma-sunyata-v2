<?php
/**
 * Admin - Configuração de Parâmetros de Verticais
 *
 * Sistema híbrido: arquivo config/verticals.php (defaults) + DB overrides (editável via UI)
 *
 * @package Sunyata
 * @since 2025-12-17 (Canvas MVP - Sprint 1)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Helpers\VerticalConfig;
use Sunyata\AI\ModelService;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    http_response_code(403);
    die('Acesso negado. Área restrita a administradores.');
}

$db = Database::getInstance();
$modelService = ModelService::getInstance();
$availableModels = $modelService->getAvailableModels();
$modelCacheInfo = $modelService->getCacheInfo();
$error = null;
$success = null;

// Obter vertical_id da URL
$vertical_id = $_GET['id'] ?? null;

if (!$vertical_id) {
    // Listar verticais disponíveis
    $verticals = $db->fetchAll("SELECT id, slug, name FROM verticals WHERE is_active = TRUE ORDER BY name");
    $pageTitle = 'Configuração de Verticais';
} else {
    // Buscar vertical específica
    $vertical = $db->fetchOne("SELECT * FROM verticals WHERE id = :id", [':id' => $vertical_id]);

    if (!$vertical) {
        die('Vertical não encontrada.');
    }

    $pageTitle = 'Configurar Parâmetros - ' . $vertical['name'];

    // Processar formulário
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $config_json = $_POST['config'] ?? '{}';

        // Parse JSON
        $config_array = json_decode($config_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = 'JSON inválido: ' . json_last_error_msg();
        } else {
            // Validar parâmetros
            $validation = VerticalConfig::validate($config_array);

            if (!$validation['valid']) {
                $error = 'Erros de validação:<br>' . implode('<br>', $validation['errors']);
            } else {
                // Salvar
                if (VerticalConfig::saveOverrides($vertical['slug'], $config_array)) {
                    $success = 'Configuração salva com sucesso!';
                    // Recarregar vertical para pegar novos valores
                    $vertical = $db->fetchOne("SELECT * FROM verticals WHERE id = :id", [':id' => $vertical_id]);
                } else {
                    $error = 'Erro ao salvar configuração no banco de dados.';
                }
            }
        }
    }

    // Obter defaults do arquivo e overrides atuais
    $file_defaults = VerticalConfig::getFileDefaults($vertical['slug']);

    try {
        $db_overrides = VerticalConfig::getDatabaseOverrides($vertical['slug']);
        $effective_config = VerticalConfig::get($vertical['slug']);
    } catch (\Exception $e) {
        $error = 'Erro crítico ao carregar configuração: ' . $e->getMessage();
        $db_overrides = [];
        $effective_config = $file_defaults; // Fallback para defaults do arquivo
    }
}

require __DIR__ . '/../../src/views/admin-header.php';
?>

<?php if (!$vertical_id): ?>
    <!-- Listagem de Verticais -->
    <h1><i class="bi bi-sliders"></i> Configuração de Parâmetros</h1>
    <p class="text-muted">Escolha uma vertical para configurar seus parâmetros da API Claude</p>

    <div class="row mt-4">
        <?php foreach ($verticals as $v): ?>
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><?= htmlspecialchars($v['name']) ?></h5>
                        <p class="text-muted mb-2">
                            <small><code><?= htmlspecialchars($v['slug']) ?></code></small>
                        </p>
                        <a href="?id=<?= $v['id'] ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-gear"></i> Configurar
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <!-- Formulário de Configuração -->
    <div class="mb-3">
        <a href="verticals-config.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Voltar
        </a>
    </div>

    <h1><i class="bi bi-sliders"></i> <?= htmlspecialchars($vertical['name']) ?></h1>
    <p class="text-muted"><code><?= htmlspecialchars($vertical['slug']) ?></code></p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <!-- Documentação inline -->
    <div class="alert alert-info">
        <strong><i class="bi bi-info-circle"></i> Como funciona:</strong>
        <ul class="mb-0 mt-2">
            <li><strong>Defaults do arquivo</strong> (<code>config/verticals.php</code>): Parâmetros seguros versionados no Git</li>
            <li><strong>Overrides do banco</strong> (este formulário): Apenas o que você quiser alterar</li>
            <li><strong>Resultado final</strong>: Merge automático (overrides sobrescrevem defaults)</li>
        </ul>
    </div>

    <form method="POST" id="configForm">
        <div class="row">
            <!-- Coluna Esquerda: Editor JSON -->
            <div class="col-md-6">
                <h5><i class="bi bi-code-square"></i> Overrides (JSON)</h5>
                <p class="text-muted small">
                    Edite apenas os parâmetros que deseja alterar. Deixe <code>{}</code> vazio para usar defaults do arquivo.
                </p>

                <textarea
                    name="config"
                    id="configTextarea"
                    rows="15"
                    class="form-control font-monospace"
                    style="font-size: 0.9rem;"
                ><?= htmlspecialchars(json_encode($db_overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Salvar Configuração
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="resetBtn">
                        <i class="bi bi-arrow-counterclockwise"></i> Resetar para Defaults
                    </button>
                </div>
            </div>

            <!-- Coluna Direita: Preview + Docs -->
            <div class="col-md-6">
                <!-- Preview em tempo real -->
                <h5><i class="bi bi-eye"></i> Preview: Parâmetros Efetivos</h5>
                <p class="text-muted small">Resultado do merge (arquivo + seus overrides)</p>

                <pre id="effectivePreview" class="bg-light p-3 border rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.85rem;"></pre>

                <!-- Documentação de parâmetros (partial reutilizável) -->
                <div class="mt-4">
                    <?php include __DIR__ . '/../../src/views/partials/api-params-docs.php'; ?>
                </div>

                <div class="card mt-3">
                    <div class="card-body py-2" style="font-size: 0.85rem;">
                        <dt><code>system_prompt</code> <span class="badge bg-secondary">string</span></dt>
                        <dd class="mb-0">Prompt de sistema que define comportamento do assistente (Nível 1 da hierarquia)</dd>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <script>
        // Dados do arquivo (defaults)
        const fileDefaults = <?= json_encode($file_defaults, JSON_UNESCAPED_UNICODE) ?>;

        // Textarea e preview
        const textarea = document.getElementById('configTextarea');
        const preview = document.getElementById('effectivePreview');
        const resetBtn = document.getElementById('resetBtn');

        // Atualizar preview em tempo real
        function updatePreview() {
            try {
                const overrides = JSON.parse(textarea.value || '{}');
                const effective = {...fileDefaults, ...overrides};
                preview.textContent = JSON.stringify(effective, null, 2);
                preview.classList.remove('border-danger');
                preview.classList.add('border-success');
            } catch (e) {
                preview.textContent = '❌ JSON inválido: ' + e.message;
                preview.classList.remove('border-success');
                preview.classList.add('border-danger');
            }
        }

        // Reset para defaults
        resetBtn.addEventListener('click', () => {
            if (confirm('Resetar para defaults do arquivo? Isso apagará todos os overrides.')) {
                textarea.value = '{}';
                updatePreview();
            }
        });

        // Atualizar preview ao digitar
        textarea.addEventListener('input', updatePreview);

        // Preview inicial
        updatePreview();

        // Modelos disponíveis (para referência no editor)
        const availableModels = <?= json_encode(array_column($availableModels, 'id')) ?>;

        // Refresh de modelos: agora no partial api-params-docs.php
    </script>

<?php endif; ?>

<?php require __DIR__ . '/../../src/views/admin-footer.php'; ?>
