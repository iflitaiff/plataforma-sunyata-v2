<?php
/**
 * Configuração Monitor PNCP — SurveyJS form for keyword-based PNCP monitoring.
 *
 * This is a custom page (not standard canvas submit) because it calls the
 * PNCP API directly instead of going through Claude AI.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

// Access check
$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

if ($user_vertical !== 'iatr' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Load form_config from canvas_templates
use Sunyata\Core\Database;
$db = Database::getInstance();
$canvas = $db->fetchOne("
    SELECT id, form_config FROM canvas_templates
    WHERE slug = 'licitacoes-monitor-pncp-config' AND is_active = TRUE
");

$formConfig = null;
if ($canvas) {
    $formConfig = $canvas['form_config'];
    if (is_string($formConfig)) {
        $formConfig = json_decode($formConfig, true);
    }
}

$pageTitle = 'Configuração Monitor PNCP';
$activeNav = 'iatr';

$headExtra = '
<link rel="stylesheet" href="https://unpkg.com/survey-core/defaultV2.min.css">
<link rel="stylesheet" href="' . BASE_URL . '/assets/css/surveyjs-tabler.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
';

$pageContent = function () use ($formConfig) {
?>
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <a href="<?= BASE_URL ?>/areas/iatr/" class="btn btn-ghost-primary btn-sm mb-2">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
                <h2 class="page-title">Configuração Monitor PNCP</h2>
                <div class="text-secondary mt-1">Configure palavras-chave e filtros para busca automática de licitações</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <?php if (!$formConfig): ?>
                        <div class="alert alert-warning">Formulário não configurado. Contate o administrador.</div>
                    <?php else: ?>
                        <div id="surveyContainer"></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Results Area -->
            <div id="result-area" class="card mt-4" style="display: none;">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title" id="result-title">Resultados</h3>
                    <span class="badge bg-primary" id="result-count"></span>
                </div>
                <div class="card-body p-0" id="result-content"></div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Como funciona</h3></div>
                <div class="card-body">
                    <ol class="mb-0">
                        <li class="mb-2">Selecione as <strong>palavras-chave</strong> de interesse</li>
                        <li class="mb-2">Escolha as <strong>UFs</strong> alvo</li>
                        <li class="mb-2">Ajuste <strong>filtros</strong> opcionais</li>
                        <li class="mb-2">Configure <strong>notificações</strong> por email</li>
                        <li>Clique em <strong>Executar</strong></li>
                    </ol>
                    <hr>
                    <p class="text-secondary small mb-0">
                        A busca expande automaticamente singular/plural de cada termo.
                        Ex: "Computador(es)" busca "computador" E "computadores".
                    </p>
                </div>
            </div>
        </div>
    </div>

<?php if ($formConfig): ?>
<!-- SurveyJS Scripts -->
<script src="https://unpkg.com/survey-core/survey.core.min.js"></script>
<script src="https://unpkg.com/survey-js-ui/survey-js-ui.min.js"></script>

<script>
(function() {
    const MONITOR_URL = '<?= BASE_URL ?>/api/legal/pncp-monitor.php';
    const CSRF_TOKEN = '<?= csrf_token() ?>';

    const surveyJson = <?= json_encode($formConfig, JSON_UNESCAPED_UNICODE) ?>;
    const survey = new Survey.Model(surveyJson);

    survey.onComplete.add(async function(sender) {
        const data = sender.data;
        const resultArea = document.getElementById('result-area');
        const resultContent = document.getElementById('result-content');
        const resultTitle = document.getElementById('result-title');
        const resultCount = document.getElementById('result-count');

        resultArea.style.display = 'block';
        resultArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
        resultContent.innerHTML = '<div class="text-center p-4"><span class="spinner-border spinner-border-sm"></span> Consultando PNCP...</div>';

        try {
            const resp = await fetch(MONITOR_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify(data)
            });
            const result = await resp.json();

            if (!result.success) {
                resultContent.innerHTML = '<div class="alert alert-danger m-3">' + escapeHtml(result.error || 'Erro desconhecido') + '</div>';
                return;
            }

            resultTitle.textContent = 'Resultados — ' + (result.keywords_display || []).join(', ');
            resultCount.textContent = result.total + ' encontrado(s)';

            if (!result.items || result.items.length === 0) {
                resultContent.innerHTML = '<div class="text-center p-4 text-secondary"><i class="bi bi-inbox" style="font-size:2rem;"></i><p class="mt-2">Nenhum edital encontrado para os critérios selecionados.</p></div>';
                return;
            }

            let html = '<div class="table-responsive"><table class="table table-vcenter card-table">';
            html += '<thead><tr><th>Edital</th><th>Órgão / UF</th><th>Valor</th><th>Status</th><th></th></tr></thead><tbody>';

            result.items.forEach(function(item) {
                const valor = item.valor_estimado
                    ? 'R$ ' + Number(item.valor_estimado).toLocaleString('pt-BR', {minimumFractionDigits: 2})
                    : 'Sigiloso';
                const statusClass = (item.status || '').toLowerCase().includes('divulgad') ? 'bg-success' : 'bg-secondary';
                const data = item.data_publicacao ? new Date(item.data_publicacao).toLocaleDateString('pt-BR') : '-';

                html += '<tr>';
                html += '<td><div class="fw-bold">' + escapeHtml(item.titulo || item.objeto || 'Sem título') + '</div><small class="text-secondary">' + data + '</small></td>';
                html += '<td><small>' + escapeHtml(item.orgao || '-') + '</small><br><span class="badge bg-azure-lt">' + escapeHtml(item.uf || '-') + '</span></td>';
                html += '<td class="fw-bold text-primary">' + valor + '</td>';
                html += '<td><span class="badge ' + statusClass + '">' + escapeHtml(item.status || '-') + '</span></td>';
                html += '<td>';
                if (item.url_pncp) {
                    html += '<a href="' + escapeHtml(item.url_pncp) + '" target="_blank" class="btn btn-sm btn-outline-primary" hx-boost="false"><i class="bi bi-box-arrow-up-right"></i></a>';
                }
                html += '</td></tr>';
            });

            html += '</tbody></table></div>';

            if (result.query_used) {
                html += '<div class="card-footer text-secondary small"><strong>Query:</strong> ' + escapeHtml(result.query_used.substring(0, 200)) + '</div>';
            }

            resultContent.innerHTML = html;

        } catch (err) {
            resultContent.innerHTML = '<div class="alert alert-danger m-3">Erro de conexão: ' + escapeHtml(err.message) + '</div>';
        }
    });

    survey.render(document.getElementById('surveyContainer'));

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>
<?php endif; ?>

<?php
};

require __DIR__ . '/../../../src/views/layouts/user.php';
