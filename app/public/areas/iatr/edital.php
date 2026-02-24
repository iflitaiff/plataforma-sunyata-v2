<?php
/**
 * Detalhe do Edital PNCP
 *
 * Exibe informações do edital armazenado na tabela pncp_editais.
 * Permite disparar análise IA via webhook N8N e faz polling do resultado.
 *
 * GET /iatr/edital.php?id={id}           — visualizar edital
 * GET /iatr/edital.php?id={id}&acao=analise — abrir e disparar análise automaticamente
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

if (!in_array($user_vertical, ['iatr', 'licitacoes']) && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Validate ID or pncp_id
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$pncp_id = $_GET['pncp_id'] ?? null;

$db = Database::getInstance();

if ($id) {
    $edital = $db->fetchOne("SELECT * FROM pncp_editais WHERE id = ?", [$id]);
} elseif ($pncp_id) {
    $edital = $db->fetchOne("SELECT * FROM pncp_editais WHERE pncp_id = ?", [$pncp_id]);
} else {
    $_SESSION['error'] = 'ID do edital inválido';
    redirect(BASE_URL . '/areas/iatr/');
}

if (!$edital) {
    $_SESSION['error'] = 'Edital não encontrado';
    redirect(BASE_URL . '/areas/iatr/');
}

$autoAnalise = ($_GET['acao'] ?? '') === 'analise';

$pageTitle = 'Edital #' . $edital['id'];
$activeNav = 'iatr';

$headExtra = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
    .edital-header { border-bottom: 3px solid #667eea; padding-bottom: 1rem; margin-bottom: 1.5rem; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; }
    .info-item { background: #f8f9fa; border-radius: 8px; padding: 0.75rem 1rem; }
    .info-label { font-size: 0.8rem; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; }
    .info-value { font-size: 1rem; font-weight: 500; color: #1e293b; margin-top: 0.25rem; }
    .info-value.valor { color: #667eea; font-size: 1.2rem; font-weight: 700; }
    .status-badge { font-size: 0.85rem; padding: 0.35em 0.65em; }
    .analise-card { border: 2px solid #e6e8eb; border-radius: 8px; }
    .analise-card.concluida { border-color: #28a745; }
    .analise-card.erro { border-color: #dc3545; }
    .analise-card.em-andamento { border-color: #ffc107; }
    .analise-resultado { line-height: 1.7; }
    .analise-resultado table { width: 100%; border-collapse: collapse; margin: 1em 0; font-size: 0.9em; }
    .analise-resultado th, .analise-resultado td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; }
    .analise-resultado th { background: #f1f3f5; font-weight: 600; }
    .analise-resultado tr:nth-child(even) { background: #f8f9fa; }
    .analise-resultado h1, .analise-resultado h2, .analise-resultado h3 { margin-top: 1.5em; margin-bottom: 0.5em; color: #1a2a3a; }
    .analise-resultado h1 { font-size: 1.4em; border-bottom: 2px solid #3498db; padding-bottom: 0.3em; }
    .analise-resultado h2 { font-size: 1.2em; }
    .analise-resultado h3 { font-size: 1.05em; }
    .analise-resultado hr { margin: 1.5em 0; border: none; border-top: 1px solid #dee2e6; }
    .analise-resultado ul, .analise-resultado ol { padding-left: 1.5em; }
    .analise-resultado blockquote { border-left: 3px solid #3498db; padding-left: 1em; color: #666; margin: 1em 0; }
    .polling-indicator { display: inline-flex; align-items: center; gap: 0.5rem; }
    .pulse-dot { width: 10px; height: 10px; border-radius: 50%; background: #ffc107; animation: pulse 1.5s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
    /* DataJud styles */
    .datajud-card { border: 2px solid #e6e8eb; border-radius: 8px; }
    .datajud-card.loaded { border-color: #6f42c1; }
    .datajud-card.empty { border-color: #28a745; }
    .classe-badge { display: inline-flex; flex-direction: column; align-items: center; background: #f8f9fa; border-radius: 8px; padding: 0.5rem 1rem; min-width: 120px; }
    .classe-badge .count { font-size: 1.5rem; font-weight: 700; color: #6f42c1; }
    .classe-badge .label { font-size: 0.75rem; color: #64748b; text-align: center; }
    .alerta-critico { border-left: 4px solid #dc3545; background: #fff5f5; padding: 0.75rem 1rem; margin-bottom: 0.5rem; border-radius: 0 4px 4px 0; }
    .alerta-atencao { border-left: 4px solid #ffc107; background: #fffdf0; padding: 0.75rem 1rem; margin-bottom: 0.5rem; border-radius: 0 4px 4px 0; }
    .alerta-info { border-left: 4px solid #0d6efd; background: #f0f7ff; padding: 0.75rem 1rem; margin-bottom: 0.5rem; border-radius: 0 4px 4px 0; }
    .cnpj-input { font-family: monospace; letter-spacing: 0.05em; }
    .processo-table { font-size: 0.85rem; }
    .processo-table th { background: #f1f3f5; font-weight: 600; white-space: nowrap; }
    .bg-purple { background-color: #6f42c1; color: #fff; }
</style>
HTML;

$pageContent = function () use ($edital, $autoAnalise) {
    $csrfToken = csrf_token();
    $statusClass = match($edital['status']) {
        'aberto' => 'bg-success',
        'encerrado' => 'bg-secondary',
        'suspenso' => 'bg-warning text-dark',
        default => 'bg-info'
    };
    $valorFormatado = $edital['valor_estimado']
        ? 'R$ ' . number_format((float)$edital['valor_estimado'], 2, ',', '.')
        : 'Valor sigiloso';
    $dataAbertura = $edital['data_abertura']
        ? (new DateTime($edital['data_abertura']))->format('d/m/Y H:i')
        : 'N/A';
    $dataEncerramento = $edital['data_encerramento']
        ? (new DateTime($edital['data_encerramento']))->format('d/m/Y H:i')
        : 'N/A';
    $keywords = $edital['keywords_matched']
        ? implode(', ', json_decode($edital['keywords_matched'], true) ?: [])
        : '';
?>
    <!-- Back + Header -->
    <div class="page-header mb-3">
        <a href="<?= BASE_URL ?>/areas/iatr/monitor-pncp.php" class="btn btn-ghost-primary btn-sm mb-2">
            <i class="bi bi-arrow-left"></i> Voltar ao Monitor
        </a>
        <div class="edital-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <span class="badge <?= $statusClass ?> status-badge me-2"><?= sanitize_output($edital['status']) ?></span>
                    <?php if ($edital['modalidade']): ?>
                        <span class="badge bg-info status-badge"><?= sanitize_output($edital['modalidade']) ?></span>
                    <?php endif; ?>
                    <?php if ($edital['numero']): ?>
                        <span class="badge bg-light text-dark status-badge"><?= sanitize_output($edital['numero']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <span class="info-value valor"><?= $valorFormatado ?></span>
                </div>
            </div>
            <h3 class="mt-2 mb-0"><?= sanitize_output($edital['titulo']) ?></h3>
        </div>
    </div>

    <!-- Info Grid -->
    <div class="info-grid mb-4">
        <div class="info-item">
            <div class="info-label"><i class="bi bi-building"></i> Órgão</div>
            <div class="info-value"><?= sanitize_output($edital['orgao'] ?: 'N/A') ?></div>
        </div>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-geo-alt"></i> UF / Município</div>
            <div class="info-value"><?= sanitize_output(($edital['uf'] ?: '') . ($edital['municipio'] ? ' - ' . $edital['municipio'] : '')) ?: 'N/A' ?></div>
        </div>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-calendar-event"></i> Abertura</div>
            <div class="info-value"><?= $dataAbertura ?></div>
        </div>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-calendar-x"></i> Encerramento</div>
            <div class="info-value"><?= $dataEncerramento ?></div>
        </div>
        <?php if ($edital['orgao_cnpj']): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-card-text"></i> CNPJ</div>
            <div class="info-value"><?= sanitize_output($edital['orgao_cnpj']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($keywords): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-tags"></i> Keywords</div>
            <div class="info-value"><?= sanitize_output($keywords) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Objeto -->
    <?php if ($edital['objeto']): ?>
    <div class="card mb-4">
        <div class="card-header"><h4 class="card-title mb-0"><i class="bi bi-file-text"></i> Objeto</h4></div>
        <div class="card-body">
            <p style="white-space: pre-wrap;"><?= sanitize_output($edital['objeto']) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Links -->
    <div class="d-flex gap-2 mb-4">
        <?php if ($edital['url_pncp']): ?>
        <a href="<?= sanitize_output($edital['url_pncp']) ?>" target="_blank" class="btn btn-outline-primary">
            <i class="bi bi-box-arrow-up-right"></i> Ver no PNCP
        </a>
        <?php endif; ?>
    </div>

    <!-- DataJud: Historico Judicial do Orgao (Feature 1) -->
    <div class="card datajud-card mb-4" id="datajud-orgao-section">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h4 class="card-title mb-0"><i class="bi bi-briefcase"></i> Hist&oacute;rico Judicial do &Oacute;rg&atilde;o</h4>
                <small class="text-muted"><?= sanitize_output($edital['orgao'] ?: 'Órgão não identificado') ?></small>
            </div>
            <div id="datajud-orgao-status"></div>
        </div>
        <div class="card-body" id="datajud-orgao-body">
            <p class="text-muted small mb-3">
                <i class="bi bi-info-circle"></i>
                Consulta processos judiciais registrados no <strong>DataJud (CNJ)</strong> envolvendo o CNPJ do &oacute;rg&atilde;o licitante
                nos tribunais relevantes para o estado. Ajuda a avaliar riscos como execu&ccedil;&otilde;es fiscais, fal&ecirc;ncias e recupera&ccedil;&atilde;o judicial.
            </p>
            <div class="text-center py-3 text-secondary">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <span class="ms-2">Consultando hist&oacute;rico judicial...</span>
            </div>
        </div>
    </div>

    <!-- DataJud: Verificacao de Idoneidade (Feature 3) -->
    <div class="card datajud-card mb-4" id="datajud-empresa-section">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h4 class="card-title mb-0"><i class="bi bi-shield-check"></i> Verificar Idoneidade de Empresa</h4>
                <small class="text-muted">Consulta judicial por CNPJ</small>
            </div>
            <div id="datajud-empresa-status"></div>
        </div>
        <div class="card-body" id="datajud-empresa-body">
            <p class="text-muted small mb-3">
                <i class="bi bi-info-circle"></i>
                Pesquisa no <strong>DataJud (CNJ)</strong> se a empresa possui processos judiciais que possam afetar sua
                habilita&ccedil;&atilde;o na licita&ccedil;&atilde;o: fal&ecirc;ncia, recupera&ccedil;&atilde;o judicial, execu&ccedil;&otilde;es fiscais, etc.
            </p>
            <div class="row align-items-end g-2">
                <div class="col-auto" style="min-width:220px;">
                    <label class="form-label">CNPJ da Empresa</label>
                    <input type="text" id="cnpj-empresa" class="form-control cnpj-input"
                           placeholder="00.000.000/0000-00" maxlength="18">
                </div>
                <div class="col-auto" style="min-width:200px;">
                    <label class="form-label">Nome da Empresa <small class="text-muted">(opcional)</small></label>
                    <input type="text" id="nome-empresa" class="form-control"
                           placeholder="Raz&atilde;o social">
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary" id="btn-verificar-idoneidade" onclick="verificarIdoneidade()">
                        <i class="bi bi-search"></i> Consultar
                    </button>
                </div>
            </div>
            <div id="datajud-empresa-resultado" class="mt-3"></div>
        </div>
    </div>

    <!-- AI Analysis Section -->
    <div class="card analise-card mb-4" id="analise-section">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="card-title mb-0"><i class="bi bi-robot"></i> Análise com IA</h4>
            <div id="analise-status-indicator"></div>
        </div>
        <div class="card-body" id="analise-body">
            <!-- Filled by JS -->
            <div class="text-center py-3 text-secondary">
                <i class="bi bi-robot" style="font-size: 2rem;"></i>
                <p class="mt-2">Clique no botão abaixo para solicitar análise inteligente deste edital.</p>
                <button class="btn btn-primary" id="btn-analisar" onclick="triggerAnalise()">
                    <i class="bi bi-lightning"></i> Analisar com IA
                </button>
            </div>
        </div>
    </div>

<script>
const EDITAL_ID = <?= (int) $edital['id'] ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const STATUS_URL = <?= json_encode(BASE_URL . '/api/pncp/analise-status.php') ?>;
const TRIGGER_URL = <?= json_encode(BASE_URL . '/api/pncp/trigger-analise.php') ?>;
const AUTO_ANALISE = <?= $autoAnalise ? 'true' : 'false' ?>;

let pollingInterval = null;
let currentStatus = <?= json_encode($edital['status_analise']) ?>;

// Initialize based on current status
document.addEventListener('DOMContentLoaded', function() {
    if (currentStatus === 'concluida' || currentStatus === 'erro') {
        loadAnaliseResult();
    } else if (currentStatus === 'em_analise') {
        showPolling();
        startPolling();
    } else if (AUTO_ANALISE) {
        triggerAnalise();
    }
});

async function triggerAnalise() {
    const btn = document.getElementById('btn-analisar');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';

    try {
        const resp = await fetch(TRIGGER_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify({ edital_id: EDITAL_ID })
        });

        if (!resp.ok) {
            throw new Error('Webhook retornou HTTP ' + resp.status);
        }

        showPolling();
        startPolling();

    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-lightning"></i> Analisar com IA';
        document.getElementById('analise-body').innerHTML += `
            <div class="alert alert-danger mt-3">
                <i class="bi bi-exclamation-triangle"></i> Erro ao disparar análise: ${escapeHtml(err.message)}
            </div>`;
    }
}

function showPolling() {
    document.getElementById('analise-status-indicator').innerHTML =
        '<span class="polling-indicator"><span class="pulse-dot"></span> Analisando...</span>';
    document.getElementById('analise-body').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <p class="text-secondary">A IA está analisando este edital. Aguarde...</p>
            <small class="text-muted">A página atualiza automaticamente a cada 5 segundos.</small>
        </div>`;
    document.getElementById('analise-section').classList.add('em-andamento');
    document.getElementById('analise-section').classList.remove('concluida', 'erro');
}

function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(checkStatus, 5000);
}

async function checkStatus() {
    try {
        const resp = await fetch(`${STATUS_URL}?id=${EDITAL_ID}`, {
            headers: { 'X-CSRF-Token': CSRF_TOKEN }
        });
        const data = await resp.json();

        if (data.status_analise === 'concluida' || data.status_analise === 'erro') {
            clearInterval(pollingInterval);
            pollingInterval = null;
            renderAnaliseResult(data);
        }
    } catch (err) {
        console.error('Polling error:', err);
    }
}

async function loadAnaliseResult() {
    try {
        const resp = await fetch(`${STATUS_URL}?id=${EDITAL_ID}`, {
            headers: { 'X-CSRF-Token': CSRF_TOKEN }
        });
        const data = await resp.json();
        renderAnaliseResult(data);
    } catch (err) {
        document.getElementById('analise-body').innerHTML = `
            <div class="alert alert-danger">Erro ao carregar resultado: ${escapeHtml(err.message)}</div>`;
    }
}

function renderAnaliseResult(data) {
    const section = document.getElementById('analise-section');
    const indicator = document.getElementById('analise-status-indicator');
    const body = document.getElementById('analise-body');

    if (data.status_analise === 'concluida') {
        section.classList.add('concluida');
        section.classList.remove('em-andamento', 'erro');
        indicator.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Concluída</span>';

        const resultado = data.analise_resultado || {};
        const texto = resultado.resumo_executivo || resultado.texto || resultado.markdown || JSON.stringify(resultado, null, 2);
        const meta = [];
        if (data.analise_modelo) meta.push(`Modelo: ${data.analise_modelo}`);
        if (data.analise_tokens) meta.push(`Tokens: ${data.analise_tokens.toLocaleString('pt-BR')}`);
        if (data.analise_concluida_em) meta.push(`Concluída: ${new Date(data.analise_concluida_em).toLocaleString('pt-BR')}`);

        body.innerHTML = `
            <div class="analise-resultado">${renderMarkdown(texto)}</div>
            ${meta.length ? `<hr><small class="text-muted">${meta.join(' | ')}</small>` : ''}
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" onclick="triggerAnalise()">
                    <i class="ti ti-refresh"></i> Reanalisar
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="openPdfPreview()">
                    <i class="ti ti-file-type-pdf"></i> PDF
                </button>
                <button class="btn btn-outline-success btn-sm" onclick="showEmailForm()">
                    <i class="ti ti-mail"></i> Email
                </button>
            </div>`;

    } else if (data.status_analise === 'erro') {
        section.classList.add('erro');
        section.classList.remove('em-andamento', 'concluida');
        indicator.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Erro</span>';

        body.innerHTML = `
            <div class="alert alert-danger">
                <strong>Erro na análise:</strong> ${escapeHtml(data.analise_erro || 'Erro desconhecido')}
            </div>
            <button class="btn btn-primary btn-sm" onclick="triggerAnalise()">
                <i class="bi bi-arrow-repeat"></i> Tentar novamente
            </button>`;
    }
}

function renderMarkdown(text) {
    if (!text) return '';
    return marked.parse(text);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function openPdfPreview() {
    const pdfUrl = `<?= BASE_URL ?>/api/pncp/export-pdf.php?id=${EDITAL_ID}`;
    document.getElementById('pdf-iframe').src = pdfUrl;
    document.getElementById('pdf-download-link').href = pdfUrl + '&download=1';
    new bootstrap.Modal(document.getElementById('modal-pdf')).show();
}

function showEmailForm() {
    const pdfModal = bootstrap.Modal.getInstance(document.getElementById('modal-pdf'));
    if (pdfModal) pdfModal.hide();
    new bootstrap.Modal(document.getElementById('modal-email')).show();
}

async function sendAnaliseEmail() {
    const emailTo = document.getElementById('email-to').value.trim();
    const statusEl = document.getElementById('email-status');
    if (!emailTo) {
        statusEl.textContent = 'Informe o email destinatário.';
        statusEl.className = 'alert alert-warning py-2';
        return;
    }
    const btn = document.getElementById('btn-send-email');
    btn.disabled = true;
    btn.textContent = 'Enviando...';
    statusEl.textContent = '';
    statusEl.className = '';
    try {
        const resp = await fetch(`<?= BASE_URL ?>/api/pncp/email-analise.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ edital_id: EDITAL_ID, to: emailTo })
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || 'Erro ao enviar');
        statusEl.textContent = 'Email enviado com sucesso!';
        statusEl.className = 'alert alert-success py-2';
    } catch (err) {
        statusEl.textContent = err.message;
        statusEl.className = 'alert alert-danger py-2';
    }
    btn.textContent = 'Enviar';
    btn.disabled = false;
}

// --- DataJud helpers ---
function formatCnpj(cnpj) {
    const d = (cnpj || '').replace(/\D/g, '');
    if (d.length !== 14) return cnpj || '';
    return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
}

// --- DataJud: Feature 1 - Historico Judicial do Orgao ---
const DATAJUD_ORGAO_URL = <?= json_encode(BASE_URL . '/api/datajud/orgao-processos.php') ?>;
const DATAJUD_EMPRESA_URL = <?= json_encode(BASE_URL . '/api/datajud/empresa-idoneidade.php') ?>;

document.addEventListener('DOMContentLoaded', function() {
    loadDatajudOrgao();

    // CNPJ mask
    const cnpjInput = document.getElementById('cnpj-empresa');
    if (cnpjInput) {
        cnpjInput.addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length > 14) v = v.slice(0, 14);
            if (v.length > 12) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/, '$1.$2.$3/$4-$5');
            else if (v.length > 8) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4})/, '$1.$2.$3/$4');
            else if (v.length > 5) v = v.replace(/^(\d{2})(\d{3})(\d{0,3})/, '$1.$2.$3');
            else if (v.length > 2) v = v.replace(/^(\d{2})(\d{0,3})/, '$1.$2');
            e.target.value = v;
        });
        cnpjInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') verificarIdoneidade();
        });
    }
});

