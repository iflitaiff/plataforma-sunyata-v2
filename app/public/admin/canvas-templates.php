<?php
/**
 * Admin: Canvas Templates - Listagem
 * Gerenciamento de Canvas Templates (formulários dinâmicos)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

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
    // Table doesn't exist or query failed - set to 0
    $stats['pending_requests'] = 0;
}

// Buscar todos os Canvas Templates
$canvasTemplates = $db->fetchAll("
    SELECT
        id,
        slug,
        name,
        vertical,
        max_questions,
        is_active,
        created_at,
        updated_at
    FROM canvas_templates
    ORDER BY vertical ASC, name ASC
");

// Contar conversas por canvas (para estatísticas)
// CORRIGIDO: Usar $statsResult ao invés de $stats para evitar sobrescrita
$canvasStats = [];
foreach ($canvasTemplates as $canvas) {
    $statsResult = $db->fetchOne("
        SELECT COUNT(*) as total_conversations
        FROM conversations
        WHERE canvas_id = :canvas_id
    ", ['canvas_id' => $canvas['id']]);

    $canvasStats[$canvas['id']] = $statsResult['total_conversations'] ?? 0;
}

$pageTitle = 'Canvas Templates';

// Include header
include __DIR__ . '/../../src/views/admin-header.php';
?>

<h1 class="mb-4">Canvas Templates</h1>

<p class="text-muted">
    Gerencie os Canvas (formulários dinâmicos) usados na plataforma. Cada Canvas define os campos do formulário e os prompts enviados ao Claude.
</p>

<!-- Botões de Ação -->
<div class="mb-4">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCanvasModal">
        <i class="bi bi-plus-circle"></i> Criar Canvas do Zero
    </button>
    <a href="canvas-import.php" class="btn btn-success">
        <i class="bi bi-upload"></i> Importar JSON do Creator
    </a>
    <a href="verticals.php" class="btn btn-outline-secondary">
        <i class="bi bi-collection"></i> Gerenciar Verticais
    </a>
    <span class="text-muted small ms-2 d-block mt-2">
        <strong>Criar do Zero:</strong> Canvas básico para editar depois |
        <strong>Importar JSON:</strong> Canvas completo do Survey Creator
    </span>
</div>

<!-- Campo de Busca -->
<div class="mb-4">
    <div class="input-group" style="max-width: 500px;">
        <span class="input-group-text">
            <i class="bi bi-search"></i>
        </span>
        <input
            type="text"
            id="searchCanvas"
            class="form-control"
            placeholder="Buscar por nome ou slug..."
            aria-label="Buscar Canvas"
        >
        <button class="btn btn-outline-secondary" type="button" id="clearSearch">
            <i class="bi bi-x"></i>
        </button>
    </div>
    <small class="text-muted">
        <span id="searchResults"></span>
    </small>
</div>

<!-- Cards de Canvas -->
<div class="row g-4" id="canvasContainer">
    <?php foreach ($canvasTemplates as $canvas): ?>
        <div class="col-12 canvas-card"
             data-canvas-name="<?= strtolower(sanitize_output($canvas['name'])) ?>"
             data-canvas-slug="<?= strtolower($canvas['slug']) ?>"
             data-canvas-vertical="<?= $canvas['vertical'] ?>">
            <div class="card <?= $canvas['is_active'] ? 'border-success' : 'border-secondary' ?>">
                <div class="card-body">
                    <div class="row align-items-center">
                        <!-- Info do Canvas -->
                        <div class="col-md-8">
                            <h5 class="card-title mb-2">
                                <?= sanitize_output($canvas['name']) ?>
                                <?php if ($canvas['is_active']): ?>
                                    <span class="badge bg-success">ATIVO</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">INATIVO</span>
                                <?php endif; ?>
                            </h5>

                            <p class="mb-2">
                                <strong>Slug:</strong> <code><?= sanitize_output($canvas['slug']) ?></code> |
                                <strong>Vertical:</strong>
                                <span class="vertical-edit-wrapper" data-canvas-id="<?= $canvas['id'] ?>">
                                    <select class="form-select form-select-sm d-inline-block w-auto vertical-selector"
                                            data-canvas-id="<?= $canvas['id'] ?>"
                                            data-current-vertical="<?= $canvas['vertical'] ?>">
                                        <?php
                                        $verticals = require __DIR__ . '/../../config/verticals.php';
                                        foreach ($verticals as $v_key => $v_data):
                                        ?>
                                            <option value="<?= $v_key ?>" <?= $canvas['vertical'] === $v_key ? 'selected' : '' ?>>
                                                <?= $v_data['icone'] ?> <?= $v_data['nome'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="spinner-border spinner-border-sm ms-2 d-none vertical-loading"></span>
                                </span> |
                                <strong>Máx. Perguntas:</strong> <?= $canvas['max_questions'] ?>
                            </p>

                            <p class="mb-2">
                                <i class="bi bi-calendar"></i> Criado em: <?= date('d/m/Y H:i', strtotime($canvas['created_at'])) ?><br>
                                <i class="bi bi-clock-history"></i> Atualizado em: <?= date('d/m/Y H:i', strtotime($canvas['updated_at'])) ?>
                            </p>

                            <p class="mb-0 text-muted small">
                                <i class="bi bi-chat-dots"></i>
                                <strong><?= number_format($canvasStats[$canvas['id']] ?? 0) ?></strong> conversas realizadas
                            </p>
                        </div>

                        <!-- Ações -->
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                                <a href="canvas-edit.php?id=<?= $canvas['id'] ?>" class="btn btn-primary btn-sm">
                                    <i class="bi bi-pencil"></i> Editar
                                </a>
                                <a href="canvas-editor.php?id=<?= $canvas['id'] ?>" class="btn btn-success btn-sm">
                                    <i class="bi bi-palette"></i> Editor Visual
                                </a>
                                <button class="btn btn-sm <?= $canvas['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?> toggle-active-btn"
                                        data-canvas-id="<?= $canvas['id'] ?>"
                                        data-is-active="<?= $canvas['is_active'] ? '1' : '0' ?>"
                                        title="<?= $canvas['is_active'] ? 'Desativar Canvas' : 'Ativar Canvas' ?>">
                                    <i class="bi <?= $canvas['is_active'] ? 'bi-pause-circle' : 'bi-check-circle' ?>"></i>
                                    <?= $canvas['is_active'] ? 'Desativar' : 'Ativar' ?>
                                    <span class="spinner-border spinner-border-sm ms-1 d-none"></span>
                                </button>
                                <button class="btn btn-outline-primary btn-sm clone-canvas-btn"
                                        data-canvas-id="<?= $canvas['id'] ?>"
                                        data-canvas-name="<?= sanitize_output($canvas['name']) ?>"
                                        data-canvas-slug="<?= $canvas['slug'] ?>"
                                        data-canvas-vertical="<?= $canvas['vertical'] ?>"
                                        title="Clonar este Canvas">
                                    <i class="bi bi-files"></i> Clonar
                                </button>
                                <button class="btn btn-outline-info btn-sm edit-system-prompt-btn"
                                        data-canvas-id="<?= $canvas['id'] ?>"
                                        data-canvas-name="<?= sanitize_output($canvas['name']) ?>"
                                        title="Editar System Prompt">
                                    <i class="bi bi-code-square"></i> System Prompt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($canvasTemplates)): ?>
        <div class="col-12">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> Nenhum Canvas Template encontrado. Execute a migration 004_mvp_console.sql.
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Clonagem -->
<div class="modal fade" id="cloneCanvasModal" tabindex="-1" aria-labelledby="cloneCanvasModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="canvas-clone-process.php" id="cloneCanvasForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="source_id" id="cloneSourceId">

                <div class="modal-header">
                    <h5 class="modal-title" id="cloneCanvasModalLabel">
                        <i class="bi bi-files"></i> Clonar Canvas
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Canvas de origem:</strong> <span id="cloneSourceName"></span>
                    </div>

                    <div class="mb-3">
                        <label for="cloneNewSlug" class="form-label">
                            Novo Slug <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="cloneNewSlug"
                               name="new_slug"
                               required
                               pattern="[a-z0-9-]+"
                               placeholder="ex: iatr-analise-contratos-v2">
                        <small class="text-muted">
                            Apenas letras minúsculas, números e hífens. Deve ser único.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label for="cloneNewName" class="form-label">
                            Nome do Canvas <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="cloneNewName"
                               name="new_name"
                               required
                               placeholder="ex: 🧪 Análise de Contratos v2 (IATR Test)">
                        <small class="text-muted">
                            Nome descritivo que aparecerá na interface.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label for="cloneNewVertical" class="form-label">
                            Vertical de Destino <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="cloneNewVertical" name="new_vertical" required>
                            <?php
                            $verticals = require __DIR__ . '/../../config/verticals.php';
                            foreach ($verticals as $v_key => $v_data):
                            ?>
                                <option value="<?= $v_key ?>">
                                    <?= $v_data['icone'] ?> <?= $v_data['nome'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input"
                               type="checkbox"
                               id="cloneIsActive"
                               name="is_active"
                               checked>
                        <label class="form-check-label" for="cloneIsActive">
                            Ativar Canvas imediatamente
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-files"></i> Clonar Canvas
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de Edição de System Prompt -->
<div class="modal fade" id="systemPromptModal" tabindex="-1" aria-labelledby="systemPromptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="systemPromptModalLabel">
                    <i class="bi bi-code-square"></i> Editar System Prompt
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Canvas:</strong> <span id="systemPromptCanvasName"></span> (<code id="systemPromptCanvasId"></code>)
                </div>

                <div class="mb-3">
                    <label for="systemPromptTextarea" class="form-label">
                        <strong>System Prompt</strong>
                        <small class="text-muted">(Instruções enviadas ao Claude sobre como se comportar)</small>
                    </label>
                    <textarea class="form-control font-monospace"
                              id="systemPromptTextarea"
                              rows="20"
                              style="font-size: 13px; line-height: 1.6;"
                              placeholder="Digite o system prompt aqui..."></textarea>
                    <small class="text-muted">
                        <span id="systemPromptCharCount">0</span> caracteres
                    </small>
                </div>

                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Atenção:</strong> Alterações no System Prompt afetam TODAS as novas requisições deste Canvas.
                    Teste bem antes de salvar em Canvas ativos.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" id="saveSystemPromptBtn">
                    <i class="bi bi-save"></i> Salvar System Prompt
                    <span class="spinner-border spinner-border-sm ms-1 d-none"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Criar Canvas do Zero -->
<div class="modal fade" id="createCanvasModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="canvas-create-process.php" id="createCanvasForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle"></i> Criar Canvas do Zero
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Canvas Básico:</strong> Formulário será criado vazio.
                        Use o <strong>Editor Visual</strong> depois para adicionar campos.
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="canvas-nome" class="form-label">
                                Nome do Canvas <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="canvas-nome" name="nome" required
                                   placeholder="ex: Análise de Contratos">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="canvas-slug" class="form-label">
                                Slug <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="canvas-slug" name="slug" required
                                   pattern="[a-z0-9-]+" placeholder="ex: analise-contratos">
                            <small class="text-muted">Apenas letras minúsculas, números e hífens</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="canvas-vertical" class="form-label">
                            Vertical <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="canvas-vertical" name="vertical" required>
                            <option value="">Selecione...</option>
                            <?php
                            $verticals = require __DIR__ . '/../../config/verticals.php';
                            foreach ($verticals as $v_key => $v_data):
                            ?>
                                <option value="<?= $v_key ?>">
                                    <?= $v_data['icone'] ?> <?= $v_data['nome'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="canvas-system-prompt" class="form-label">
                            System Prompt (Opcional)
                        </label>
                        <textarea class="form-control font-monospace" id="canvas-system-prompt"
                                  name="system_prompt" rows="6"
                                  placeholder="Opcional - deixe vazio para usar prompts da vertical"
                                  style="font-size: 0.9rem;"></textarea>
                        <small class="text-muted">
                            <i class="bi bi-lightbulb"></i> Vazio = usa hierarquia de prompts (Portal → Vertical → ajSystemPrompt)
                        </small>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="canvas-is-active" name="is_active" checked>
                        <label class="form-check-label" for="canvas-is-active">
                            Ativar Canvas imediatamente
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Criar Canvas
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Informações Adicionais -->
<div class="card mt-4">
    <div class="card-header bg-info text-white">
        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Sobre Canvas Templates</h6>
    </div>
    <div class="card-body">
        <h6>O que é um Canvas?</h6>
        <p>
            Um Canvas é uma ferramenta interativa que guia o usuário através de campos específicos para coletar informações
            contextuais. Essas informações são então processadas por prompts otimizados enviados ao Claude API.
        </p>

        <h6>Componentes de um Canvas:</h6>
        <ul>
            <li><strong>Form Config (JSON):</strong> Define os campos do formulário usando SurveyJS</li>
            <li><strong>System Prompt:</strong> Instruções enviadas ao Claude sobre como se comportar</li>
            <li><strong>User Prompt Template:</strong> Template com placeholders (ex: <code>{{tarefa}}</code>) preenchidos com dados do formulário</li>
            <li><strong>Max Questions:</strong> Número máximo de perguntas contextuais que Claude pode fazer (padrão: 5)</li>
        </ul>

        <h6>Funcionalidades Disponíveis:</h6>
        <ul>
            <li>✅ Editar Canvas existentes (form config, prompts)</li>
            <li>✅ <strong>Importar JSON do Survey Creator</strong> - Crie Canvas automaticamente</li>
            <li>✅ Auto-geração de prompts (quando user_prompt_template está vazio)</li>
            <li>✅ System Prompt via JSON (propriedade <code>ajSystemPrompt</code>)</li>
            <li>✅ <strong>Buscar Canvas</strong> por nome ou slug (filtro em tempo real)</li>
            <li>✅ <strong>Ativar/Desativar Canvas</strong> com um clique (AJAX)</li>
            <li>✅ <strong>Alterar Vertical</strong> via dropdown inline (AJAX)</li>
            <li>✅ <strong>Clonar Canvas</strong> - Duplique templates para outras verticais</li>
        </ul>

        <h6>Roadmap - Próximas Versões:</h6>
        <ul>
            <li>🔄 Editor visual integrado (SurveyJS Creator embarcado)</li>
            <li>🔄 Versionamento completo com histórico</li>
            <li>🔄 Filtrar por vertical (tabs)</li>
            <li>🔄 Deletar Canvas com confirmação</li>
            <li>🔄 Dashboard de estatísticas</li>
        </ul>
    </div>
</div>

<script>
// ============================================================================
// Admin Canvas Templates - JavaScript Interativo
// ============================================================================

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchCanvas');
    const clearSearchBtn = document.getElementById('clearSearch');
    const searchResults = document.getElementById('searchResults');
    const canvasCards = document.querySelectorAll('.canvas-card');

    // ========================================================================
    // 1. BUSCA / FILTRO
    // ========================================================================
    function filterCanvas() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        let visibleCount = 0;
        let totalCount = canvasCards.length;

        canvasCards.forEach(card => {
            const name = card.dataset.canvasName;
            const slug = card.dataset.canvasSlug;

            if (searchTerm === '' || name.includes(searchTerm) || slug.includes(searchTerm)) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        // Atualizar contador
        if (searchTerm === '') {
            searchResults.textContent = '';
        } else {
            searchResults.textContent = `Mostrando ${visibleCount} de ${totalCount} canvas`;
        }
    }

    searchInput.addEventListener('input', filterCanvas);

    clearSearchBtn.addEventListener('click', () => {
        searchInput.value = '';
        filterCanvas();
        searchInput.focus();
    });

    // ========================================================================
    // 2. TOGGLE ACTIVE/INACTIVE
    // ========================================================================
    document.querySelectorAll('.toggle-active-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const canvasId = this.dataset.canvasId;
            const isActive = this.dataset.isActive === '1';
            const spinner = this.querySelector('.spinner-border');
            const icon = this.querySelector('i');

            // Desabilitar botão durante request
            this.disabled = true;
            spinner.classList.remove('d-none');

            try {
                const response = await fetch('<?= BASE_URL ?>/api/canvas/toggle-active.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ canvas_id: canvasId })
                });

                const data = await response.json();

                if (data.success) {
                    // Atualizar UI
                    const newState = data.is_active;
                    this.dataset.isActive = newState ? '1' : '0';

                    // Atualizar classes do botão
                    if (newState) {
                        this.classList.remove('btn-outline-success');
                        this.classList.add('btn-outline-secondary');
                        icon.classList.remove('bi-check-circle');
                        icon.classList.add('bi-pause-circle');
                        this.innerHTML = '<i class="bi bi-pause-circle"></i> Desativar <span class="spinner-border spinner-border-sm ms-1 d-none"></span>';
                        this.title = 'Desativar Canvas';
                    } else {
                        this.classList.remove('btn-outline-secondary');
                        this.classList.add('btn-outline-success');
                        icon.classList.remove('bi-pause-circle');
                        icon.classList.add('bi-check-circle');
                        this.innerHTML = '<i class="bi bi-check-circle"></i> Ativar <span class="spinner-border spinner-border-sm ms-1 d-none"></span>';
                        this.title = 'Ativar Canvas';
                    }

                    // Atualizar borda do card
                    const card = this.closest('.card');
                    if (newState) {
                        card.classList.remove('border-secondary');
                        card.classList.add('border-success');
                    } else {
                        card.classList.remove('border-success');
                        card.classList.add('border-secondary');
                    }

                    // Atualizar badge no título
                    const badge = card.querySelector('.card-title .badge');
                    if (newState) {
                        badge.classList.remove('bg-secondary');
                        badge.classList.add('bg-success');
                        badge.textContent = 'ATIVO';
                    } else {
                        badge.classList.remove('bg-success');
                        badge.classList.add('bg-secondary');
                        badge.textContent = 'INATIVO';
                    }

                    // Mostrar mensagem de sucesso
                    showToast(data.message, 'success');
                } else {
                    showToast(data.error || 'Erro ao alternar status', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Erro de conexão ao alternar status', 'danger');
            } finally {
                this.disabled = false;
                spinner.classList.add('d-none');
            }
        });
    });

    // ========================================================================
    // 3. ALTERAR VERTICAL
    // ========================================================================
    document.querySelectorAll('.vertical-selector').forEach(select => {
        const originalValue = select.value;

        select.addEventListener('change', async function() {
            const canvasId = this.dataset.canvasId;
            const newVertical = this.value;
            const currentVertical = this.dataset.currentVertical;
            const wrapper = this.closest('.vertical-edit-wrapper');
            const spinner = wrapper.querySelector('.vertical-loading');

            // Se não mudou, ignorar
            if (newVertical === currentVertical) {
                return;
            }

            // Confirmar mudança
            if (!confirm(`Deseja alterar a vertical deste Canvas para "${this.options[this.selectedIndex].text}"?`)) {
                this.value = currentVertical;
                return;
            }

            // Desabilitar select durante request
            this.disabled = true;
            spinner.classList.remove('d-none');

            try {
                const response = await fetch('<?= BASE_URL ?>/api/canvas/update-vertical.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        canvas_id: canvasId,
                        vertical: newVertical
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Atualizar data attribute
                    this.dataset.currentVertical = newVertical;

                    // Atualizar data attribute no card (para filtro)
                    const card = this.closest('.canvas-card');
                    card.dataset.canvasVertical = newVertical;

                    showToast(data.message, 'success');
                } else {
                    // Reverter mudança
                    this.value = currentVertical;
                    showToast(data.error || 'Erro ao alterar vertical', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                this.value = currentVertical;
                showToast('Erro de conexão ao alterar vertical', 'danger');
            } finally {
                this.disabled = false;
                spinner.classList.add('d-none');
            }
        });
    });

    // ========================================================================
    // 4. CLONAR CANVAS (MODAL)
    // ========================================================================
    const cloneModal = new bootstrap.Modal(document.getElementById('cloneCanvasModal'));

    document.querySelectorAll('.clone-canvas-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const canvasId = this.dataset.canvasId;
            const canvasName = this.dataset.canvasName;
            const canvasSlug = this.dataset.canvasSlug;
            const canvasVertical = this.dataset.canvasVertical;

            // Preencher formulário
            document.getElementById('cloneSourceId').value = canvasId;
            document.getElementById('cloneSourceName').textContent = canvasName;

            // Sugerir novo slug (adicionar -copy)
            const suggestedSlug = canvasSlug + '-copy';
            document.getElementById('cloneNewSlug').value = suggestedSlug;

            // Sugerir novo nome (adicionar "Cópia de")
            const suggestedName = 'Cópia de ' + canvasName;
            document.getElementById('cloneNewName').value = suggestedName;

            // Pré-selecionar mesma vertical
            document.getElementById('cloneNewVertical').value = canvasVertical;

            // Abrir modal
            cloneModal.show();

            // Focar no campo slug
            setTimeout(() => {
                document.getElementById('cloneNewSlug').select();
            }, 500);
        });
    });

    // ========================================================================
    // MODAL: Editar System Prompt
    // ========================================================================
    const systemPromptModal = new bootstrap.Modal(document.getElementById('systemPromptModal'));
    const systemPromptTextarea = document.getElementById('systemPromptTextarea');
    const systemPromptCharCount = document.getElementById('systemPromptCharCount');
    const saveSystemPromptBtn = document.getElementById('saveSystemPromptBtn');
    let currentEditingCanvasId = null;

    // Atualizar contador de caracteres
    systemPromptTextarea.addEventListener('input', function() {
        systemPromptCharCount.textContent = this.value.length.toLocaleString();
    });

    // Abrir modal ao clicar em "System Prompt"
    document.querySelectorAll('.edit-system-prompt-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const canvasId = this.dataset.canvasId;
            const canvasName = this.dataset.canvasName;

            currentEditingCanvasId = canvasId;

            // Preencher header do modal
            document.getElementById('systemPromptCanvasName').textContent = canvasName;
            document.getElementById('systemPromptCanvasId').textContent = '#' + canvasId;

            // Buscar system prompt atual via AJAX
            try {
                const response = await fetch(`<?= BASE_URL ?>/api/canvas/get-system-prompt.php?id=${canvasId}`);
                const data = await response.json();

                if (data.success) {
                    systemPromptTextarea.value = data.system_prompt || '';
                    systemPromptCharCount.textContent = (data.system_prompt || '').length.toLocaleString();
                    systemPromptModal.show();
                } else {
                    alert('Erro ao carregar system prompt: ' + (data.error || 'Erro desconhecido'));
                }
            } catch (error) {
                console.error('Erro ao buscar system prompt:', error);
                alert('Erro ao carregar system prompt. Verifique o console.');
            }
        });
    });

    // Salvar system prompt
    saveSystemPromptBtn.addEventListener('click', async function() {
        if (!currentEditingCanvasId) {
            alert('Erro: Canvas ID não definido');
            return;
        }

        const newSystemPrompt = systemPromptTextarea.value.trim();
        const spinner = this.querySelector('.spinner-border');

        // Desabilitar botão e mostrar spinner
        this.disabled = true;
        spinner.classList.remove('d-none');

        try {
            const response = await fetch('<?= BASE_URL ?>/api/canvas/update-system-prompt.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    canvas_id: currentEditingCanvasId,
                    system_prompt: newSystemPrompt,
                    csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                })
            });

            const data = await response.json();

            if (data.success) {
                showToast('✅ System Prompt atualizado com sucesso!', 'success');
                systemPromptModal.hide();
            } else {
                showToast('❌ Erro: ' + (data.error || 'Erro desconhecido'), 'danger');
            }
        } catch (error) {
            console.error('Erro ao salvar system prompt:', error);
            showToast('❌ Erro ao salvar. Verifique o console.', 'danger');
        } finally {
            // Reabilitar botão e esconder spinner
            this.disabled = false;
            spinner.classList.add('d-none');
        }
    });

    // ========================================================================
    // HELPER: Toast de Notificação
    // ========================================================================
    function showToast(message, type = 'info') {
        // Criar toast container se não existir
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }

        // Criar toast
        const toastId = 'toast-' + Date.now();
        const toastHTML = `
            <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;

        toastContainer.insertAdjacentHTML('beforeend', toastHTML);

        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { delay: 4000 });
        toast.show();

        // Remover do DOM após esconder
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
});
</script>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
