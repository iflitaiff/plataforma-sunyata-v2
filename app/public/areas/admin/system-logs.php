<?php
/**
 * System Logs - Centralized Event Log Dashboard
 * Exige acesso administrativo.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

// Verificar acesso de administrador
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
if (!$is_admin) {
    $_SESSION['error'] = 'Você não tem permissão para acessar esta página';
    redirect(BASE_URL . '/dashboard.php');
}

$pageTitle = 'System Logs';
$activeNav = 'admin';

$headExtra = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
    .filter-card {
        border: 1px solid #e6e8eb;
        border-radius: 8px;
        background: #f8f9fa;
    }
    
    .timeline-container {
        position: relative;
        padding-left: 1.5rem;
    }
    .timeline-item {
        position: relative;
        padding-bottom: 1.5rem;
    }
    .timeline-item:last-child {
        padding-bottom: 0;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -1.25rem;
        top: 1.25rem;
        bottom: 0;
        width: 2px;
        background-color: #e6e8eb;
    }
    .timeline-item:last-child::before {
        display: none;
    }
    .timeline-icon {
        position: absolute;
        left: -1.65rem;
        top: 0;
        width: 1rem;
        height: 1rem;
        border-radius: 50%;
        border: 2px solid #fff;
        z-index: 1;
    }
    .timeline-content {
        background: #fff;
        border: 1px solid #e6e8eb;
        border-radius: 6px;
        padding: 0.75rem;
    }
    .timeline-meta {
        font-size: 0.8rem;
        color: #64748b;
        margin-bottom: 0.25rem;
    }
    .timeline-payload {
        background: #f8f9fa;
        padding: 0.5rem;
        border-radius: 4px;
        font-family: monospace;
        font-size: 0.75rem;
        margin-top: 0.5rem;
        max-height: 200px;
        overflow-y: auto;
    }
    .timeline-duration {
        font-size: 0.75rem;
        color: #94a3b8;
        font-style: italic;
    }
    
    /* Cores das fontes (Source) */
    .bg-source-portal { background-color: #206bc4 !important; color: white !important; }
    .bg-source-n8n { background-color: #2fb344 !important; color: white !important; }
    .bg-source-fastapi { background-color: #f76707 !important; color: white !important; }
    .bg-source-litellm { background-color: #6f32be !important; color: white !important; }
    .bg-source-cron { background-color: #6c757d !important; color: white !important; }
    
    tr.has-trace { cursor: pointer; }
    tr.has-trace:hover td { background-color: rgba(32, 107, 196, 0.05); }
    
    .payload-preview { max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; font-size: 0.8em; color: #666; }
</style>
HTML;

$pageContent = function () {
    $csrfToken = csrf_token();
    // Default dates for filter
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-7 days'));
?>
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-ghost-primary btn-sm mb-2">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
                <h2 class="page-title">System Logs</h2>
                <div class="text-secondary mt-1">Monitoramento centralizado de eventos do sistema</div>
            </div>
            <div class="col-auto ms-auto d-print-none">
                <button class="btn btn-primary" onclick="loadEvents()">
                    <i class="bi bi-arrow-repeat me-2"></i> Atualizar
                </button>
            </div>
        </div>
    </div>

    <!-- Bloco 1: Dashboard Resumo (Placeholder) -->
    <div class="row row-deck mb-4">
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="subheader">Eventos (24h)</div>
                    </div>
                    <div class="h1 mb-0" id="stat-total">
                        <span class="spinner-border spinner-border-sm text-secondary"></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="subheader">Erros (24h)</div>
                    </div>
                    <div class="h1 mb-0 text-danger" id="stat-errors">
                        <span class="spinner-border spinner-border-sm text-secondary"></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="subheader">Warnings (24h)</div>
                    </div>
                    <div class="h1 mb-0 text-warning" id="stat-warnings">
                        <span class="spinner-border spinner-border-sm text-secondary"></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="subheader">Custo LLM (24h)</div>
                    </div>
                    <div class="h1 mb-0 text-success" id="stat-cost">
                        <span class="spinner-border spinner-border-sm text-secondary"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bloco 2: Filtros -->
    <div class="filter-card p-3 mb-4">
        <form id="filter-form" onsubmit="event.preventDefault(); loadEvents(1);">
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Source</label>
                    <select class="form-select form-select-sm" id="filter-source">
                        <option value="">Todos</option>
                        <option value="portal">Portal</option>
                        <option value="n8n">N8N</option>
                        <option value="fastapi">FastAPI</option>
                        <option value="litellm">LiteLLM</option>
                        <option value="cron">Cron</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Severidade</label>
                    <select class="form-select form-select-sm" id="filter-severity">
                        <option value="">Todas</option>
                        <option value="info">Info</option>
                        <option value="warning">Warning</option>
                        <option value="error">Error</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Entity (ex: edital:148)</label>
                    <input type="text" class="form-control form-control-sm" id="filter-entity" placeholder="tipo:id">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Período De</label>
                    <input type="date" class="form-control form-control-sm" id="filter-date-from" value="<?= $dateFrom ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Período Até</label>
                    <input type="date" class="form-control form-control-sm" id="filter-date-to" value="<?= $dateTo ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Trace ID</label>
                    <input type="text" class="form-control form-control-sm" id="filter-trace" placeholder="UUID completo">
                </div>
                <div class="col-12 mt-2 text-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearFilters()">Limpar</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Bloco 3: Tabela de Eventos -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-striped table-sm">
                <thead>
                    <tr>
                        <th style="width: 150px;">Timestamp</th>
                        <th style="width: 100px;">Source</th>
                        <th style="width: 100px;">Severity</th>
                        <th>Tipo do Evento</th>
                        <th>Entidade</th>
                        <th>Resumo</th>
                    </tr>
                </thead>
                <tbody id="events-body">
                    <tr><td colspan="6" class="text-center py-4"><span class="spinner-border spinner-border-sm text-secondary"></span> Carregando...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex align-items-center justify-content-between">
            <p class="m-0 text-secondary">Mostrando página <span id="page-current">1</span></p>
            <ul class="pagination m-0 ms-auto">
                <li class="page-item disabled" id="btn-prev-wrap">
                    <button class="page-link" onclick="loadEvents(currentPage - 1)">Anterior</button>
                </li>
                <li class="page-item" id="btn-next-wrap">
                    <button class="page-link" onclick="loadEvents(currentPage + 1)">Próxima</button>
                </li>
            </ul>
        </div>
    </div>

    <!-- Bloco 4: Painel Lateral de Trace -->
    <div class="offcanvas offcanvas-end shadow-sm" tabindex="-1" id="trace-panel" style="width: 600px;">
        <div class="offcanvas-header bg-light border-bottom">
            <h5 class="offcanvas-title mb-0">Trace Lifecycle</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-4 bg-white">
            <div class="mb-4">
                <span class="text-muted small">Trace ID:</span><br>
                <code id="trace-id-display" class="user-select-all fs-6"></code>
            </div>
            
            <div id="trace-timeline-loading" style="display: none;" class="text-center py-5">
                <span class="spinner-border text-primary mb-2"></span>
                <p class="text-muted">Reconstruindo transação...</p>
            </div>
            
            <div class="timeline-container" id="trace-timeline">
                <!-- Preenchido via JS -->
            </div>
        </div>
    </div>

<script>
let currentPage = 1;
const ITEMS_PER_PAGE = 50;

// Utilidades visuais
const sourceColors = {
    'portal': 'bg-source-portal',
    'n8n': 'bg-source-n8n',
    'fastapi': 'bg-source-fastapi',
    'litellm': 'bg-source-litellm',
    'cron': 'bg-source-cron'
};

const severityBadges = {
    'info': '<span class="badge bg-info">Info</span>',
    'warning': '<span class="badge bg-warning text-dark">Warning</span>',
    'error': '<span class="badge bg-danger">Error</span>',
    'debug': '<span class="badge bg-secondary">Debug</span>'
};

document.addEventListener('DOMContentLoaded', () => {
    loadSummaryDashboard();
    loadEvents(1);
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function clearFilters() {
    document.getElementById('filter-source').value = '';
    document.getElementById('filter-severity').value = '';
    document.getElementById('filter-entity').value = '';
    document.getElementById('filter-trace').value = '';
    // reset dates to default
    const d = new Date();
    document.getElementById('filter-date-to').value = d.toISOString().split('T')[0];
    d.setDate(d.getDate() - 7);
    document.getElementById('filter-date-from').value = d.toISOString().split('T')[0];
    
    loadEvents(1);
}

// ==========================================
// MOCKS E INTEGRAÇÃO FUTURA DO CODEX
// ==========================================

async function loadSummaryDashboard() {
    try {
        const resp = await fetch(`/api/admin/system-events-dashboard.php`, {
            headers: { 'X-CSRF-Token': csrfToken }
        });
        if (!resp.ok) throw new Error('Erro ao carregar dashboard');
        const data = await resp.json();
        
        document.getElementById('stat-total').textContent = (data.total || 0).toLocaleString('pt-BR');
        document.getElementById('stat-errors').textContent = (data.errors || 0).toLocaleString('pt-BR');
        document.getElementById('stat-warnings').textContent = (data.warnings || 0).toLocaleString('pt-BR');
        document.getElementById('stat-cost').textContent = '$ ' + Number(data.custo_total || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } catch (err) {
        console.error(err);
        document.getElementById('stat-total').textContent = '-';
        document.getElementById('stat-errors').textContent = '-';
        document.getElementById('stat-warnings').textContent = '-';
        document.getElementById('stat-cost').textContent = '-';
    }
}

async function loadEvents(page = 1) {
    currentPage = page;
    document.getElementById('page-current').textContent = page;
    document.getElementById('events-body').innerHTML = '<tr><td colspan="6" class="text-center py-4"><span class="spinner-border spinner-border-sm text-secondary"></span> Carregando...</td></tr>';
    
    // Ler filtros
    const filters = {
        source: document.getElementById('filter-source').value,
        severity: document.getElementById('filter-severity').value,
        entity: document.getElementById('filter-entity').value,
        date_from: document.getElementById('filter-date-from').value,
        date_to: document.getElementById('filter-date-to').value,
        trace_id: document.getElementById('filter-trace').value,
        page: page,
        limit: ITEMS_PER_PAGE
    };

    // Remove empty params
    Object.keys(filters).forEach(k => {
        if (filters[k] === '' || filters[k] === null || filters[k] === undefined) {
            delete filters[k];
        }
    });

    try {
        const resp = await fetch(`/api/admin/system-events.php?` + new URLSearchParams(filters), {
            headers: { 'X-CSRF-Token': csrfToken }
        });
        if (!resp.ok) throw new Error('Erro ao carregar eventos');
        const data = await resp.json();
        
        // As per spec: { events: [...], total, page, pages }
        // The render function expects items and has_next
        const mappedData = {
            items: data.events || [],
            has_next: data.page < data.pages
        };
        
        renderEventsTable(mappedData);
    } catch (err) {
        console.error(err);
        document.getElementById('events-body').innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger"><i class="bi bi-exclamation-triangle"></i> Erro ao carregar eventos: ${escapeHtml(err.message)}</td></tr>`;
        document.getElementById('btn-next-wrap').classList.add('disabled');
    }
}

function renderEventsTable(data) {
    const tbody = document.getElementById('events-body');
    
    if (!data.items || data.items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Nenhum evento encontrado para os filtros selecionados.</td></tr>';
        document.getElementById('btn-next-wrap').classList.add('disabled');
        return;
    }
    
    let html = '';
    data.items.forEach(ev => {
        const ts = new Date(ev.created_at).toLocaleString('pt-BR');
        const colorClass = sourceColors[ev.source] || 'bg-secondary';
        const sevBadge = severityBadges[ev.severity] || ev.severity;
        const entityStr = ev.entity_type ? `${escapeHtml(ev.entity_type)}:${escapeHtml(ev.entity_id)}` : '-';
        
        const trClass = ev.trace_id ? 'has-trace' : '';
        const onclickAttr = ev.trace_id ? `onclick="openTrace('${escapeHtml(ev.trace_id)}', this)"` : '';
        const traceIndicator = ev.trace_id ? `<i class="bi bi-diagram-3 me-1 text-muted" title="Possui Trace ID"></i>` : '';

        html += `
            <tr class="${trClass}" ${onclickAttr} title="${ev.trace_id ? 'Clique para ver a linha do tempo completa' : ''}">
                <td class="text-nowrap">${traceIndicator}${ts}</td>
                <td><span class="badge ${colorClass}">${escapeHtml(ev.source)}</span></td>
                <td>${sevBadge}</td>
                <td><code>${escapeHtml(ev.event_type)}</code></td>
                <td>${entityStr}</td>
                <td><span class="text-truncate d-inline-block" style="max-width: 300px;">${escapeHtml(ev.summary)}</span></td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
    
    // Pagination state
    document.getElementById('btn-prev-wrap').classList.toggle('disabled', currentPage <= 1);
    document.getElementById('btn-next-wrap').classList.toggle('disabled', !data.has_next);
}

async function openTrace(traceId, rowElement) {
    const offcanvas = new bootstrap.Offcanvas(document.getElementById('trace-panel'));
    document.getElementById('trace-id-display').textContent = traceId;
    document.getElementById('trace-timeline').innerHTML = '';
    document.getElementById('trace-timeline-loading').style.display = 'block';
    
    offcanvas.show();

    try {
        const resp = await fetch(`/api/admin/system-events-trace.php?trace_id=${encodeURIComponent(traceId)}`, {
            headers: { 'X-CSRF-Token': csrfToken }
        });
        if (!resp.ok) throw new Error('Erro ao carregar trace');
        const data = await resp.json();
        
        document.getElementById('trace-timeline-loading').style.display = 'none';
        renderTimeline(data.events || []);
    } catch (err) {
        console.error(err);
        document.getElementById('trace-timeline-loading').style.display = 'none';
        document.getElementById('trace-timeline').innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Erro ao carregar detalhes do trace: ${escapeHtml(err.message)}</div>`;
    }
}

function renderTimeline(events) {
    const container = document.getElementById('trace-timeline');
    let html = '';
    
    events.forEach(ev => {
        const colorClass = sourceColors[ev.source] || 'bg-secondary';
        const ts = new Date(ev.created_at).toLocaleTimeString('pt-BR', { hour12: false, hour: '2-digit', minute:'2-digit', second:'2-digit' });
        
        let durStr = '';
        if (ev.duration_ms) {
            durStr = `<span class="timeline-duration ms-2"><i class="bi bi-stopwatch"></i> ${ev.duration_ms}ms</span>`;
        }
        
        let payloadHtml = '';
        if (ev.payload) {
            payloadHtml = `<div class="timeline-payload">${escapeHtml(JSON.stringify(ev.payload, null, 2))}</div>`;
        }

        html += `
            <div class="timeline-item">
                <div class="timeline-icon ${colorClass}"></div>
                <div class="timeline-content">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>${escapeHtml(ev.source)}</strong>
                        <span class="text-muted small">${ts}${durStr}</span>
                    </div>
                    <div class="timeline-meta"><code>${escapeHtml(ev.event_type)}</code></div>
                    <div class="mb-1">${escapeHtml(ev.summary)}</div>
                    ${payloadHtml}
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}
</script>
<?php
};

require __DIR__ . '/../../../src/views/layouts/user.php';
