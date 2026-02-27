<?php
/**
 * Componente: Formulário de Análise IATR v4
 *
 * Incluído em edital.php. Espera a variável $edital definida no escopo.
 * Permite selecionar tipo de análise, nível de profundidade e instruções complementares.
 */

// Validação defensiva dos dados do edital para o bloco de indicadores
$textoCompleto = $edital['texto_completo'] ?? '';
$temTexto = !empty($textoCompleto) && strlen($textoCompleto) > 500;

$itensRaw = is_string($edital['pncp_itens'] ?? null)
    ? json_decode($edital['pncp_itens'], true) ?: []
    : ($edital['pncp_itens'] ?? []);
$itensList = array_is_list($itensRaw) ? $itensRaw : ($itensRaw['data'] ?? []);
$temItens = !empty($itensList) && count($itensList) > 0;

$detalhes = is_string($edital['pncp_detalhes'] ?? null)
    ? json_decode($edital['pncp_detalhes'], true) ?: []
    : ($edital['pncp_detalhes'] ?? []);
$temMetaDados = !empty($detalhes);

$statusAnalise = $edital['status_analise'] ?? '';
$isEmAnalise = $statusAnalise === 'em_analise';
$podeAnalisar = $temTexto || $temItens; // Se não tem texto nem itens, não dá para analisar (Modo C forçado)

$csrfToken = csrf_token(); // Assumindo que a função está disponível via config.php
?>

<div class="card analise-card mb-4" id="analise-section">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0"><i class="bi bi-robot text-primary"></i> Solicitar Análise IA</h4>
        <div id="analise-status-indicator">
            <?php if ($isEmAnalise): ?>
                <span class="polling-indicator"><span class="pulse-dot"></span> Analisando...</span>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card-body" id="analise-body">
        
        <?php if ($isEmAnalise): ?>
            <div class="text-center py-4">
                <div class="spinner-border text-primary mb-3" role="status"></div>
                <p class="text-secondary">A IA está a analisar este edital. Aguarde...</p>
                <small class="text-muted">A página atualiza automaticamente a cada 5 segundos.</small>
            </div>
        <?php else: ?>
        
            <!-- Bloco 1: Indicadores de Dados -->
            <div class="alert <?= $podeAnalisar ? 'alert-info' : 'alert-warning' ?> mb-4">
                <h4 class="alert-title"><i class="bi bi-info-circle me-2"></i>Dados disponíveis para análise</h4>
                <div class="d-flex flex-column gap-1 mt-2">
                    <div>
                        <?= $temMetaDados ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?>
                        Metadados do edital
                    </div>
                    <div>
                        <?= $temItens ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?>
                        Itens (<?= count($itensList) ?> itens)
                    </div>
                    <div>
                        <?= $temTexto ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?>
                        Texto completo do edital
                    </div>
                </div>
                
                <?php if (!$podeAnalisar): ?>
                    <div class="mt-3 text-danger fw-bold">
                        <i class="bi bi-exclamation-triangle"></i> Dados insuficientes para análise. Os documentos não estão disponíveis.
                    </div>
                <?php elseif (!$temTexto && $temItens): ?>
                    <div class="mt-3 text-warning-emphasis">
                        <i class="bi bi-exclamation-triangle"></i> <strong>Atenção:</strong> O texto do edital não está disponível. A análise será baseada apenas nos metadados e itens da API do PNCP (Modo Parcial).
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bloco 2: Formulário -->
            <form id="form-analise-v4" onsubmit="submitAnalise(event)">
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Tipo de análise</label>
                        <select class="form-select" id="input-tipo-analise" onchange="updateFormVisibility()">
                            <option value="resumo_executivo" selected>Resumo Executivo</option>
                            <option value="habilitacao">Documentação de Habilitação</option>
                            <option value="verifica_edital">Verificação de Edital</option>
                            <option value="contratos">Contratos e Aditivos</option>
                            <option value="sg_contrato">Seguro-Garantia</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3" id="grupo-profundidade">
                    <label class="form-label fw-bold">Profundidade</label>
                    <div class="form-selectgroup">
                        <label class="form-selectgroup-item">
                            <input type="radio" name="nivel_profundidade" value="triagem" class="form-selectgroup-input" onchange="updateEstimativas()">
                            <span class="form-selectgroup-label">Triagem</span>
                        </label>
                        <label class="form-selectgroup-item">
                            <input type="radio" name="nivel_profundidade" value="resumo" class="form-selectgroup-input" checked onchange="updateEstimativas()">
                            <span class="form-selectgroup-label">Resumo</span>
                        </label>
                        <label class="form-selectgroup-item">
                            <input type="radio" name="nivel_profundidade" value="completa" class="form-selectgroup-input" onchange="updateEstimativas()">
                            <span class="form-selectgroup-label">Completa</span>
                        </label>
                    </div>
                    <div class="form-hint mt-1" id="hint-profundidade">
                        Ideal para leitura rápida. Foca nos pontos essenciais e riscos.
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Instruções complementares (opcional)</label>
                    <textarea class="form-control" id="input-instrucoes" rows="3" maxlength="1000" oninput="updateCharCount()" placeholder="Ex: Foco em requisitos de ar condicionado e prazos de entrega"></textarea>
                    <div class="form-hint text-end mt-1"><span id="char-count">0</span>/1000</div>
                </div>

                <div class="card bg-light mb-4">
                    <div class="card-body p-3">
                        <div class="row align-items-center text-secondary">
                            <div class="col-auto">
                                <i class="bi bi-cpu fs-2"></i>
                            </div>
                            <div class="col">
                                <div class="row">
                                    <div class="col-sm-4">
                                        <div class="fw-bold text-dark">Modelo</div>
                                        <div id="est-modelo">Claude Sonnet 4.5</div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="fw-bold text-dark">Custo estimado</div>
                                        <div id="est-custo">R$ 0,05</div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="fw-bold text-dark">Tempo estimado</div>
                                        <div id="est-tempo">~45 segundos</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="btn-submit-analise" <?= !$podeAnalisar ? 'disabled' : '' ?>>
                    <i class="bi bi-lightning"></i> Analisar com IA
                </button>
            </form>
            
        <?php endif; ?>
    </div>
