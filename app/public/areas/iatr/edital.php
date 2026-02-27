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
    .data-disponivel { background: #f8f9fa; border: 1px solid #e6e8eb; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.92rem; }
    .data-disponivel-titulo { font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; margin-bottom: 0.4rem; }
    .estimativas-panel { font-size: 0.85rem; }
</style>
HTML;

$pageContent = function () use ($edital, $autoAnalise) {
    $csrfToken = csrf_token();

    // Enrich display data from raw_data JSONB (API returns more than we store in columns)
    $raw = is_string($edital['raw_data'] ?? null)
        ? json_decode($edital['raw_data'], true) ?: []
        : ($edital['raw_data'] ?? []);

    $municipio = $edital['municipio'] ?: ($raw['municipio_nome'] ?? '');
    $unidadeNome = $raw['unidade_nome'] ?? '';
    $unidadeCodigo = $raw['unidade_codigo'] ?? '';
    $modalidade = $edital['modalidade'] ?: ($raw['modalidade_licitacao_nome'] ?? '');
    $esfera = $raw['esfera_nome'] ?? '';
    $situacao = $raw['situacao_nome'] ?? '';
    $orgaoCnpj = $edital['orgao_cnpj'] ?: ($raw['orgao_cnpj'] ?? '');

    // Parse pncp_detalhes for enriched info
    $detalhes = is_string($edital['pncp_detalhes'] ?? null)
        ? json_decode($edital['pncp_detalhes'], true) ?: []
        : ($edital['pncp_detalhes'] ?? []);

    $modoDisputa = $detalhes['modoDisputaNome'] ?? '';
    // amparoLegal may be a nested object {nome, codigo, descricao} or a plain string
    $amparoLegalRaw = $detalhes['amparoLegal'] ?? '';
    $amparoLegal = is_array($amparoLegalRaw)
        ? ($amparoLegalRaw['nome'] ?? $amparoLegalRaw['descricao'] ?? (string)($amparoLegalRaw['codigo'] ?? ''))
        : (string)$amparoLegalRaw;
    $srp = isset($detalhes['srp']) ? ($detalhes['srp'] ? 'Sim' : 'Não') : '';
    $processo = $detalhes['processo'] ?? '';
    $infoComplementar = $detalhes['informacaoComplementar'] ?? '';
    // Skip infoComplementar if it's just a URL (it's often duplicated from linkSistemaOrigem)
    if ($infoComplementar && filter_var($infoComplementar, FILTER_VALIDATE_URL)) {
        $infoComplementar = '';
    }

    // Parse pncp_itens
    $itensRaw = is_string($edital['pncp_itens'] ?? null)
        ? json_decode($edital['pncp_itens'], true) ?: []
        : ($edital['pncp_itens'] ?? []);
    // pncp_itens is stored as a JSON array directly
    $itensList = array_is_list($itensRaw) ? $itensRaw : ($itensRaw['data'] ?? []);

    // Data availability for v4 form (section 1.8)
    $temTexto    = !empty($edital['texto_completo']) && strlen((string)$edital['texto_completo']) > 500;
    $temItens    = !empty($itensList) && count($itensList) > 0;
    $temMetaDados = !empty($detalhes);
    $emAnalise   = ($edital['status_analise'] ?? '') === 'em_analise';

    // Parse arquivos_pncp
    $arquivos = is_string($edital['arquivos_pncp'] ?? null)
        ? json_decode($edital['arquivos_pncp'], true) ?: []
        : ($edital['arquivos_pncp'] ?? []);

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
    <!-- Suporte -->
    <div class="d-flex align-items-center gap-3 mb-3 px-1 text-muted small">
        <span><i class="bi bi-headset me-1"></i>Suporte:</span>
        <a href="https://chat.whatsapp.com/KWWg7FUFShwB4V2YY4iOTi" target="_blank" rel="noopener noreferrer" class="text-success fw-semibold text-decoration-none">
            <i class="bi bi-whatsapp me-1"></i>WhatsApp
        </a>
        <a href="mailto:contato@sunyataconsulting.com" class="text-body text-decoration-none">
            <i class="bi bi-envelope me-1"></i>contato@sunyataconsulting.com
        </a>
    </div>

    <!-- Back + Header -->
    <div class="page-header mb-3">
        <a href="<?= BASE_URL ?>/areas/iatr/monitor-pncp.php" class="btn btn-ghost-primary btn-sm mb-2">
            <i class="bi bi-arrow-left"></i> Voltar ao Monitor
        </a>
        <div class="edital-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <span class="badge <?= $statusClass ?> status-badge me-2"><?= sanitize_output($edital['status']) ?></span>
                    <?php if ($modalidade): ?>
                        <span class="badge bg-info status-badge"><?= sanitize_output($modalidade) ?></span>
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
        <?php if ($unidadeNome): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-diagram-3"></i> Unidade Compradora</div>
            <div class="info-value"><?= sanitize_output($unidadeCodigo ? "$unidadeCodigo - $unidadeNome" : $unidadeNome) ?></div>
        </div>
        <?php endif; ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-geo-alt"></i> UF / Município</div>
            <div class="info-value"><?= sanitize_output(($edital['uf'] ?: '') . ($municipio ? ' - ' . $municipio : '')) ?: 'N/A' ?></div>
        </div>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-calendar-event"></i> Abertura</div>
            <div class="info-value"><?= $dataAbertura ?></div>
        </div>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-calendar-x"></i> Encerramento</div>
            <div class="info-value"><?= $dataEncerramento ?></div>
        </div>
        <?php if ($orgaoCnpj): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-card-text"></i> CNPJ</div>
            <div class="info-value"><?= sanitize_output($orgaoCnpj) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($esfera): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-bank"></i> Esfera</div>
            <div class="info-value"><?= sanitize_output($esfera) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($situacao): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-info-circle"></i> Situação PNCP</div>
            <div class="info-value"><?= sanitize_output($situacao) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($keywords): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-tags"></i> Keywords</div>
            <div class="info-value"><?= sanitize_output($keywords) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($modoDisputa): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-lightning"></i> Modo de Disputa</div>
            <div class="info-value"><?= sanitize_output($modoDisputa) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($amparoLegal): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-book"></i> Amparo Legal</div>
            <div class="info-value"><?= sanitize_output($amparoLegal) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($srp): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-arrow-repeat"></i> SRP</div>
            <div class="info-value"><?= sanitize_output($srp) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($processo): ?>
        <div class="info-item">
            <div class="info-label"><i class="bi bi-folder2"></i> Processo Administrativo</div>
            <div class="info-value"><?= sanitize_output($processo) ?></div>
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

    <!-- Informação Complementar (collapsible) -->
    <?php if ($infoComplementar): ?>
    <div class="card mt-3 mb-3">
        <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#info-complementar">
            <h3 class="card-title"><i class="bi bi-info-circle me-2"></i>Informação Complementar</h3>
            <div class="card-options"><i class="bi bi-chevron-down"></i></div>
        </div>
        <div class="collapse" id="info-complementar">
            <div class="card-body">
                <p style="white-space: pre-wrap"><?= sanitize_output($infoComplementar) ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Itens da Licitação -->
    <?php if (!empty($itensList)): ?>
    <div class="card mt-3 mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="bi bi-list-ol me-2"></i>Itens da Licitação (<?= count($itensList) ?>)</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-striped table-sm">
                <thead>
                    <tr>
                        <th style="width:50px">#</th>
                        <th>Descrição</th>
                        <th style="width:80px" class="text-end">Qtd</th>
                        <th style="width:70px">Unidade</th>
                        <th style="width:140px" class="text-end">Valor Unit. Est.</th>
                        <th style="width:140px" class="text-end">Valor Total Est.</th>
                        <th style="width:110px">Critério</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($itensList as $item):
                    $valorUnit = $item['valorUnitarioEstimado'] ?? null;
                    $valorTotal = $item['valorTotal'] ?? null;
                    $sigiloso = !empty($item['orcamentoSigiloso']);
                    $valorUnitFmt = $sigiloso ? '<span class="text-muted fst-italic">Sigiloso</span>'
                        : ($valorUnit !== null ? 'R$ ' . number_format((float)$valorUnit, 2, ',', '.') : '-');
                    $valorTotalFmt = $sigiloso ? '<span class="text-muted fst-italic">Sigiloso</span>'
                        : ($valorTotal !== null ? 'R$ ' . number_format((float)$valorTotal, 2, ',', '.') : '-');
                ?>
                    <tr>
                        <td class="text-muted"><?= (int)($item['numeroItem'] ?? 0) ?></td>
                        <td><?= sanitize_output($item['descricao'] ?? 'N/I') ?></td>
                        <td class="text-end"><?= sanitize_output((string)($item['quantidade'] ?? '-')) ?></td>
                        <td><?= sanitize_output($item['unidadeMedida'] ?? '-') ?></td>
                        <td class="text-end"><?= $valorUnitFmt ?></td>
                        <td class="text-end"><?= $valorTotalFmt ?></td>
                        <td><small><?= sanitize_output($item['criterioJulgamentoNome'] ?? '-') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Documentos -->
    <?php if (!empty($arquivos)): ?>
    <div class="card mt-3 mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="bi bi-file-earmark-text me-2"></i>Documentos (<?= count($arquivos) ?>)</h3>
        </div>
        <div class="list-group list-group-flush">
        <?php foreach ($arquivos as $arq):
            $titulo = $arq['titulo'] ?? ($arq['tipoDocumentoNome'] ?? 'Documento');
            $tipo = $arq['tipoDocumentoNome'] ?? ($arq['tipo'] ?? '');
            $downloadUrl = $arq['url'] ?? ($arq['uri'] ?? '');
            // Block non-HTTP(S) schemes — sanitize_output() won't block javascript:
            if ($downloadUrl && !preg_match('#^https?://#i', $downloadUrl)) {
                $downloadUrl = '';
            }
            $extraido = !empty($arq['extraido']);
        ?>
            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                <div>
                    <i class="bi bi-file-earmark<?= $extraido ? '-check text-success' : '' ?> me-1"></i>
                    <?= sanitize_output($titulo) ?>
                    <?php if ($tipo && $tipo !== $titulo): ?>
                        <span class="text-muted ms-1"><small>(<?= sanitize_output($tipo) ?>)</small></span>
                    <?php endif; ?>
                    <?php if ($extraido): ?>
                        <span class="badge bg-success-lt ms-1">Texto extraído</span>
                    <?php endif; ?>
                </div>
                <?php if ($downloadUrl): ?>
                <a href="<?= sanitize_output($downloadUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-download me-1"></i>Baixar
                </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
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

    <!-- Formulário de Análise v4 -->
    <div class="card mb-3" id="form-analise-card"<?= $emAnalise ? ' style="display:none"' : '' ?>>
        <div class="card-header">
            <h4 class="card-title mb-0"><i class="bi bi-robot me-2"></i>Solicitar Análise</h4>
        </div>
        <div class="card-body">
            <!-- 1.8 — Dados disponíveis -->
            <div class="data-disponivel mb-3">
                <div class="data-disponivel-titulo">Dados disponíveis para análise</div>
                <div class="d-flex flex-column gap-1">
                    <span><?= $temMetaDados ? '✅' : '⚠️' ?> Metadados do edital</span>
                    <span><?php if ($temItens): ?>✅ Itens (<?= count($itensList) ?> <?= count($itensList) === 1 ? 'item' : 'itens' ?>)<?php else: ?>❌ Itens da licitação<?php endif; ?></span>
                    <span><?= $temTexto ? '✅ Texto completo do edital' : '❌ Texto completo do edital' ?></span>
                </div>
                <?php if (!$temTexto && !$temItens): ?>
                <div class="alert alert-warning mt-2 mb-0 py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Atenção:</strong> Sem texto nem itens — a análise pode retornar resultado insuficiente.
                </div>
                <?php elseif (!$temTexto): ?>
                <div class="alert alert-info mt-2 mb-0 py-2 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Análise baseada nos metadados e itens da API PNCP (sem texto completo).
                </div>
                <?php endif; ?>
            </div>

            <!-- Tipo + Profundidade -->
            <div class="row g-3 mb-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Tipo de análise</label>
                    <select id="tipo-analise" class="form-select" onchange="onFormChange()">
                        <option value="resumo_executivo">Resumo Executivo</option>
                        <option value="habilitacao">Documentação de Habilitação</option>
                        <option value="verifica_edital">Verificação de Edital</option>
                        <option value="contratos">Contratos e Aditivos</option>
                        <option value="sg_contrato">Seguro-Garantia</option>
                    </select>
                </div>
                <div class="col-md-7" id="profundidade-wrapper">
                    <label class="form-label fw-semibold">Profundidade</label>
                    <div class="d-flex gap-3 mt-1">
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="nivel_profundidade" value="triagem" onchange="onFormChange()">
                            <span class="form-check-label">Triagem <small class="text-muted">(rápida)</small></span>
                        </label>
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="nivel_profundidade" value="resumo" checked onchange="onFormChange()">
                            <span class="form-check-label">Resumo</span>
                        </label>
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="nivel_profundidade" value="completa" onchange="onFormChange()">
                            <span class="form-check-label">Completa</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Instruções complementares -->
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Instruções complementares <span class="text-muted fw-normal">(opcional)</span>
                </label>
                <textarea id="instrucoes-complementares" class="form-control" rows="2" maxlength="1000"
                    placeholder="Ex: Foco em requisitos de TI e prazos de entrega"
                    oninput="updateCharCount()"></textarea>
                <div class="d-flex justify-content-end mt-1">
                    <small id="char-count" class="text-muted">0 / 1000</small>
                </div>
            </div>

            <!-- Botão + Estimativas -->
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <button id="btn-analisar" class="btn btn-primary" onclick="triggerAnalise()"
                    <?= (!$temTexto && !$temItens) ? 'disabled title="Dados insuficientes para análise"' : '' ?>>
                    <i class="bi bi-lightning"></i> Analisar
                </button>
                <div class="estimativas-panel px-3 py-2 rounded border bg-light d-flex gap-3 flex-wrap align-items-center">
                    <small class="text-muted fw-semibold text-uppercase" style="font-size:.7rem">Estimativa</small>
                    <small><i class="bi bi-cpu me-1 text-secondary"></i><span id="est-modelo">Claude Sonnet 4.5</span></small>
                    <small><i class="bi bi-coin me-1 text-secondary"></i><span id="est-custo">R$ 0,05</span></small>
                    <small><i class="bi bi-clock me-1 text-secondary"></i><span id="est-tempo">~45 segundos</span></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Resultado da Análise -->
    <div class="card analise-card mb-4" id="analise-section">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="card-title mb-0"><i class="bi bi-file-text"></i> Resultado</h4>
            <div id="analise-status-indicator"></div>
        </div>
        <div class="card-body" id="analise-body">
            <!-- Preenchido por JS no DOMContentLoaded -->
        </div>
    </div>

<script>
const EDITAL_ID   = <?= (int) $edital['id'] ?>;
const CSRF_TOKEN  = <?= json_encode($csrfToken) ?>;
const STATUS_URL  = <?= json_encode(BASE_URL . '/api/pncp/analise-status.php') ?>;
const TRIGGER_URL = <?= json_encode(BASE_URL . '/api/pncp/trigger-analise.php') ?>;
const AUTO_ANALISE = <?= $autoAnalise ? 'true' : 'false' ?>;
const TEM_DADOS   = <?= ($temTexto || $temItens) ? 'true' : 'false' ?>;

const TIPOS_SEM_PROFUNDIDADE = ['verifica_edital', 'contratos', 'sg_contrato'];
const ESTIMATIVAS = {
    triagem:  { modelo: 'Claude Haiku 4.5',  custo: 'R$ 0,01', tempo: '~15 segundos' },
    resumo:   { modelo: 'Claude Sonnet 4.5', custo: 'R$ 0,05', tempo: '~45 segundos' },
    completa: { modelo: 'Claude Sonnet 4.5', custo: 'R$ 0,12', tempo: '~90 segundos' },
};
const EST_FIXAS = { modelo: 'Claude Haiku 4.5', custo: 'R$ 0,02', tempo: '~20 segundos' };
const TIPO_LABELS = {
    resumo_executivo: 'Resumo Exec.', habilitacao: 'Habilitação',
    verifica_edital: 'Verif. Edital', contratos: 'Contratos', sg_contrato: 'SG Contrato'
};

let pollingInterval  = null;
let elapsedInterval  = null;
let analiseStartedAt = null;
let currentStatus    = <?= json_encode($edital['status_analise']) ?>;

const SUPORTE_HTML = `<div class="mt-2 small text-muted">
    Precisa de ajuda?
    <a href="https://chat.whatsapp.com/KWWg7FUFShwB4V2YY4iOTi" target="_blank" rel="noopener noreferrer" class="text-success fw-semibold ms-1"><i class="bi bi-whatsapp"></i> WhatsApp</a>
    <span class="mx-1">·</span>
    <a href="mailto:contato@sunyataconsulting.com" class="text-body"><i class="bi bi-envelope"></i> contato@sunyataconsulting.com</a>
</div>`;

const ERROS_HTTP = {
    401: 'Token de autenticação inválido. Contacte o suporte.',
    403: 'Acesso negado pelo serviço de análise. Contacte o suporte.',
    409: 'Análise já em andamento para este edital. Aguarde a conclusão.',
    500: 'Erro interno no servidor de análise. Tente novamente em alguns minutos.',
    502: 'Serviço de análise indisponível (N8N). Verifique se os serviços estão activos.',
    504: 'Tempo de resposta excedido. A análise pode ter sido concluída — verificando resultado...',
};

const FASES_ANALISE = [
    { ate:  10, msg: 'Iniciando análise...' },
    { ate:  30, msg: 'Verificando dados do edital...' },
    { ate:  60, msg: 'Extraindo texto dos documentos...' },
    { ate: 120, msg: 'Processando com IA (pode demorar alguns minutos)...' },
    { ate: 240, msg: 'A IA está a gerar a análise completa...' },
    { ate: 999, msg: 'Finalizando e salvando resultado...' },
];

document.addEventListener('DOMContentLoaded', function () {
    updateEstimativas();
    if (['concluida', 'erro', 'insuficiente'].includes(currentStatus)) {
        loadAnaliseResult();
    } else if (currentStatus === 'em_analise') {
        showPolling();
        startPolling();
    } else if (AUTO_ANALISE && TEM_DADOS) {
        triggerAnalise();
    }
});

// ── Form helpers ───────────────────────────────────────────

function onFormChange() {
    const tipo = document.getElementById('tipo-analise').value;
    document.getElementById('profundidade-wrapper').style.display =
        TIPOS_SEM_PROFUNDIDADE.includes(tipo) ? 'none' : '';
    updateEstimativas();
}

function updateEstimativas() {
    const tipo  = document.getElementById('tipo-analise')?.value || 'resumo_executivo';
    const nivel = document.querySelector('input[name="nivel_profundidade"]:checked')?.value || 'resumo';
    const est   = TIPOS_SEM_PROFUNDIDADE.includes(tipo) ? EST_FIXAS : (ESTIMATIVAS[nivel] || ESTIMATIVAS.resumo);
    document.getElementById('est-modelo').textContent = est.modelo;
    document.getElementById('est-custo').textContent  = est.custo;
    document.getElementById('est-tempo').textContent  = est.tempo;
}

function updateCharCount() {
    const el = document.getElementById('instrucoes-complementares');
    document.getElementById('char-count').textContent = `${el.value.length} / 1000`;
}

// ── Analysis trigger ───────────────────────────────────────

async function triggerAnalise() {
    const tipo = document.getElementById('tipo-analise')?.value || 'resumo_executivo';
    const semProfundidade = TIPOS_SEM_PROFUNDIDADE.includes(tipo);
    const nivel = semProfundidade
        ? 'completa'
        : (document.querySelector('input[name="nivel_profundidade"]:checked')?.value || 'completa');
    const instrucoes = document.getElementById('instrucoes-complementares')?.value?.trim() || '';

    const btn = document.getElementById('btn-analisar');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Enviando...';
    }

    // Mostrar spinner imediatamente — não esperar o fetch síncrono completar
    showPolling();
    startPolling();

    try {
        const payload = { edital_id: EDITAL_ID, tipo_analise: tipo, nivel_profundidade: nivel };
        if (instrucoes) payload.instrucoes_complementares = instrucoes;

        const resp = await fetch(TRIGGER_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify(payload)
        });

        if (!resp.ok) {
            const status = resp.status;
            const msg = ERROS_HTTP[status] || `Erro inesperado (HTTP ${status}). Contacte o suporte.`;
            const is504 = status === 504;
            if (!is504) {
                // Erros definitivos: parar polling e mostrar erro
                stopPolling();
                if (btn) { btn.disabled = !TEM_DADOS; btn.innerHTML = '<i class="bi bi-lightning"></i> Analisar'; }
                document.getElementById('analise-section').className = 'card analise-card erro mb-4';
                document.getElementById('analise-status-indicator').innerHTML =
                    '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Erro</span>';
                document.getElementById('analise-body').innerHTML =
                    `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>HTTP ${status}:</strong> ${escapeHtml(msg)}</div>
                    ${SUPORTE_HTML}`;
            }
            // 504: manter polling activo — análise pode ter concluído no N8N
        }
        // 200: polling já activo, resultado aparecerá automaticamente
    } catch (err) {
        // Erro de rede (ex: timeout do browser antes do PHP responder)
        stopPolling();
        if (btn) { btn.disabled = !TEM_DADOS; btn.innerHTML = '<i class="bi bi-lightning"></i> Analisar'; }
        document.getElementById('analise-section').className = 'card analise-card erro mb-4';
        document.getElementById('analise-status-indicator').innerHTML =
            '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Erro</span>';
        document.getElementById('analise-body').innerHTML =
            `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Falha de ligação:</strong> ${escapeHtml(err.message)}</div>
            ${SUPORTE_HTML}`;
    }
}

