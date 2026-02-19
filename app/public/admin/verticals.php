<?php
/**
 * Admin: Verticais - Gerenciamento
 * CRUD completo de verticais (DB-driven + config fallback)
 *
 * @package Sunyata\Admin
 * @since 2026-02-18 (Fase 3.5)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Services\VerticalService;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();
$verticalService = VerticalService::getInstance();

// Stats para admin-header.php
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

// Buscar todas as verticais (DB + config merged)
$verticais = $verticalService->getAll(true); // Force refresh

// Buscar verticais apenas do DB (para distinguir origem)
$verticaisDB = [];
try {
    $dbRows = $db->fetchAll("
        SELECT id, slug, nome, icone, descricao, ordem, disponivel,
               requer_aprovacao, max_users, api_params, created_at, updated_at
        FROM verticals
        ORDER BY ordem ASC, nome ASC
    ");
    foreach ($dbRows as $row) {
        $verticaisDB[$row['slug']] = $row;
    }
} catch (Exception $e) {
    // Tabela não existe ainda
}

// Contar canvas por vertical
$canvasStats = [];
foreach ($verticais as $slug => $vertical) {
    try {
        $result = $db->fetchOne("
            SELECT COUNT(*) as count
            FROM canvas_templates
            WHERE vertical = :slug
        ", ['slug' => $slug]);
        $canvasStats[$slug] = $result['count'] ?? 0;
    } catch (Exception $e) {
        $canvasStats[$slug] = 0;
    }
}

$pageTitle = 'Gerenciar Verticais';

// Include header
include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
    .vertical-card {
        transition: all 0.2s;
    }
    .vertical-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .source-badge {
        font-size: 0.7rem;
        padding: 2px 6px;
    }
    .drag-handle {
        cursor: move;
        color: #6c757d;
    }
    .drag-handle:hover {
        color: #0d6efd;
    }
</style>

<h1 class="mb-4">
    <i class="bi bi-collection"></i> Gerenciar Verticais
</h1>

<p class="text-muted">
    Gerencie as verticais do sistema. Verticais definem áreas temáticas com ferramentas específicas.
    <br>
    <small><strong>Fonte de verdade:</strong> Banco de dados (prioridade) + config/verticals.php (fallback)</small>
</p>

<!-- Botões de Ação -->
<div class="mb-4">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createVerticalModal">
        <i class="bi bi-plus-circle"></i> Criar Nova Vertical
    </button>
    <a href="canvas-templates.php" class="btn btn-outline-primary">
        <i class="bi bi-palette"></i> Gerenciar Canvas
    </a>
    <span class="text-muted small ms-3">
        <i class="bi bi-info-circle"></i> Total: <strong><?= count($verticais) ?></strong> verticais
    </span>
</div>

<!-- Cards de Verticais -->
<div class="row g-3" id="verticaisContainer">
    <?php foreach ($verticais as $slug => $vertical):
        $isFromDB = isset($verticaisDB[$slug]);
        $dbId = $isFromDB ? $verticaisDB[$slug]['id'] : null;
    ?>
        <div class="col-12 vertical-card" data-slug="<?= $slug ?>">
            <div class="card <?= ($vertical['disponivel'] ?? true) ? 'border-success' : 'border-secondary' ?>">
                <div class="card-body">
                    <div class="row align-items-center">
                        <!-- Drag Handle -->
                        <div class="col-auto">
                            <i class="bi bi-grip-vertical drag-handle fs-4"></i>
                        </div>

                        <!-- Info da Vertical -->
                        <div class="col-md-7">
                            <h5 class="card-title mb-2">
                                <span style="font-size: 1.5rem;"><?= $vertical['icone'] ?? '📦' ?></span>
                                <?= sanitize_output($vertical['nome']) ?>

                                <?php if ($vertical['disponivel'] ?? true): ?>
                                    <span class="badge bg-success">ATIVO</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">INATIVO</span>
                                <?php endif; ?>

                                <?php if ($isFromDB): ?>
                                    <span class="badge bg-primary source-badge">DB</span>
                                <?php else: ?>
                                    <span class="badge bg-warning source-badge">CONFIG</span>
                                <?php endif; ?>
                            </h5>

                            <p class="mb-2">
                                <strong>Slug:</strong> <code><?= $slug ?></code> |
                                <strong>Ordem:</strong> <?= $vertical['ordem'] ?? 999 ?>
                            </p>

                            <p class="text-muted small mb-2">
                                <?= sanitize_output($vertical['descricao'] ?? 'Sem descrição') ?>
                            </p>

                            <p class="mb-0 text-muted small">
                                <i class="bi bi-palette"></i>
                                <strong><?= number_format($canvasStats[$slug] ?? 0) ?></strong> canvas associados

                                <?php if (!empty($vertical['max_users'])): ?>
                                    | <i class="bi bi-people"></i> Máx: <?= $vertical['max_users'] ?> usuários
                                <?php endif; ?>

                                <?php if ($vertical['requer_aprovacao'] ?? false): ?>
                                    | <i class="bi bi-shield-check"></i> Requer aprovação
                                <?php endif; ?>
                            </p>
                        </div>

                        <!-- Ações -->
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                                <?php if ($isFromDB): ?>
                                    <!-- Ações para verticais do DB -->
                                    <button class="btn btn-primary btn-sm edit-vertical-btn"
                                            data-id="<?= $dbId ?>"
                                            data-slug="<?= $slug ?>"
                                            data-nome="<?= htmlspecialchars($vertical['nome']) ?>"
                                            data-icone="<?= htmlspecialchars($vertical['icone'] ?? '') ?>"
                                            data-descricao="<?= htmlspecialchars($vertical['descricao'] ?? '') ?>"
                                            data-ordem="<?= $vertical['ordem'] ?? 999 ?>"
                                            data-disponivel="<?= ($vertical['disponivel'] ?? true) ? '1' : '0' ?>"
                                            data-requer-aprovacao="<?= ($vertical['requer_aprovacao'] ?? false) ? '1' : '0' ?>"
                                            data-max-users="<?= $vertical['max_users'] ?? '' ?>"
                                            title="Editar Vertical">
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>

                                    <button class="btn btn-sm <?= ($vertical['disponivel'] ?? true) ? 'btn-outline-secondary' : 'btn-outline-success' ?> toggle-disponivel-btn"
                                            data-id="<?= $dbId ?>"
                                            data-disponivel="<?= ($vertical['disponivel'] ?? true) ? '1' : '0' ?>"
                                            title="<?= ($vertical['disponivel'] ?? true) ? 'Desativar' : 'Ativar' ?>">
                                        <i class="bi <?= ($vertical['disponivel'] ?? true) ? 'bi-pause-circle' : 'bi-check-circle' ?>"></i>
                                        <?= ($vertical['disponivel'] ?? true) ? 'Desativar' : 'Ativar' ?>
                                    </button>

                                    <button class="btn btn-outline-danger btn-sm delete-vertical-btn"
                                            data-id="<?= $dbId ?>"
                                            data-slug="<?= $slug ?>"
                                            data-canvas-count="<?= $canvasStats[$slug] ?? 0 ?>"
                                            title="Deletar Vertical">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <!-- Vertical do config (read-only) -->
                                    <span class="badge bg-warning">
                                        <i class="bi bi-lock"></i> Somente leitura (config file)
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($verticais)): ?>
        <div class="col-12">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> Nenhuma vertical encontrada.
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Criar Vertical -->
<div class="modal fade" id="createVerticalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="createVerticalForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle"></i> Criar Nova Vertical
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="create-slug" class="form-label">Slug <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="create-slug" name="slug" required
                                   pattern="[a-z0-9-]+" placeholder="ex: marketing-digital">
                            <small class="text-muted">Apenas letras minúsculas, números e hífens</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="create-nome" class="form-label">Nome <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="create-nome" name="nome" required
                                   placeholder="ex: Marketing Digital">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label for="create-icone" class="form-label">Ícone <span class="text-danger">*</span></label>
                            <input type="text" class="form-control text-center" id="create-icone" name="icone" required
                                   placeholder="📢" style="font-size: 1.5rem;">
                            <small class="text-muted">Emoji</small>
                        </div>

                        <div class="col-md-10 mb-3">
                            <label for="create-descricao" class="form-label">Descrição</label>
                            <textarea class="form-control" id="create-descricao" name="descricao" rows="2"
                                      placeholder="Breve descrição da vertical"></textarea>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="create-ordem" class="form-label">Ordem</label>
                            <input type="number" class="form-control" id="create-ordem" name="ordem" value="999" min="1">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="create-max-users" class="form-label">Máx. Usuários</label>
                            <input type="number" class="form-control" id="create-max-users" name="max_users"
                                   placeholder="Deixe vazio para ilimitado">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label d-block">Opções</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="create-disponivel" name="disponivel" checked>
                                <label class="form-check-label" for="create-disponivel">
                                    Disponível
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="create-requer-aprovacao" name="requer_aprovacao">
                                <label class="form-check-label" for="create-requer-aprovacao">
                                    Requer Aprovação
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Criar Vertical
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Vertical -->
<div class="modal fade" id="editVerticalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="editVerticalForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" id="edit-id" name="id">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil"></i> Editar Vertical
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit-slug" class="form-label">Slug <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit-slug" name="slug" required pattern="[a-z0-9-]+">
                            <small class="text-muted">Apenas letras minúsculas, números e hífens</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit-nome" class="form-label">Nome <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit-nome" name="nome" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label for="edit-icone" class="form-label">Ícone</label>
                            <input type="text" class="form-control text-center" id="edit-icone" name="icone"
                                   style="font-size: 1.5rem;">
                        </div>

                        <div class="col-md-10 mb-3">
                            <label for="edit-descricao" class="form-label">Descrição</label>
                            <textarea class="form-control" id="edit-descricao" name="descricao" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="edit-ordem" class="form-label">Ordem</label>
                            <input type="number" class="form-control" id="edit-ordem" name="ordem" min="1">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="edit-max-users" class="form-label">Máx. Usuários</label>
                            <input type="number" class="form-control" id="edit-max-users" name="max_users"
                                   placeholder="Vazio = ilimitado">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label d-block">Opções</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit-disponivel" name="disponivel">
                                <label class="form-check-label" for="edit-disponivel">
                                    Disponível
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit-requer-aprovacao" name="requer_aprovacao">
                                <label class="form-check-label" for="edit-requer-aprovacao">
                                    Requer Aprovação
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Salvar Alterações
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Informações Adicionais -->
<div class="card mt-4">
    <div class="card-header bg-info text-white">
        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Sobre Verticais</h6>
    </div>
    <div class="card-body">
        <h6>O que são Verticais?</h6>
        <p>
            Verticais definem áreas temáticas do sistema (ex: Jurídico, Marketing, RH). Cada vertical agrupa canvas templates relacionados
            e pode ter configurações específicas de API (model, temperature, max_tokens).
        </p>

        <h6>Fonte de Verdade Híbrida:</h6>
        <ul>
            <li><strong>Banco de Dados (prioridade):</strong> Verticais criadas via admin UI ficam na tabela <code>verticals</code></li>
            <li><strong>Config File (fallback):</strong> <code>config/verticals.php</code> - usado se tabela não existe ou vazia</li>
            <li><strong>Merge:</strong> Sistema combina ambas fontes, DB sobrescreve config quando há conflito</li>
        </ul>

        <h6>Badges de Origem:</h6>
        <ul>
            <li><span class="badge bg-primary">DB</span> - Vertical gerenciada via admin (editável)</li>
            <li><span class="badge bg-warning">CONFIG</span> - Vertical do arquivo PHP (somente leitura)</li>
        </ul>

        <h6>Funcionalidades:</h6>
        <ul>
            <li>✅ Criar verticais via GUI (sem código)</li>
            <li>✅ Ativar/Desativar verticais</li>
            <li>✅ Drag-to-reorder (em breve)</li>
            <li>✅ Limite de usuários por vertical</li>
            <li>✅ Aprovação opcional para acesso</li>
            <li>🔄 API Params por vertical (próxima versão)</li>
        </ul>
    </div>
</div>

<script>
// ============================================================================
// Admin Verticais - JavaScript
// ============================================================================

const createModal = new bootstrap.Modal(document.getElementById('createVerticalModal'));
const editModal = new bootstrap.Modal(document.getElementById('editVerticalModal'));

// ========================================================================
// 1. CRIAR VERTICAL
// ========================================================================
document.getElementById('createVerticalForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const formData = new FormData(e.target);
    const data = {
        csrf_token: formData.get('csrf_token'),
        slug: formData.get('slug'),
        nome: formData.get('nome'),
        icone: formData.get('icone'),
        descricao: formData.get('descricao') || '',
        ordem: parseInt(formData.get('ordem')) || 999,
        max_users: formData.get('max_users') ? parseInt(formData.get('max_users')) : null,
        disponivel: formData.get('disponivel') === 'on',
        requer_aprovacao: formData.get('requer_aprovacao') === 'on',
    };

    try {
        const response = await fetch('<?= BASE_URL ?>/api/verticals/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });

        const result = await response.json();

        if (result.success) {
            showToast('✅ ' + result.message, 'success');
            createModal.hide();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('❌ ' + result.error, 'danger');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('❌ Erro de conexão', 'danger');
    }
});

// ========================================================================
// 2. EDITAR VERTICAL
// ========================================================================
document.querySelectorAll('.edit-vertical-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('edit-id').value = this.dataset.id;
        document.getElementById('edit-slug').value = this.dataset.slug;
        document.getElementById('edit-nome').value = this.dataset.nome;
        document.getElementById('edit-icone').value = this.dataset.icone;
        document.getElementById('edit-descricao').value = this.dataset.descricao;
        document.getElementById('edit-ordem').value = this.dataset.ordem;
        document.getElementById('edit-max-users').value = this.dataset.maxUsers;
        document.getElementById('edit-disponivel').checked = this.dataset.disponivel === '1';
        document.getElementById('edit-requer-aprovacao').checked = this.dataset.requerAprovacao === '1';

        editModal.show();
    });
});

document.getElementById('editVerticalForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const formData = new FormData(e.target);
    const data = {
        csrf_token: formData.get('csrf_token'),
        id: parseInt(formData.get('id')),
        slug: formData.get('slug'),
        nome: formData.get('nome'),
        icone: formData.get('icone'),
        descricao: formData.get('descricao'),
        ordem: parseInt(formData.get('ordem')),
        max_users: formData.get('max_users') ? parseInt(formData.get('max_users')) : null,
        disponivel: formData.get('disponivel') === 'on',
        requer_aprovacao: formData.get('requer_aprovacao') === 'on',
    };

    try {
        const response = await fetch('<?= BASE_URL ?>/api/verticals/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });

        const result = await response.json();

        if (result.success) {
            showToast('✅ ' + result.message, 'success');
            editModal.hide();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('❌ ' + result.error, 'danger');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('❌ Erro de conexão', 'danger');
    }
});

// ========================================================================
// 3. TOGGLE DISPONÍVEL
// ========================================================================
document.querySelectorAll('.toggle-disponivel-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = parseInt(this.dataset.id);
        const currentDisponivel = this.dataset.disponivel === '1';
        const newDisponivel = !currentDisponivel;

        try {
            const response = await fetch('<?= BASE_URL ?>/api/verticals/update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: '<?= csrf_token() ?>',
                    id: id,
                    disponivel: newDisponivel,
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast('✅ ' + result.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('❌ ' + result.error, 'danger');
            }
        } catch (error) {
            console.error('Error:', error);
            showToast('❌ Erro de conexão', 'danger');
        }
    });
});

// ========================================================================
// 4. DELETAR VERTICAL
// ========================================================================
document.querySelectorAll('.delete-vertical-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = parseInt(this.dataset.id);
        const slug = this.dataset.slug;
        const canvasCount = parseInt(this.dataset.canvasCount);

        if (canvasCount > 0) {
            alert(`❌ Não é possível deletar esta vertical.\n\nExistem ${canvasCount} canvas associados. Remova ou reassocie os canvas antes de deletar.`);
            return;
        }

        if (!confirm(`Deseja realmente deletar a vertical "${slug}"?\n\nEsta ação é IRREVERSÍVEL!`)) {
            return;
        }

        try {
            const response = await fetch('<?= BASE_URL ?>/api/verticals/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: '<?= csrf_token() ?>',
                    id: id,
                    hard_delete: false, // Soft delete
                }),
            });

            const result = await response.json();

            if (result.success) {
                showToast('✅ ' + result.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('❌ ' + result.error, 'danger');
            }
        } catch (error) {
            console.error('Error:', error);
            showToast('❌ Erro de conexão', 'danger');
        }
    });
});

// ========================================================================
// HELPER: Toast de Notificação
// ========================================================================
function showToast(message, type = 'info') {
    let toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
        toastContainer.style.zIndex = '9999';
        document.body.appendChild(toastContainer);
    }

    const toastId = 'toast-' + Date.now();
    const toastHTML = `
        <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;

    toastContainer.insertAdjacentHTML('beforeend', toastHTML);

    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, { delay: 4000 });
    toast.show();

    toastElement.addEventListener('hidden.bs.toast', () => toastElement.remove());
}
</script>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