</div>

<script>
// Bloco 3: Lógica JS (Polling e Submissão)
const EDITAL_ID = <?= (int) $edital['id'] ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const TRIGGER_URL = <?= json_encode(BASE_URL . '/api/pncp/trigger-analise.php') ?>;
const STATUS_URL = <?= json_encode(BASE_URL . '/api/pncp/analise-status.php') ?>;

let pollingInterval = null;
let currentStatus = <?= json_encode($statusAnalise) ?>;

const ESTIMATIVAS = {
    triagem:  { modelo: 'Claude Haiku 4.5',  custo: 'R$ 0,01', tempo: '~15 segundos', hint: 'Resposta direta e concisa: vale ou não vale investir tempo neste edital.' },
    resumo:   { modelo: 'Claude Sonnet 4.5', custo: 'R$ 0,05', tempo: '~45 segundos', hint: 'Ideal para leitura rápida. Foca nos pontos essenciais, valores e riscos.' },
    completa: { modelo: 'Claude Sonnet 4.5', custo: 'R$ 0,12', tempo: '~90 segundos', hint: 'Análise exaustiva cobrindo todas as seções mapeadas.' },
    default:  { modelo: 'Claude Haiku 4.5',  custo: 'R$ 0,02', tempo: '~20 segundos', hint: 'Análise focada em extração estruturada de dados.' }
};

document.addEventListener('DOMContentLoaded', function() {
    if (currentStatus === 'em_analise') {
        startPolling();
    } else {
        updateFormVisibility(); // Initialize estimates and visibility
    }
});

function updateCharCount() {
    const textarea = document.getElementById('input-instrucoes');
    if(textarea) {
        document.getElementById('char-count').textContent = textarea.value.length;
    }
}