// ── Polling ────────────────────────────────────────────────

function showPolling() {
    const formCard = document.getElementById('form-analise-card');
    if (formCard) formCard.style.display = 'none';

    analiseStartedAt = Date.now();
    document.getElementById('analise-status-indicator').innerHTML =
        '<span class="polling-indicator"><span class="pulse-dot"></span> <span id="elapsed-label">Analisando...</span></span>';
    document.getElementById('analise-body').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <p class="text-secondary mb-1" id="fase-label">Iniciando análise...</p>
            <small class="text-muted">A página atualiza automaticamente. Pode demorar alguns minutos.</small>
        </div>`;
    document.getElementById('analise-section').className = 'card analise-card em-andamento mb-4';

    // Contador de tempo + fases
    if (elapsedInterval) clearInterval(elapsedInterval);
    elapsedInterval = setInterval(() => {
        const secs = Math.floor((Date.now() - analiseStartedAt) / 1000);
        const mins = Math.floor(secs / 60);
        const s    = secs % 60;
        const label = mins > 0 ? `${mins}m ${String(s).padStart(2,'0')}s` : `${secs}s`;
        const el = document.getElementById('elapsed-label');
        if (el) el.textContent = `Analisando... ${label}`;
        const fase = FASES_ANALISE.find(f => secs <= f.ate);
        const fl = document.getElementById('fase-label');
        if (fl && fase) fl.textContent = fase.msg;
    }, 1000);
}

function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(checkStatus, 5000);
}

function stopPolling() {
    if (pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; }
    if (elapsedInterval) { clearInterval(elapsedInterval); elapsedInterval = null; }
}

async function checkStatus() {
    try {
        const resp = await fetch(`${STATUS_URL}?id=${EDITAL_ID}`, { headers: { 'X-CSRF-Token': CSRF_TOKEN } });
        const data = await resp.json();
        if (['concluida', 'erro', 'insuficiente'].includes(data.status_analise)) {
            stopPolling();
            renderAnaliseResult(data);
        }
    } catch (err) { console.error('Polling error:', err); }
}

async function loadAnaliseResult() {
    try {
        const resp = await fetch(`${STATUS_URL}?id=${EDITAL_ID}`, { headers: { 'X-CSRF-Token': CSRF_TOKEN } });
        renderAnaliseResult(await resp.json());
    } catch (err) {
        document.getElementById('analise-body').innerHTML =
            `<div class="alert alert-danger">Erro ao carregar resultado: ${escapeHtml(err.message)}</div>`;
    }
}

// ── Result rendering ───────────────────────────────────────

function renderAnaliseResult(data) {
    // Restore form card and button
    const formCard = document.getElementById('form-analise-card');
    if (formCard) formCard.style.display = '';
    const btn = document.getElementById('btn-analisar');
    if (btn) { btn.disabled = !TEM_DADOS; btn.innerHTML = '<i class="bi bi-lightning"></i> Analisar'; }

    const section   = document.getElementById('analise-section');
    const indicator = document.getElementById('analise-status-indicator');
    const body      = document.getElementById('analise-body');

    if (data.status_analise === 'insuficiente') {
        section.className = 'card analise-card mb-4';
        indicator.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-info-circle"></i> Dados insuficientes</span>';
        const resultado = data.analise_resultado || {};
        const pncpUrl = typeof resultado === 'object' ? (resultado.pncp_url || '') : '';
        let html = `<div class="alert alert-warning mb-3">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Dados insuficientes para análise.</strong>
            Os documentos deste edital não estão disponíveis para extração automática.
            ${pncpUrl ? `<a href="${escapeHtml(pncpUrl)}" target="_blank" rel="noopener noreferrer" class="alert-link ms-1">Ver documentos no PNCP</a>` : ''}
        </div>`;
        body.innerHTML = html;

    } else if (data.status_analise === 'concluida') {
        section.className = 'card analise-card concluida mb-4';
        indicator.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Concluída</span>';

        const resultado = data.analise_resultado || {};
        const tipoKey = data.analise_tipo || 'resumo_executivo';
        const texto = resultado[tipoKey] || resultado.resumo_executivo || resultado.texto || resultado.markdown || JSON.stringify(resultado, null, 2);

        const meta = [];
        if (data.analise_modelo)       meta.push(data.analise_modelo.replace('claude-', '').replace(/-\d{8}$/, ''));
        if (data.analise_tipo)         meta.push(TIPO_LABELS[data.analise_tipo] || data.analise_tipo);
        if (data.analise_nivel)        meta.push({ triagem: 'Triagem', resumo: 'Resumo', completa: 'Completa' }[data.analise_nivel] || data.analise_nivel);
        if (data.analise_tokens)       meta.push(`${data.analise_tokens.toLocaleString('pt-BR')} tokens`);
        if (data.analise_concluida_em) meta.push(new Date(data.analise_concluida_em).toLocaleString('pt-BR'));

        body.innerHTML = `
            <div class="analise-resultado">${renderMarkdown(texto)}</div>
            ${meta.length ? `<hr><small class="text-muted">${meta.join(' · ')}</small>` : ''}
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
        section.className = 'card analise-card erro mb-4';
        indicator.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Erro</span>';
        body.innerHTML = `
            <div class="alert alert-danger">
                <strong>Erro na análise:</strong> ${escapeHtml(data.analise_erro || 'Erro desconhecido')}
            </div>
            <button class="btn btn-primary btn-sm mt-2" onclick="triggerAnalise()">
                <i class="bi bi-arrow-repeat"></i> Tentar novamente
            </button>
            ${SUPORTE_HTML}`;
    }
}