async function loadDatajudOrgao(forceRefresh = false) {
    const body = document.getElementById('datajud-orgao-body');
    const status = document.getElementById('datajud-orgao-status');
    const section = document.getElementById('datajud-orgao-section');

    body.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div><span class="ms-2">Consultando hist\u00f3rico judicial...</span></div>';

    try {
        const resp = await fetch(DATAJUD_ORGAO_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ edital_id: EDITAL_ID, force_refresh: forceRefresh })
        });
        const data = await resp.json();

        if (data.error) {
            body.innerHTML = '<div class="alert alert-warning mb-0">' + escapeHtml(data.error) + '</div>';
            return;
        }

        renderDatajudOrgao(data, body, status, section);
    } catch (err) {
        body.innerHTML = '<div class="alert alert-danger mb-0">Erro ao consultar DataJud: ' + escapeHtml(err.message) + '</div>';
    }
}

function renderDatajudOrgao(data, body, status, section) {
    const total = data.total_processos || 0;
    const orgaoNome = data.orgao || '(nome n\u00e3o dispon\u00edvel)';
    const cnpjFormatado = formatCnpj(data.cnpj || '');
    const tribunaisStr = (data.tribunais_consultados || []).join(', ') || 'nenhum';
    const explanationHtml = '<p class="text-muted small mb-3">' +
        '<i class="bi bi-info-circle"></i> ' +
        'Consulta processos judiciais registrados no <strong>DataJud (CNJ)</strong> envolvendo o CNPJ do \u00f3rg\u00e3o licitante ' +
        'nos tribunais relevantes para o estado. Ajuda a avaliar riscos como execu\u00e7\u00f5es fiscais, fal\u00eancias e recupera\u00e7\u00e3o judicial.' +
        '</p>';

    if (total === 0) {
        section.classList.add('empty');
        status.innerHTML = '<span class="badge bg-success">Nenhum processo</span>';
        body.innerHTML = explanationHtml +
            '<div class="text-center py-3 text-success">' +
                '<i class="bi bi-check-circle" style="font-size: 2rem;"></i>' +
                '<p class="mt-2 mb-1">Nenhum processo judicial encontrado para <strong>' + escapeHtml(orgaoNome) + '</strong></p>' +
                '<p class="mb-0"><small class="text-muted">CNPJ consultado: ' + escapeHtml(cnpjFormatado) + '</small></p>' +
                '<small class="text-muted">Tribunais consultados: ' + escapeHtml(tribunaisStr) + '</small>' +
            '</div>' +
            '<div class="text-end mt-2">' +
                '<button class="btn btn-ghost-primary btn-sm" onclick="loadDatajudOrgao(true)"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>' +
            '</div>';
        return;
    }

    // Panorama regional: results are from the region, not specific to this organ's CNPJ
    const isPanorama = data.panorama_regional === true;
    section.classList.add('loaded');
    const cached = data.cached ? ' <span class="badge bg-secondary">cache</span>' : '';
    const buscaAmpla = data.busca_ampla ? ' <span class="badge bg-info" title="Busca sem filtro de classe processual">busca ampla</span>' : '';
    const panoramaBadge = isPanorama ? ' <span class="badge bg-warning text-dark" title="Dados da regi\u00e3o, n\u00e3o espec\u00edficos deste \u00f3rg\u00e3o">panorama regional</span>' : '';
    status.innerHTML = '<span class="badge bg-purple">' + total + ' processo(s)</span>' + cached + buscaAmpla + panoramaBadge;

    const panoramaWarning = isPanorama
        ? '<div class="alert alert-warning py-2 mb-3"><i class="bi bi-exclamation-triangle"></i> ' +
          '<strong>Panorama regional:</strong> A API p\u00fablica do DataJud n\u00e3o permite busca por CNPJ de partes processuais. ' +
          'Os processos abaixo s\u00e3o recentes na jurisdi\u00e7\u00e3o (' + escapeHtml(data.uf || '?') + '), ' +
          'n\u00e3o necessariamente envolvendo <em>' + escapeHtml(orgaoNome) + '</em>. ' +
          'Para consulta espec\u00edfica, verifique diretamente nos portais dos tribunais.</div>'
        : '';

    // Build classe badges
    const resumo = data.resumo || {};
    const porClasse = resumo.por_classe || {};
    let badgesHtml = '';
    for (const [classe, count] of Object.entries(porClasse)) {
        badgesHtml += '<div class="classe-badge"><span class="count">' + count + '</span><span class="label">' + escapeHtml(classe) + '</span></div>';
    }

    // Build processes table
    let tableRows = '';
    for (const p of (data.processos || []).slice(0, 50)) {
        const classe = (p.classe || {}).nome || '?';
        const dataAj = p.data_ajuizamento ? new Date(p.data_ajuizamento + 'T00:00:00').toLocaleDateString('pt-BR') : '?';
        const ultMov = p.ultima_movimentacao
            ? (p.ultima_movimentacao.nome || '?') + ' (' + (p.ultima_movimentacao.data ? new Date(p.ultima_movimentacao.data).toLocaleDateString('pt-BR') : '?') + ')'
            : '-';
        tableRows += '<tr><td>' + escapeHtml(p.numero) + '</td><td>' + escapeHtml(classe) + '</td><td>' + escapeHtml(p.tribunal) + '</td><td>' + dataAj + '</td><td>' + escapeHtml(ultMov) + '</td></tr>';
    }

    body.innerHTML = explanationHtml + panoramaWarning +
        '<div class="mb-2"><strong>' + escapeHtml(orgaoNome) + '</strong> <small class="text-muted">(CNPJ: ' + escapeHtml(cnpjFormatado) + ')</small></div>' +
        '<div class="d-flex flex-wrap gap-2 mb-3">' + badgesHtml + '</div>' +
        '<div class="d-flex justify-content-between align-items-center">' +
            '<small class="text-muted">Tribunais: ' + escapeHtml(tribunaisStr) + ' | Per\u00edodo: ' + (resumo.mais_antigo || '?') + ' a ' + (resumo.mais_recente || '?') + '</small>' +
            '<button class="btn btn-ghost-primary btn-sm" onclick="loadDatajudOrgao(true)"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>' +
        '</div>' +
        '<details class="mt-3">' +
            '<summary class="btn btn-outline-secondary btn-sm">Ver processos detalhados (' + total + ')</summary>' +
            '<div class="table-responsive mt-2">' +
                '<table class="table table-sm processo-table">' +
                    '<thead><tr><th>Processo</th><th>Classe</th><th>Tribunal</th><th>Ajuizamento</th><th>\u00daltima Movimenta\u00e7\u00e3o</th></tr></thead>' +
                    '<tbody>' + tableRows + '</tbody>' +
                '</table>' +
            '</div>' +
        '</details>';
}