function updateFormVisibility() {
    const tipoSelect = document.getElementById('input-tipo-analise');
    if(!tipoSelect) return;
    
    const tipo = tipoSelect.value;
    const grupoProfundidade = document.getElementById('grupo-profundidade');
    
    // Ocultar profundidade para tipos estruturados
    if (['verifica_edital', 'contratos', 'sg_contrato'].includes(tipo)) {
        grupoProfundidade.style.display = 'none';
    } else {
        grupoProfundidade.style.display = 'block';
    }
    
    updateEstimativas();
}

function updateEstimativas() {
    const tipo = document.getElementById('input-tipo-analise').value;
    let est = ESTIMATIVAS.default;
    
    if (!['verifica_edital', 'contratos', 'sg_contrato'].includes(tipo)) {
        const profundidade = document.querySelector('input[name="nivel_profundidade"]:checked').value;
        est = ESTIMATIVAS[profundidade];
        document.getElementById('hint-profundidade').textContent = est.hint;
    }
    
    document.getElementById('est-modelo').textContent = est.modelo;
    document.getElementById('est-custo').textContent = est.custo;
    document.getElementById('est-tempo').textContent = est.tempo;
}

async function submitAnalise(e) {
    e.preventDefault();
    
    const btn = document.getElementById('btn-submit-analise');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Analisando...';
    
    const tipoAnalise = document.getElementById('input-tipo-analise').value;
    const instrucoes = document.getElementById('input-instrucoes').value.trim();
    
    let nivelProfundidade = 'completa'; // fallback para backend
    if (!['verifica_edital', 'contratos', 'sg_contrato'].includes(tipoAnalise)) {
        nivelProfundidade = document.querySelector('input[name="nivel_profundidade"]:checked').value;
    }

    const payload = {
        edital_id: EDITAL_ID,
        tipo_analise: tipoAnalise,
        nivel_profundidade: nivelProfundidade
    };
    
    if (instrucoes) {
        payload.instrucoes_complementares = instrucoes;
    }

    try {
        const resp = await fetch(TRIGGER_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        });

        if (!resp.ok) {
            const data = await resp.json().catch(() => ({}));
            throw new Error(data.error || 'Erro HTTP ' + resp.status);
        }

        showPollingUI();
        startPolling();

    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-lightning"></i> Analisar com IA';
        
        // Remove existing error if any
        const existingAlert = document.getElementById('analise-submit-error');
        if (existingAlert) existingAlert.remove();
        
        const alertHtml = `<div class="alert alert-danger mt-3" id="analise-submit-error"><i class="bi bi-exclamation-triangle"></i> Erro ao disparar análise: ${escapeHtml(err.message)}</div>`;
        document.getElementById('form-analise-v4').insertAdjacentHTML('beforeend', alertHtml);
    }
}

function showPollingUI() {
    document.getElementById('analise-status-indicator').innerHTML = '<span class="polling-indicator"><span class="pulse-dot"></span> Analisando...</span>';
    document.getElementById('analise-body').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <p class="text-secondary">A IA está a analisar este edital de acordo com a sua solicitação. Aguarde...</p>
            <small class="text-muted">A página atualiza automaticamente a cada 5 segundos.</small>
        </div>`;
    
    const section = document.getElementById('analise-section');
    section.classList.add('em-andamento');
    section.classList.remove('concluida', 'erro');
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

        // Check if status is a terminal state
        if (['concluida', 'erro', 'insuficiente'].includes(data.status_analise)) {
            clearInterval(pollingInterval);
            pollingInterval = null;
            // A página edital.php original usa renderAnaliseResult(data). 
            // Para garantir que funciona, verificamos se a função existe no escopo global
            if (typeof renderAnaliseResult === 'function') {
                renderAnaliseResult(data);
            } else {
                // Fallback: recarregar a página para ver o resultado
                window.location.reload();
            }
        }
    } catch (err) {
        console.error('Polling error:', err);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
