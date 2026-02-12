<?php
/**
 * Monitor PNCP - Busca de Licitações no Portal Nacional de Contratações Públicas
 * Interface de busca com filtros que consulta a API do PNCP via FastAPI backend
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

// Verificar acesso à vertical
$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

if ($user_vertical !== 'licitacoes' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

$pageTitle = 'Monitor PNCP';
$activeNav = 'licitacoes';

$headExtra = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
    .search-card {
        border: 1px solid #e6e8eb;
        border-radius: 8px;
        background: #fff;
    }
    .result-card {
        background: #fff;
        border: 1px solid #e6e8eb;
        border-radius: 8px;
        padding: 1rem 1.25rem;
        margin-bottom: 0.75rem;
        border-left: 4px solid #667eea;
        transition: all 0.2s ease;
    }
    .result-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .result-card .badge {
        font-size: 0.75rem;
    }
    .result-meta {
        font-size: 0.85rem;
        color: #64748b;
    }
    .result-title {
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 0.25rem;
    }
    .result-value {
        font-size: 1.1rem;
        font-weight: 700;
        color: #667eea;
    }
    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: #94a3b8;
    }
    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        display: block;
    }
    .loading-spinner {
        display: none;
        text-align: center;
        padding: 2rem;
    }
    .pagination-controls {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    .uf-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
        gap: 4px;
    }
    .uf-grid label {
        font-size: 0.8rem;
        cursor: pointer;
    }
</style>
HTML;

$pageContent = function () {
    $csrfToken = $_SESSION['csrf_token'] ?? '';
?>
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <a href="<?= BASE_URL ?>/areas/licitacoes/" class="btn btn-ghost-primary btn-sm mb-2">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
                <h2 class="page-title">Monitor PNCP</h2>
                <div class="text-secondary mt-1">Busca de licitações no Portal Nacional de Contratações Públicas</div>
            </div>
        </div>
    </div>

    <!-- Search Form -->
    <div class="search-card mb-4">
        <div class="card-body p-4">
            <form id="pncp-search-form" onsubmit="return false;">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Palavras-chave</label>
                        <input type="text" class="form-control" id="search-query" name="q"
                               placeholder="Ex: infraestrutura de TI, software, manutenção predial..."
                               autofocus>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tipo de documento</label>
                        <select class="form-select" id="search-tipo" name="tipos_documento">
                            <option value="edital" selected>Edital</option>
                            <option value="aviso">Aviso de Licitação</option>
                            <option value="ata">Ata de Registro de Preços</option>
                            <option value="contrato">Contrato</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Status</label>
                        <select class="form-select" id="search-status" name="status">
                            <option value="">Todos</option>
                            <option value="divulgado" selected>Divulgado (aberto)</option>
                            <option value="encerrado">Encerrado</option>
                            <option value="suspenso">Suspenso</option>
                            <option value="revogado">Revogado</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Modalidade</label>
                        <select class="form-select" id="search-modalidade" name="modalidade">
                            <option value="">Todas</option>
                            <option value="pregao_eletronico">Pregão Eletrônico</option>
                            <option value="concorrencia">Concorrência</option>
                            <option value="dispensa">Dispensa de Licitação</option>
                            <option value="inexigibilidade">Inexigibilidade</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Ordenação</label>
                        <select class="form-select" id="search-ordem" name="ordenacao">
                            <option value="-data">Mais recentes primeiro</option>
                            <option value="data">Mais antigos primeiro</option>
                            <option value="-valor">Maior valor</option>
                            <option value="valor">Menor valor</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="button" class="btn btn-primary" id="btn-search" onclick="searchPNCP(1)">
                        <i class="bi bi-search"></i> Buscar
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="clearSearch()">
                        <i class="bi bi-x-circle"></i> Limpar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Loading -->
    <div class="loading-spinner" id="loading">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-2 text-secondary">Consultando PNCP...</p>
    </div>

    <!-- Results -->
    <div id="results-container">
        <div class="empty-state">
            <i class="bi bi-search"></i>
            <h4>Buscar licitações no PNCP</h4>
            <p>Use os filtros acima para encontrar editais e avisos de licitação publicados no Portal Nacional de Contratações Públicas.</p>
        </div>
    </div>

    <!-- Pagination -->
    <div id="pagination-container" style="display:none;">
        <div class="pagination-controls">
            <button class="btn btn-sm btn-outline-primary" id="btn-prev" onclick="searchPNCP(currentPage - 1)" disabled>
                <i class="bi bi-chevron-left"></i> Anterior
            </button>
            <span class="text-secondary" id="page-info">Página 1</span>
            <button class="btn btn-sm btn-outline-primary" id="btn-next" onclick="searchPNCP(currentPage + 1)">
                Próxima <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>

<script>
let currentPage = 1;
const csrfToken = <?= json_encode($csrfToken) ?>;

async function searchPNCP(page = 1) {
    const query = document.getElementById('search-query').value.trim();
    if (!query) {
        alert('Digite palavras-chave para buscar.');
        return;
    }

    currentPage = page;
    const loading = document.getElementById('loading');
    const results = document.getElementById('results-container');
    const pagination = document.getElementById('pagination-container');

    loading.style.display = 'block';
    results.innerHTML = '';
    pagination.style.display = 'none';

    const params = {
        q: query,
        pagina: page,
        tipos_documento: document.getElementById('search-tipo').value,
        status: document.getElementById('search-status').value,
        modalidade: document.getElementById('search-modalidade').value,
        ordenacao: document.getElementById('search-ordem').value
    };

    // Remove empty params
    Object.keys(params).forEach(k => { if (!params[k]) delete params[k]; });

    try {
        const resp = await fetch('<?= BASE_URL ?>/api/legal/pncp-search.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(params)
        });

        const data = await resp.json();
        loading.style.display = 'none';

        if (!resp.ok) {
            results.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> ${data.error || 'Erro ao consultar PNCP'}</div>`;
            return;
        }

        if (!data.items || data.items.length === 0) {
            results.innerHTML = `<div class="empty-state"><i class="bi bi-inbox"></i><h4>Nenhum resultado encontrado</h4><p>Tente outros termos ou remova filtros.</p></div>`;
            return;
        }

        renderResults(data.items, data.total || 0);

        // Pagination
        if (data.total > 20) {
            pagination.style.display = 'block';
            document.getElementById('btn-prev').disabled = (page <= 1);
            document.getElementById('btn-next').disabled = (page * 20 >= data.total);
            document.getElementById('page-info').textContent = `Página ${page} de ${Math.ceil(data.total / 20)} (${data.total} resultados)`;
        }

    } catch (err) {
        loading.style.display = 'none';
        results.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Erro de conexão: ${err.message}</div>`;
    }
}

function renderResults(items, total) {
    const container = document.getElementById('results-container');
    let html = `<div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">${total} resultado(s) encontrado(s)</h4>
    </div>`;

    items.forEach(item => {
        const valor = item.valor_estimado
            ? `<span class="result-value">R$ ${formatNumber(item.valor_estimado)}</span>`
            : '<span class="text-secondary">Valor sigiloso</span>';

        const status = item.status || 'N/A';
        const statusClass = status === 'divulgado' ? 'bg-success' : (status === 'encerrado' ? 'bg-secondary' : 'bg-warning');

        const dataPublicacao = item.data_publicacao
            ? new Date(item.data_publicacao).toLocaleDateString('pt-BR')
            : 'N/A';

        const dataAbertura = item.data_abertura
            ? new Date(item.data_abertura).toLocaleDateString('pt-BR') + ' ' + (item.hora_abertura || '')
            : 'N/A';

        const pncpUrl = item.url_pncp || '#';

        html += `
        <div class="result-card">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <span class="badge ${statusClass} me-1">${escapeHtml(status)}</span>
                    <span class="badge bg-info">${escapeHtml(item.modalidade || 'N/A')}</span>
                </div>
                ${valor}
            </div>
            <p class="result-title">${escapeHtml(item.objeto || item.titulo || 'Sem título')}</p>
            <div class="result-meta">
                <i class="bi bi-building"></i> ${escapeHtml(item.orgao || 'N/A')}
                &nbsp;|&nbsp;
                <i class="bi bi-geo-alt"></i> ${escapeHtml(item.uf || 'N/A')}
                &nbsp;|&nbsp;
                <i class="bi bi-calendar"></i> Publicação: ${dataPublicacao}
                &nbsp;|&nbsp;
                <i class="bi bi-clock"></i> Abertura: ${dataAbertura}
            </div>
            <div class="mt-2">
                <a href="${escapeHtml(pncpUrl)}" target="_blank" class="btn btn-sm btn-outline-primary me-1">
                    <i class="bi bi-box-arrow-up-right"></i> Ver no PNCP
                </a>
                ${item.url_edital ? `<a href="${escapeHtml(item.url_edital)}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> Baixar Edital</a>` : ''}
            </div>
        </div>`;
    });

    container.innerHTML = html;
}

function formatNumber(num) {
    return Number(num).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function clearSearch() {
    document.getElementById('search-query').value = '';
    document.getElementById('search-tipo').value = 'edital';
    document.getElementById('search-status').value = 'divulgado';
    document.getElementById('search-modalidade').value = '';
    document.getElementById('search-ordem').value = '-data';
    document.getElementById('results-container').innerHTML = `<div class="empty-state"><i class="bi bi-search"></i><h4>Buscar licitações no PNCP</h4><p>Use os filtros acima para encontrar editais e avisos de licitação.</p></div>`;
    document.getElementById('pagination-container').style.display = 'none';
}

// Enter key triggers search
document.getElementById('search-query').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') searchPNCP(1);
});
</script>
<?php
};

require __DIR__ . '/../../../src/views/layouts/user.php';