// --- DataJud: Feature 3 - Verificacao de Idoneidade ---

async function verificarIdoneidade() {
    const cnpjRaw = document.getElementById('cnpj-empresa').value.replace(/\D/g, '');
    const resultado = document.getElementById('datajud-empresa-resultado');
    const btn = document.getElementById('btn-verificar-idoneidade');
    const status = document.getElementById('datajud-empresa-status');

    if (cnpjRaw.length !== 14) {
        resultado.innerHTML = '<div class="alert alert-warning py-2">CNPJ deve ter 14 d\u00edgitos.</div>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Consultando...';
    resultado.innerHTML = '';

    try {
        const resp = await fetch(DATAJUD_EMPRESA_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ cnpj: cnpjRaw, edital_id: EDITAL_ID })
        });
        const data = await resp.json();

        if (data.error) {
            resultado.innerHTML = '<div class="alert alert-danger py-2">' + escapeHtml(data.error) + '</div>';
            return;
        }

        renderIdoneidade(data, resultado, status);
    } catch (err) {
        resultado.innerHTML = '<div class="alert alert-danger py-2">Erro: ' + escapeHtml(err.message) + '</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-search"></i> Consultar';
    }
}

function renderIdoneidade(data, resultado, status) {
    const total = data.total_processos || 0;
    const alertas = data.alertas || [];
    const nomeEmpresa = document.getElementById('nome-empresa').value.trim();
    const cnpjFormatado = formatCnpj(data.cnpj || '');
    const empresaLabel = nomeEmpresa
        ? '<strong>' + escapeHtml(nomeEmpresa) + '</strong> (CNPJ: ' + escapeHtml(cnpjFormatado) + ')'
        : 'CNPJ <strong>' + escapeHtml(cnpjFormatado) + '</strong>';
    const tribunaisStr = (data.tribunais_consultados || []).join(', ') || 'nenhum';
    const buscaAmplaNote = data.busca_ampla ? '<br><small class="text-muted"><i class="bi bi-info-circle"></i> Busca ampla (sem filtro de classe processual) — todos os tipos de processo foram considerados.</small>' : '';

    if (total === 0) {
        status.innerHTML = '<span class="badge bg-success">Nenhuma pend\u00eancia</span>';
        resultado.innerHTML =
            '<div class="text-center py-2 text-success">' +
                '<i class="bi bi-check-circle-fill" style="font-size: 1.5rem;"></i>' +
                '<p class="mt-2 mb-1">Nenhuma pend\u00eancia judicial encontrada para ' + empresaLabel + '</p>' +
                '<small class="text-muted">Tribunais consultados: ' + escapeHtml(tribunaisStr) + '</small>' +
                buscaAmplaNote +
            '</div>';
        return;
    }

    const isPanorama = data.panorama_regional === true;
    const hasCritico = alertas.some(function(a) { return a.tipo === 'CRITICO'; });
    const hasAtencao = alertas.some(function(a) { return a.tipo === 'ATENCAO'; });
    const badgeClass = hasCritico ? 'bg-danger' : hasAtencao ? 'bg-warning text-dark' : 'bg-info';
    const panoramaBadge = isPanorama ? ' <span class="badge bg-warning text-dark">panorama</span>' : '';
    status.innerHTML = '<span class="badge ' + badgeClass + '">' + total + ' processo(s)</span>' + panoramaBadge;

    let headerHtml = '<div class="mb-2">Resultado para ' + empresaLabel + ':</div>';

    const panoramaWarning = isPanorama
        ? '<div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle"></i> ' +
          '<strong>Panorama regional:</strong> A API p\u00fablica do DataJud n\u00e3o permite busca por CNPJ de partes processuais. ' +
          'Os processos abaixo s\u00e3o da jurisdi\u00e7\u00e3o, n\u00e3o necessariamente envolvendo esta empresa. ' +
          'Para consulta espec\u00edfica, verifique diretamente nos portais dos tribunais.</div>'
        : '';

    let alertasHtml = '';
    for (const alerta of alertas) {
        const cssClass = alerta.tipo === 'CRITICO' ? 'alerta-critico' : alerta.tipo === 'ATENCAO' ? 'alerta-atencao' : 'alerta-info';
        const icon = alerta.tipo === 'CRITICO' ? 'exclamation-triangle-fill' : alerta.tipo === 'ATENCAO' ? 'exclamation-circle-fill' : 'info-circle-fill';
        alertasHtml += '<div class="' + cssClass + '"><strong><i class="bi bi-' + icon + '"></i> ' + escapeHtml(alerta.tipo) + ':</strong> ' + escapeHtml(alerta.descricao) + '</div>';
    }

    resultado.innerHTML = headerHtml + panoramaWarning + alertasHtml +
        '<small class="text-muted d-block mt-2">Tribunais: ' + escapeHtml(tribunaisStr) + '</small>' +
        buscaAmplaNote;
}
</script>

<!-- PDF Preview Modal -->
<div class="modal modal-blur" id="modal-pdf" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Resumo Executivo — PDF</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="pdf-iframe" style="width:100%;height:500px;border:none;"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        <a id="pdf-download-link" class="btn btn-primary" download>
          <i class="ti ti-download"></i> Baixar PDF
        </a>
        <button type="button" class="btn btn-success" onclick="showEmailForm()">
          <i class="ti ti-mail"></i> Enviar por Email
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Email Modal -->
<div class="modal modal-blur" id="modal-email" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Enviar Resumo por Email</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Destinatário</label>
          <input type="email" id="email-to" class="form-control"
                 value="<?= htmlspecialchars($_SESSION['user']['email'] ?? '') ?>"
                 placeholder="email@exemplo.com">
        </div>
        <div id="email-status"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success" id="btn-send-email" onclick="sendAnaliseEmail()">
          <i class="ti ti-send"></i> Enviar
        </button>
      </div>
    </div>
  </div>
</div>

<?php
};

require __DIR__ . '/../../../src/views/layouts/user.php';
