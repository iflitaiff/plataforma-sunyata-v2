<?php
/**
 * Meus Documentos — permanent document library page.
 *
 * Upload area (drag-and-drop), document listing, search.
 * Uses HTMX for uploads and list refresh.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

$pageTitle = 'Meus Documentos';
$activeNav = 'meus-documentos';

$pageContent = function () {
?>

<?php
$pageHeaderTitle = 'Meus Documentos';
$pageHeaderPretitle = 'Biblioteca';
include __DIR__ . '/../../src/views/components/page-header.php';
?>

<!-- Upload Area -->
<div class="card mb-4">
    <div class="card-body">
        <div id="drop-zone" class="border border-2 border-dashed rounded p-4 text-center"
             style="border-color: var(--tblr-border-color); cursor: pointer; transition: all 0.2s;">
            <i class="ti ti-cloud-upload" style="font-size: 2.5rem;" class="text-secondary mb-2"></i>
            <h3 class="mt-2">Arraste arquivos aqui</h3>
            <p class="text-secondary mb-2">ou clique para selecionar</p>
            <p class="text-secondary small mb-0">PDF, DOCX, TXT, CSV, XLSX — max 20MB</p>
            <input type="file" id="file-input" multiple accept=".pdf,.docx,.doc,.txt,.csv,.md,.xlsx,.xls" style="display:none;">
        </div>
        <div id="upload-progress" style="display:none;" class="mt-3">
            <div class="progress">
                <div class="progress-bar" id="upload-bar" style="width:0%"></div>
            </div>
            <small class="text-secondary" id="upload-status">Enviando...</small>
        </div>
    </div>
</div>

<!-- Search + Filters -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-6">
                <label class="form-label">Buscar</label>
                <div class="input-icon">
                    <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                    <input type="text" class="form-control" id="doc-search"
                           placeholder="Buscar por nome ou conteudo...">
                </div>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Tipo</label>
                <select class="form-select" id="doc-type-filter">
                    <option value="">Todos</option>
                    <option value="application/pdf">PDF</option>
                    <option value="application/vnd.openxmlformats-officedocument.wordprocessingml.document">DOCX</option>
                    <option value="text/plain">TXT</option>
                    <option value="text/csv">CSV</option>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-outline-secondary w-100" id="btn-clear-doc-filters">
                    <i class="ti ti-x me-1"></i> Limpar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Documents List -->
<div class="card">
    <div class="card-body p-0" id="documents-list"
         hx-get="<?= BASE_URL ?>/api/documents/list.php?format=table"
         hx-trigger="load"
         hx-swap="innerHTML">
        <div class="text-center p-4 text-secondary">
            <span class="spinner-border spinner-border-sm"></span> Carregando documentos...
        </div>
    </div>
</div>

<script>
(function() {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const progressArea = document.getElementById('upload-progress');
    const progressBar = document.getElementById('upload-bar');
    const statusText = document.getElementById('upload-status');

    // Drag-and-drop
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.style.borderColor = 'var(--tblr-primary)';
        this.style.backgroundColor = 'rgba(32, 107, 196, 0.05)';
    });

    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.style.borderColor = 'var(--tblr-border-color)';
        this.style.backgroundColor = '';
    });

    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.style.borderColor = 'var(--tblr-border-color)';
        this.style.backgroundColor = '';
        if (e.dataTransfer.files.length > 0) {
            uploadFiles(e.dataTransfer.files);
        }
    });

    // Click to select
    dropZone.addEventListener('click', function() {
        fileInput.click();
    });

    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            uploadFiles(this.files);
            this.value = '';
        }
    });

    async function uploadFiles(files) {
        progressArea.style.display = 'block';
        let uploaded = 0;
        const total = files.length;

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            statusText.textContent = 'Enviando ' + file.name + ' (' + (i+1) + '/' + total + ')...';
            progressBar.style.width = ((i / total) * 100) + '%';

            const formData = new FormData();
            formData.append('file', file);

            try {
                const resp = await fetch('<?= BASE_URL ?>/api/documents/upload.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();
                if (data.success) {
                    uploaded++;
                } else {
                    window.SunyataApp.showToast('Erro: ' + (data.error || 'Falha no upload de ' + file.name), 'danger');
                }
            } catch (err) {
                window.SunyataApp.showToast('Erro de rede ao enviar ' + file.name, 'danger');
            }
        }

        progressBar.style.width = '100%';
        statusText.textContent = uploaded + ' de ' + total + ' enviados com sucesso';

        if (uploaded > 0) {
            window.SunyataApp.showToast(uploaded + ' documento(s) enviado(s)!', 'success');
            refreshDocList();
        }

        setTimeout(function() {
            progressArea.style.display = 'none';
            progressBar.style.width = '0%';
        }, 2000);
    }

    // Filters
    function buildDocFilterUrl() {
        const params = new URLSearchParams();
        params.set('format', 'table');

        const search = document.getElementById('doc-search').value.trim();
        const mimeType = document.getElementById('doc-type-filter').value;

        if (search.length >= 2) params.set('search', search);
        if (mimeType) params.set('mime_type', mimeType);

        return '<?= BASE_URL ?>/api/documents/list.php?' + params.toString();
    }

    function refreshDocList() {
        htmx.ajax('GET', buildDocFilterUrl(), { target: '#documents-list', swap: 'innerHTML' });
    }

    let searchTimeout;
    document.getElementById('doc-search').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(refreshDocList, 400);
    });

    document.getElementById('doc-type-filter').addEventListener('change', refreshDocList);

    document.getElementById('btn-clear-doc-filters').addEventListener('click', function() {
        document.getElementById('doc-search').value = '';
        document.getElementById('doc-type-filter').value = '';
        refreshDocList();
    });

    // Global delete handler (called from list items)
    window.deleteDocument = async function(docId) {
        if (!confirm('Tem certeza que deseja excluir este documento?')) return;

        try {
            const resp = await fetch('<?= BASE_URL ?>/api/documents/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ document_id: docId })
            });
            const data = await resp.json();
            if (data.success) {
                window.SunyataApp.showToast('Documento excluido', 'success');
                refreshDocList();
            } else {
                window.SunyataApp.showToast(data.error || 'Erro ao excluir', 'danger');
            }
        } catch (err) {
            window.SunyataApp.showToast('Erro de rede', 'danger');
        }
    };
})();
</script>

<?php
};

include __DIR__ . '/../../src/views/layouts/user.php';
