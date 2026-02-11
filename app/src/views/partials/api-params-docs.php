<?php
/**
 * Partial: Documentação de Parâmetros API (reutilizável)
 *
 * Card com lista de modelos disponíveis, docs de parâmetros e botão refresh.
 * Usado em: verticals-config.php, portal-config.php, canvas-edit.php
 *
 * Variáveis esperadas no escopo:
 *   $availableModels  — array de modelos (via ModelService::getAvailableModels())
 *   $modelCacheInfo   — array com 'updated_at' e 'count' (via ModelService::getCacheInfo())
 *
 * @package Sunyata\Views\Partials
 * @since 2026-02-06
 */
?>
<div class="card" id="apiParamsDocsCard">
    <div class="card-header bg-dark text-white">
        <strong><i class="bi bi-book"></i> Parâmetros Disponíveis</strong>
    </div>
    <div class="card-body" style="font-size: 0.9rem;">
        <dl class="mb-0">
            <dt><code>claude_model</code> <span class="badge bg-secondary">string</span></dt>
            <dd>
                Modelo Claude a usar.
                <ul class="small mb-0" style="max-height: 200px; overflow-y: auto;">
                    <?php foreach ($availableModels as $m): ?>
                        <li>
                            <code><?= htmlspecialchars($m['id']) ?></code>
                            <?php if (!empty($m['display_name'])): ?>
                                — <?= htmlspecialchars($m['display_name']) ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($availableModels)): ?>
                        <li class="text-muted">Nenhum modelo cacheado. Clique em Atualizar.</li>
                    <?php endif; ?>
                </ul>
                <small class="text-muted d-block mt-1">
                    Cache: <?= htmlspecialchars($modelCacheInfo['updated_at']) ?>
                    (<?= (int)$modelCacheInfo['count'] ?> modelos)
                    <button type="button" class="btn btn-sm btn-outline-primary ms-2 js-refresh-models-btn">
                        <i class="bi bi-arrow-clockwise"></i> Atualizar
                    </button>
                </small>
            </dd>

            <dt><code>temperature</code> <span class="badge bg-secondary">float</span></dt>
            <dd>
                Controla aleatoriedade (0.0-1.0)
                <ul class="small mb-2">
                    <li><code>0.0</code> = Muito determinístico</li>
                    <li><code>0.3</code> = Determinístico com variação mínima <span class="badge bg-success">RECOMENDADO</span></li>
                    <li><code>0.5</code> = Balanceado</li>
                    <li><code>0.7</code> = Criativo</li>
                    <li><code>1.0</code> = Muito criativo</li>
                </ul>
            </dd>

            <dt><code>max_tokens</code> <span class="badge bg-secondary">int</span></dt>
            <dd>Máximo de tokens na resposta (1-200000). Default: <code>4096</code></dd>

            <dt><code>top_p</code> <span class="badge bg-secondary">float</span></dt>
            <dd>Nucleus sampling (0.0-1.0)</dd>
        </dl>

        <div class="alert alert-warning mt-3 mb-0 py-2" style="font-size: 0.85rem;">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>temperature</strong> e <strong>top_p</strong> são mutuamente exclusivos na API Claude.
            Se ambos forem definidos, <code>temperature</code> tem prioridade.
            Use <strong>um ou outro</strong>, não ambos.
        </div>
    </div>
</div>

<script>
// Refresh models (delegated — funciona com qualquer botão .js-refresh-models-btn)
document.querySelector('.js-refresh-models-btn')?.addEventListener('click', async function() {
    this.disabled = true;
    this.innerHTML = '<i class="bi bi-hourglass-split"></i> Atualizando...';
    try {
        const res = await fetch('<?= BASE_URL ?>/api/admin/refresh-models.php', {
            method: 'POST',
            credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.success) {
            alert('Cache atualizado! ' + data.count + ' modelos encontrados.');
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Falha ao atualizar'));
        }
    } catch (e) {
        alert('Erro de rede: ' + e.message);
    }
    this.disabled = false;
    this.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Atualizar';
});
</script>