// ── Utilities ──────────────────────────────────────────────

function renderMarkdown(text) { return text ? marked.parse(text) : ''; }

function escapeHtml(text) {
    if (!text) return '';
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function openPdfPreview() {
    const pdfUrl = `<?= BASE_URL ?>/api/pncp/export-pdf.php?id=${EDITAL_ID}`;
    document.getElementById('pdf-iframe').src = pdfUrl;
    document.getElementById('pdf-download-link').href = pdfUrl + '&download=1';
    new bootstrap.Modal(document.getElementById('modal-pdf')).show();
}

function showEmailForm() {
    const m = bootstrap.Modal.getInstance(document.getElementById('modal-pdf'));
    if (m) m.hide();
    new bootstrap.Modal(document.getElementById('modal-email')).show();
}

async function sendAnaliseEmail() {
    const emailTo = document.getElementById('email-to').value.trim();
    const statusEl = document.getElementById('email-status');
    if (!emailTo) { statusEl.textContent = 'Informe o email destinatário.'; statusEl.className = 'alert alert-warning py-2'; return; }
    const btn = document.getElementById('btn-send-email');
    btn.disabled = true; btn.textContent = 'Enviando...';
    statusEl.textContent = ''; statusEl.className = '';
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
    btn.textContent = 'Enviar'; btn.disabled = false;
}
</script>

    <!-- Suporte rodapé -->
    <div class="d-flex align-items-center gap-3 mt-1 mb-4 px-1 text-muted small">
        <span><i class="bi bi-headset me-1"></i>Suporte:</span>
        <a href="https://chat.whatsapp.com/KWWg7FUFShwB4V2YY4iOTi" target="_blank" rel="noopener noreferrer" class="text-success fw-semibold text-decoration-none">
            <i class="bi bi-whatsapp me-1"></i>WhatsApp
        </a>
        <a href="mailto:contato@sunyataconsulting.com" class="text-body text-decoration-none">
            <i class="bi bi-envelope me-1"></i>contato@sunyataconsulting.com
        </a>
    </div>

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
