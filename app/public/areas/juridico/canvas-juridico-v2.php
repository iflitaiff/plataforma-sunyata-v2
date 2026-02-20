<?php
/**
 * Canvas Jurídico v2 - SurveyJS com Menu de Seleção
 * Versão moderna com 12 templates especializados + Canvas Livre
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// IMPORTANTE FIX: Revalidar vertical no banco (não confiar apenas na sessão)
$db = Database::getInstance();
$user = $db->fetchOne("
    SELECT selected_vertical, is_demo, access_level
    FROM users
    WHERE id = :user_id
", ['user_id' => $_SESSION['user_id']]);

if (!$user) {
    $_SESSION['error'] = 'Usuário não encontrado';
    redirect(BASE_URL . '/login.php');
}

// Atualizar sessão com dados do banco (fonte confiável)
$_SESSION['user']['selected_vertical'] = $user['selected_vertical'];
$_SESSION['user']['is_demo'] = $user['is_demo'];
$_SESSION['user']['access_level'] = $user['access_level'];

// Verificar acesso à vertical
if (!$user['selected_vertical']) {
    $_SESSION['error'] = 'Por favor, complete o onboarding primeiro';
    redirect(BASE_URL . '/onboarding-step1.php');
}

$user_vertical = $user['selected_vertical'];
$is_demo = $user['is_demo'] ?? false;
$is_admin = ($user['access_level'] ?? 'guest') === 'admin';

// Verificar se tem acesso (vertical juridico OU usuário demo)
if ($user_vertical !== 'juridico' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta ferramenta';
    redirect(BASE_URL . '/dashboard.php');
}

// Detectar modo: menu ou formulário específico
$selected_aj = $_GET['aj'] ?? null;
$show_menu = ($selected_aj === null || $selected_aj === 'menu');

// Detectar modo debug (para mostrar prompts)
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

// Verificar Mock Mode da sessão (sobrescreve .env)
$mock_mode_active = $_SESSION['canvas_mock_mode'] ?? false;

$canvas = null;
$formConfig = null;

if (!$show_menu) {
    // Carregar template específico (Phase 3.5: via junction table)
    $canvas = $db->fetchOne("
        SELECT ct.id, ct.slug, ct.name, ct.form_config, ct.system_prompt, ct.user_prompt_template, ct.max_questions
        FROM canvas_templates ct
        INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
        WHERE ct.slug = :slug AND cva.vertical_slug = 'juridico' AND ct.is_active = TRUE
    ", ['slug' => $selected_aj]);

    if (!$canvas) {
        $_SESSION['error'] = 'Template não encontrado: ' . sanitize_output($selected_aj);
        redirect(BASE_URL . '/areas/juridico/canvas-juridico-v2.php');
    }

    // Decodificar form_config JSON
    $formConfig = json_decode($canvas['form_config'], true);
}

// Se modo menu, buscar todos os templates
$all_templates = [];
if ($show_menu) {
    $all_templates = $db->fetchAll("
        SELECT ct.id, ct.slug, ct.name
        FROM canvas_templates ct
        INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
        WHERE cva.vertical_slug = 'juridico' AND ct.is_active = TRUE AND ct.slug != 'juridico-geral'
        ORDER BY
            CASE ct.slug
                WHEN 'juridico-livre' THEN 99
                ELSE ct.id
            END
    ");
}

$pageTitle = $show_menu ? 'Canvas Jurídico v2 (Beta)' : $canvas['name'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize_output($pageTitle) ?> - <?= APP_NAME ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <?php if (!$show_menu): ?>
    <!-- SurveyJS CSS (apenas quando mostrar formulário) - v2.4.1 (latest stable) -->
    <link href="https://unpkg.com/survey-core@2.4.1/survey-core.min.css" rel="stylesheet">

    <!-- Marked.js for Markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>

    <!-- DOMPurify for HTML sanitization -->
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>

    <!-- PDF export agora é server-side (mpdf) via /api/canvas/export-pdf.php -->
    <?php endif; ?>

    <style>
        body {
            background: linear-gradient(135deg, #1a365d 0%, #2d5a87 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container-custom {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .header h1 {
            color: #1a365d;
            margin-bottom: 10px;
        }

        .header p {
            color: #6c757d;
            font-size: 1.1rem;
        }

        /* Canvas description com destaque */
        .header .canvas-description {
            display: inline-block;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1565c0;
            padding: 10px 20px;
            border-radius: 8px;
            border-left: 4px solid #1976d2;
            font-size: 1rem;
            font-weight: 500;
            margin-top: 8px;
        }

        /* Navegação superior */
        .canvas-nav {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .canvas-nav .btn {
            margin: 5px;
        }

        /* Menu de seleção */
        .aj-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .aj-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 25px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .aj-card:hover {
            border-color: #1a365d;
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .aj-card.special {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }

        .aj-card.special:hover {
            transform: translateY(-5px) scale(1.02);
        }

        .aj-card-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .aj-card-title {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .aj-card-badge {
            display: inline-block;
            padding: 5px 10px;
            background: #e9ecef;
            border-radius: 5px;
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 10px;
        }

        .aj-card.special .aj-card-badge {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        /* SurveyJS container */
        #surveyContainer {
            margin-top: 20px;
            width: 100% !important;
            max-width: 100% !important;
        }

        #surveyContainer .sd-root-modern {
            width: 100% !important;
            max-width: 100% !important;
        }

        #surveyContainer .sd-body {
            width: 100% !important;
            max-width: 100% !important;
        }

        #surveyContainer .sd-page {
            width: 100% !important;
            max-width: 100% !important;
        }

        /* Ocultar apenas título e descrição da survey inteira (não dos campos individuais) */
        .sd-root-modern__title,
        .sd-root-modern__description {
            display: none !important;
        }

        /* Garantir que elementos HTML, perguntas e help texts sejam sempre visíveis */
        .sd-element--html,
        .sd-element--html .sd-html,
        .sd-question,
        .sd-question__title,
        .sd-question__description {
            display: block !important;
        }

        /* Fix: Garantir que elementos HTML (primeiro elemento) não apareçam truncados */
        .sd-element--html {
            min-height: auto !important;
            height: auto !important;
        }

        .sd-element--html .sd-html {
            min-height: auto !important;
            height: auto !important;
            overflow: visible !important;
            max-height: none !important;
        }

        /* Estilizar títulos e descrições dos campos */
        .sd-question__title {
            font-weight: 600;
            font-size: 1.1rem;
            color: #1a365d;
            margin-bottom: 8px;
        }

        .sd-question__description {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 10px;
        }

        /* Melhorar aparência dos inputs */
        .sd-input,
        .sd-text,
        .sd-comment {
            border: 1px solid #ced4da !important;
            border-radius: 6px !important;
            padding: 10px !important;
            font-size: 1rem !important;
            transition: border-color 0.2s ease !important;
        }

        /* Input de texto (single line) maior e mais visível */
        .sd-input,
        .sd-text {
            padding: 14px 16px !important;
            font-size: 1.05rem !important;
            min-height: 50px !important;
            line-height: 1.5 !important;
        }

        /* Textarea mantém padding original */
        .sd-comment {
            padding: 12px 16px !important;
            font-size: 1rem !important;
            line-height: 1.6 !important;
        }

        .sd-input:focus,
        .sd-text:focus,
        .sd-comment:focus {
            border-color: #1a365d !important;
            box-shadow: 0 0 0 0.2rem rgba(26, 54, 93, 0.15) !important;
            outline: none !important;
        }

        /* Espaçamento entre perguntas */
        .sd-question {
            margin-bottom: 30px !important;
            padding: 20px !important;
            background: #f8f9fa !important;
            border-radius: 10px !important;
            border-left: 4px solid #1a365d !important;
        }

        /* Character counter */
        .sd-question__content .sd-remaining-character-counter {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }

        #surveyContainer .sd-progress__text {
            font-size: 0 !important;
        }

        /* ========================================
           HELP TEXT FORMATADO (HTML)
           ======================================== */

        /* Container do Help Text */
        .help-text-formatted {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 16px 20px;
            border-radius: 8px;
            border-left: 4px solid #1a365d;
            margin-bottom: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        /* Ícone de ajuda */
        .help-icon {
            font-size: 1.3rem;
            margin-right: 10px;
            vertical-align: middle;
            display: inline-block;
        }

        /* Conteúdo do help text */
        .help-content {
            display: inline-block;
            vertical-align: middle;
            width: calc(100% - 40px);
            line-height: 1.6;
            color: #495057;
        }

        /* Títulos de seção no help text */
        .help-title {
            color: #1a365d;
            font-weight: 600;
            font-size: 0.95rem;
        }

        /* Seções do help text */
        .help-section {
            margin-bottom: 12px;
        }

        .help-section:last-child {
            margin-bottom: 0;
        }

        /* Listas no help text */
        .help-list {
            margin: 8px 0 0 20px;
            padding: 0;
            list-style-type: none;
        }

        .help-list li {
            position: relative;
            padding-left: 20px;
            margin-bottom: 6px;
            line-height: 1.5;
        }

        .help-list li:before {
            content: "→";
            position: absolute;
            left: 0;
            color: #1a365d;
            font-weight: bold;
        }

        /* Help text simples (fallback) */
        .help-text-simple {
            color: #6c757d;
            line-height: 1.6;
        }

        /* ========================================
           MELHORIAS VISUAIS GERAIS
           ======================================== */

        /* Melhorar aparência do card da pergunta */
        .sd-question {
            background: #ffffff !important;
            border: 1px solid #e0e0e0 !important;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08) !important;
            transition: all 0.3s ease !important;
        }

        .sd-question:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12) !important;
            transform: translateY(-1px);
        }

        /* Título da pergunta mais destacado */
        .sd-question__title {
            font-weight: 700 !important;
            font-size: 1.15rem !important;
            color: #1a365d !important;
            margin-bottom: 12px !important;
        }

        /* Asterisco de campo obrigatório */
        .sd-question__title .sd-question__required-text {
            color: #dc3545;
            font-weight: bold;
        }

        /* Description do campo */
        .sd-question__description {
            font-size: 0.92rem !important;
            line-height: 1.7 !important;
            margin-bottom: 15px !important;
        }

        /* Botão de submit mais atrativo */
        .sd-btn {
            background: linear-gradient(135deg, #1a365d 0%, #2d5a87 100%) !important;
            border: none !important;
            padding: 14px 32px !important;
            font-size: 1.05rem !important;
            font-weight: 600 !important;
            border-radius: 8px !important;
            box-shadow: 0 4px 12px rgba(26, 54, 93, 0.3) !important;
            transition: all 0.3s ease !important;
            color: #ffffff !important;  /* Texto branco para contrastar com fundo azul escuro */
        }

        .sd-btn:hover {
            background: linear-gradient(135deg, #0f2847 0%, #1a365d 100%) !important;
            box-shadow: 0 6px 16px rgba(26, 54, 93, 0.4) !important;
            transform: translateY(-2px);
            color: #ffffff !important;  /* Manter branco no hover */
        }

        /* Progress bar mais bonita */
        .sd-progress {
            background: #e9ecef !important;
            border-radius: 10px !important;
            overflow: hidden !important;
            height: 8px !important;
        }

        .sd-progress__bar {
            background: linear-gradient(90deg, #1a365d 0%, #2d5a87 100%) !important;
            border-radius: 10px !important;
        }

        #surveyContainer .sd-progress__text::after {
            content: "Respondidas 0 de 7 perguntas";
            font-size: 14px;
        }

        #resultContainer {
            display: none;
            margin-top: 30px;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 5px solid #28a745;
        }

        #resultContainer h3 {
            color: #28a745;
            margin-bottom: 20px;
        }

        #claudeResponse {
            background: white;
            padding: 30px;
            border-radius: 8px;
            font-family: 'Georgia', 'Times New Roman', serif;
            font-size: 1.05rem;
            line-height: 1.8;
            color: #2c3e50;
        }

        /* Tipografia aprimorada para resposta markdown */
        #claudeResponse h1,
        #claudeResponse h2,
        #claudeResponse h3,
        #claudeResponse h4 {
            color: #1a365d;
            font-weight: 600;
            margin-top: 1.5em;
            margin-bottom: 0.75em;
            line-height: 1.3;
        }

        #claudeResponse h1 {
            font-size: 1.8rem;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0.3em;
        }

        #claudeResponse h2 {
            font-size: 1.5rem;
            border-bottom: 1px solid #f1f3f5;
            padding-bottom: 0.3em;
        }

        #claudeResponse h3 {
            font-size: 1.3rem;
        }

        #claudeResponse h4 {
            font-size: 1.1rem;
        }

        #claudeResponse p {
            margin-bottom: 1.2em;
            text-align: justify;
        }

        #claudeResponse ul,
        #claudeResponse ol {
            margin-bottom: 1.2em;
            padding-left: 2em;
        }

        #claudeResponse li {
            margin-bottom: 0.5em;
        }

        #claudeResponse strong {
            font-weight: 700;
            color: #1a365d;
        }

        #claudeResponse em {
            font-style: italic;
            color: #495057;
        }

        #claudeResponse code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            color: #e83e8c;
        }

        #claudeResponse pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #1a365d;
            overflow-x: auto;
            margin-bottom: 1.2em;
        }

        #claudeResponse pre code {
            background: none;
            padding: 0;
            color: inherit;
        }

        #claudeResponse blockquote {
            border-left: 4px solid #1a365d;
            padding-left: 1em;
            margin-left: 0;
            margin-bottom: 1.2em;
            color: #6c757d;
            font-style: italic;
        }

        #claudeResponse a {
            color: #1a365d;
            text-decoration: underline;
        }

        #claudeResponse a:hover {
            color: #0f2847;
        }

        #claudeResponse hr {
            border: none;
            border-top: 1px solid #dee2e6;
            margin: 2em 0;
        }

        .loading {
            text-align: center;
            padding: 40px;
        }

        .loading .spinner-border {
            width: 3rem;
            height: 3rem;
            color: #1a365d;
        }

        .btn-voltar {
            margin-top: 20px;
        }

        .alert-custom {
            margin-top: 20px;
        }

        /* Debug box styles */
        #debugContainer {
            margin-top: 30px;
            margin-bottom: 30px;
        }

        .debug-box {
            background: #e7f3ff !important;
            border: 2px solid #0066cc !important;
            border-radius: 10px !important;
        }

        .debug-box h4 {
            color: #0066cc;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .debug-section-title {
            color: #333;
            font-size: 1rem;
            font-weight: 600;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        .debug-prompt {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            max-height: 300px;
            overflow-y: auto;
            line-height: 1.5;
            color: #333;
        }

        .debug-metadata {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .debug-badge {
            padding: 8px 12px;
            background: #ffffff;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #333;
        }

        .debug-badge strong {
            color: #0066cc;
        }

        /* Export actions styling */
        .export-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            margin-bottom: 20px;
        }

        .export-actions .btn {
            padding: 10px 20px;
            font-weight: 500;
        }

        /* Mock Mode Toggle Button */
        #mockModeToggle {
            transition: all 0.3s ease;
        }

        #mockModeToggle.active {
            background: #ffc107 !important;
            border-color: #ffc107 !important;
            color: #000 !important;
        }

        #mockModeToggle:hover {
            opacity: 0.85;
        }

        /* Rodapé de Suporte */
        .support-footer {
            margin-top: 50px;
            padding: 30px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-top: 3px solid #1a365d;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }

        .support-footer h4 {
            color: #1a365d;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .support-footer p {
            color: #495057;
            margin-bottom: 15px;
            font-size: 0.95rem;
        }

        .support-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .support-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #ffffff;
            color: #1a365d;
            text-decoration: none;
            border-radius: 8px;
            border: 2px solid #1a365d;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .support-link:hover {
            background: #1a365d;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(26, 54, 93, 0.2);
        }

        .support-link.whatsapp:hover {
            background: #25D366;
            border-color: #25D366;
        }

        .support-link.email:hover {
            background: #0d6efd;
            border-color: #0d6efd;
        }

        @media (max-width: 768px) {
            .support-links {
                flex-direction: column;
                align-items: center;
            }

            .support-link {
                width: 100%;
                max-width: 300px;
                justify-content: center;
            }
        }

        /* Print-friendly styles */
        @media print {
            body {
                background: white !important;
            }

            .container-custom {
                box-shadow: none !important;
                padding: 20px !important;
            }

            .canvas-nav,
            .btn-voltar,
            .export-actions,
            #debugContainer {
                display: none !important;
            }

            #resultContainer h3 {
                color: #000 !important;
            }

            #claudeResponse {
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <!-- Navegação Superior -->
        <div class="canvas-nav">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="<?= BASE_URL ?>/areas/juridico/" class="btn btn-sm btn-outline-secondary">
                        ← Voltar para Vertical Jurídico
                    </a>
                    <?php if (!$show_menu): ?>
                    <a href="<?= BASE_URL ?>/areas/juridico/canvas-juridico-v2.php" class="btn btn-sm btn-outline-primary">
                        📋 Menu de Atividades
                    </a>
                    <?php endif; ?>
                </div>
                <div>
                    <button id="mockModeToggle" class="btn btn-sm btn-outline-warning" onclick="toggleMockMode()" title="Ativar/desativar modo teste (economiza créditos Claude)">
                        🧪 Modo Teste: <span id="mockModeStatus">Desativado</span>
                    </button>
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-sm btn-outline-info">
                        🏠 Dashboard
                    </a>
                    <a href="<?= BASE_URL ?>/profile.php" class="btn btn-sm btn-outline-info">
                        👤 Sua Conta
                    </a>
                    <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-danger">
                        🚪 Sair
                    </a>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="header">
            <?php if ($show_menu): ?>
            <h1><?= sanitize_output($pageTitle) ?></h1>
            <p>Escolha o tipo de análise jurídica que deseja realizar</p>
            <?php endif; ?>
            <!-- Quando formulário específico: title/description nativos do SurveyJS -->
        </div>

        <?php if ($mock_mode_active): ?>
        <!-- Modo Teste Ativo -->
        <div id="mockModeAlert" class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>⚠️ MODO TESTE ATIVO</strong><br>
            Respostas simuladas (Lorem ipsum). Não consome créditos da API Claude.<br>
            <small>Clique no botão "🧪 Modo Teste" acima para desativar</small>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if ($show_menu): ?>
        <!-- Menu de Seleção de Atividades Jurídicas -->
        <div class="aj-grid">
            <?php
            $icons = [
                'juridico-due-diligence' => '📊',
                'juridico-contratos' => '📄',
                'juridico-jurisprudencia' => '⚖️',
                'juridico-parecer' => '📝',
                'juridico-peticao' => '✍️',
                'juridico-compliance' => '🛡️',
                'juridico-precedentes' => '📚',
                'juridico-memorando' => '📋',
                'juridico-viabilidade' => '🏗️',
                'juridico-organizacao' => '🗂️',
                'juridico-riscos' => '⚠️',
                'juridico-estruturacao' => '🏢',
                'juridico-livre' => '✨'
            ];

            foreach ($all_templates as $template):
                $is_livre = ($template['slug'] === 'juridico-livre');
                $card_class = $is_livre ? 'aj-card special' : 'aj-card';
                $icon = $icons[$template['slug']] ?? '📌';
            ?>
            <a href="?aj=<?= urlencode($template['slug']) ?>" class="<?= $card_class ?>">
                <div class="aj-card-icon"><?= $icon ?></div>
                <div class="aj-card-title"><?= sanitize_output($template['name']) ?></div>
                <?php if ($is_livre): ?>
                <span class="aj-card-badge">Canvas em Branco</span>
                <?php else: ?>
                <span class="aj-card-badge">Template Especializado</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Formulário Específico -->
        <!-- Survey Container -->
        <div id="surveyContainer"></div>

        <!-- Loading State -->
        <div id="loadingContainer" class="loading" style="display: none;">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Processando...</span>
            </div>
            <p class="mt-3">Analisando com Claude AI... Isso pode levar alguns segundos.</p>
        </div>

        <?php if ($debug_mode): ?>
        <!-- Debug Container (only in debug mode) -->
        <div id="debugContainer" style="display: none;">
            <div class="alert alert-info debug-box">
                <h4>🛠️ DEBUG - Prompt Enviado</h4>

                <h5 class="debug-section-title">System Prompt:</h5>
                <pre id="debugSystemPrompt" class="debug-prompt"></pre>

                <h5 class="debug-section-title">User Prompt:</h5>
                <pre id="debugUserPrompt" class="debug-prompt"></pre>

                <h5 class="debug-section-title">Metadata:</h5>
                <div id="debugMetadata" class="debug-metadata"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Result Container -->
        <div id="resultContainer">
            <h3>📋 Análise Jurídica - Claude AI</h3>
            <div id="claudeResponse"></div>

            <!-- Export Actions -->
            <div class="export-actions">
                <button id="btnExportPDF" class="btn btn-success">
                    📄 Baixar PDF
                </button>
                <button id="btnCopyText" class="btn btn-info ms-2">
                    📋 Copiar Texto
                </button>
                <button id="btnSendEmail" class="btn btn-primary ms-2">
                    📧 Enviar por Email
                </button>
                <span class="text-muted ms-3">
                    <small>Resposta formatada para impressão</small>
                </span>
            </div>

            <a href="?aj=<?= urlencode($selected_aj) ?>" class="btn btn-primary btn-voltar">
                ← Nova Análise (Mesmo Template)
            </a>
            <a href="<?= BASE_URL ?>/areas/juridico/canvas-juridico-v2.php" class="btn btn-secondary btn-voltar">
                📋 Voltar ao Menu
            </a>
        </div>

        <!-- Error Container -->
        <div id="errorContainer" style="display: none;">
            <div class="alert alert-danger alert-custom">
                <h4 class="alert-heading">Erro ao processar</h4>
                <p id="errorMessage"></p>
                <hr>
                <button class="btn btn-danger" onclick="window.location.reload()">Tentar Novamente</button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Support Footer -->
        <div class="support-footer">
            <h4>💬 Precisa de ajuda?</h4>
            <p>Para reportar erros, esclarecer dúvidas e sugestões, entre em contato:</p>
            <div class="support-links">
                <a href="https://chat.whatsapp.com/HEyyAyoS4bb6ycTMs0kLWq?mode=wwc" class="support-link whatsapp" target="_blank" rel="noopener">
                    📱 WhatsApp - Grupo de Suporte
                </a>
                <a href="mailto:contato@sunyataconsulting.com" class="support-link email">
                    📧 contato@sunyataconsulting.com
                </a>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <?php if (!$show_menu): ?>
    <!-- SurveyJS (apenas quando mostrar formulário) - v2.4.1 (latest stable) -->
    <script src="https://unpkg.com/survey-core@2.4.1/survey.core.min.js"></script>
    <script src="https://unpkg.com/survey-js-ui@2.4.1/survey-js-ui.min.js"></script>

    <!-- SurveyJS Commercial License -->
    <script src="<?= BASE_URL ?>/assets/js/surveyjs-license.js"></script>

    <script>
        console.log('Script started');

        // Verificar se SurveyJS carregou
        if (typeof Survey === 'undefined') {
            console.error('SurveyJS library not loaded!');
            document.getElementById('surveyContainer').innerHTML = '<div class="alert alert-danger">Erro: Biblioteca SurveyJS não carregou. Verifique sua conexão com internet.</div>';
        } else {
            console.log('SurveyJS loaded OK');

            try {
                // Configuração do formulário SurveyJS do banco
                const surveyJson = <?= json_encode($formConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                console.log('Survey config loaded:', surveyJson);

                // Criar survey
                const survey = new Survey.Model(surveyJson);
                console.log('Survey model created');

        // ========== AUTO-SAVE COM LOCALSTORAGE (Sprint 3.5) ==========
        const canvasId = '<?= $canvas['slug'] ?>';
        const userId = <?= $_SESSION['user_id'] ?>;
        const DRAFT_KEY = `canvas_draft_${canvasId}_${userId}`;

        // Função para salvar rascunho
        function saveDraft() {
            const draft = {
                data: survey.data,
                pageNo: survey.currentPageNo,
                timestamp: Date.now()
            };
            localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
            console.log('✅ Rascunho salvo automaticamente');
        }

        // Auto-save a cada mudança de valor
        survey.onValueChanged.add((sender, options) => {
            saveDraft();
        });

        // Auto-save ao mudar de página
        survey.onCurrentPageChanged.add((sender, options) => {
            saveDraft();
        });

        // Restaurar rascunho ao carregar
        (function restoreDraft() {
            try {
                const draftStr = localStorage.getItem(DRAFT_KEY);
                if (!draftStr) return;

                const draft = JSON.parse(draftStr);
                const maxAge = 7 * 24 * 60 * 60 * 1000; // 7 dias

                if (Date.now() - draft.timestamp > maxAge) {
                    console.log('Rascunho expirado, removendo...');
                    localStorage.removeItem(DRAFT_KEY);
                    return;
                }

                // Verificar se há dados
                if (!draft.data || Object.keys(draft.data).length === 0) {
                    return;
                }

                // Perguntar ao usuário
                const draftDate = new Date(draft.timestamp).toLocaleString('pt-BR');
                const msg = `Encontramos um rascunho salvo em ${draftDate}.\n\nDeseja continuar de onde parou?`;

                if (confirm(msg)) {
                    survey.data = draft.data;
                    if (draft.pageNo !== undefined) {
                        survey.currentPageNo = draft.pageNo;
                    }
                    console.log('✅ Rascunho restaurado');
                } else {
                    // Usuário recusou, remover rascunho
                    localStorage.removeItem(DRAFT_KEY);
                    console.log('Rascunho descartado pelo usuário');
                }
            } catch (error) {
                console.error('Erro ao restaurar rascunho:', error);
                localStorage.removeItem(DRAFT_KEY);
            }
        })();
        // ========== FIM AUTO-SAVE ==========

        // Configurar upload de arquivos
        survey.onUploadFiles.add(function (sender, options) {
            const formData = new FormData();
            options.files.forEach(file => {
                formData.append('file', file);
            });

            fetch('<?= BASE_URL ?>/api/canvas/upload-file.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    options.callback('error', [{ file: options.files[0], error: data.error }]);
                } else {
                    options.callback('success', [data.file]);
                }
            })
            .catch(error => {
                options.callback('error', [{ file: options.files[0], error: error.message }]);
            });
        });

        // ========== APLICAR TEMA PROFISSIONAL (Sprint 3.5) ==========
        survey.applyTheme({
            "themeName": "layered-light",
            "colorPalette": "light",
            "cssVariables": {
                "--sjs-primary-backcolor": "#1a365d",
                "--sjs-primary-forecolor": "#ffffff",
                "--sjs-primary-backcolor-dark": "#0f1f3d",
                "--sjs-primary-backcolor-light": "#2d5a87",
                "--sjs-general-backcolor": "#f8f9fa",
                "--sjs-border-default": "#dee2e6",
                "--sjs-font-family": "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                "--sjs-corner-radius": "8px",
                "--sjs-base-unit": "8px"
            }
        });
        console.log('✅ Tema profissional aplicado');

        // Renderizar no container
        survey.render(document.getElementById("surveyContainer"));

        // Handler quando completar
        survey.onComplete.add(async function (sender) {
            const formData = sender.data;
            console.log('Form submitted:', formData);

            // Limpar rascunho (formulário foi completado)
            localStorage.removeItem(DRAFT_KEY);
            console.log('✅ Rascunho removido após conclusão');

            // Mostrar loading
            document.getElementById('surveyContainer').style.display = 'none';
            document.getElementById('loadingContainer').style.display = 'block';

            try {
                // Detectar se debug mode está ativo
                const debugParam = window.location.search.includes('debug=1') ? '?debug=1' : '';

                // Enviar para backend (incluindo plainData para geração automática)
                const response = await fetch('<?= BASE_URL ?>/api/canvas/submit.php' + debugParam, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?= csrf_token() ?>',
                    },
                    body: JSON.stringify({
                        canvas_id: <?= $canvas['id'] ?>,
                        form_data: formData,
                        plain_data: sender.getPlainData()  // Metadados para geração automática
                    })
                });

                const result = await response.json();

                // Esconder loading
                document.getElementById('loadingContainer').style.display = 'none';

                if (result.success) {
                    // Se tem debug_info, mostrar debug container primeiro
                    if (result.debug_info) {
                        const debugInfo = result.debug_info;

                        // System Prompt
                        document.getElementById('debugSystemPrompt').textContent = debugInfo.system_prompt;

                        // User Prompt
                        document.getElementById('debugUserPrompt').textContent = debugInfo.user_prompt;

                        // Metadata badges
                        const metadata = debugInfo.metadata;
                        document.getElementById('debugMetadata').innerHTML = `
                            <div class="debug-badge"><strong>Modelo:</strong> ${metadata.model}</div>
                            <div class="debug-badge"><strong>Input:</strong> ${metadata.input_tokens} tokens</div>
                            <div class="debug-badge"><strong>Output:</strong> ${metadata.output_tokens} tokens</div>
                            <div class="debug-badge"><strong>Tempo:</strong> ${metadata.execution_time}</div>
                            <div class="debug-badge"><strong>Custo:</strong> ${metadata.cost}</div>
                        `;

                        document.getElementById('debugContainer').style.display = 'block';
                    }

                    // Mostrar resultado - Parse Markdown to HTML
                    const markdownHTML = marked.parse(result.response);
                    const cleanHTML = DOMPurify.sanitize(markdownHTML);
                    document.getElementById('claudeResponse').innerHTML = cleanHTML;
                    document.getElementById('resultContainer').style.display = 'block';

                    // Configurar botão de export PDF (Server-Side com mpdf)
                    document.getElementById('btnExportPDF').addEventListener('click', async function() {
                        const element = document.getElementById('claudeResponse');

                        // Verificar se há conteúdo
                        if (!element || !element.innerHTML || element.innerHTML.trim().length === 0) {
                            alert('Nenhum conteúdo para exportar');
                            return;
                        }

                        // Loading feedback
                        const originalHTML = this.innerHTML;
                        this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Gerando PDF...';
                        this.disabled = true;

                        try {
                            // Enviar HTML para o backend gerar PDF
                            const response = await fetch('<?= BASE_URL ?>/api/canvas/export-pdf.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({
                                    html: element.innerHTML,
                                    filename: 'analise-juridica-' + Date.now() + '.pdf'
                                })
                            });

                            if (!response.ok) {
                                throw new Error('Erro ao gerar PDF: ' + response.statusText);
                            }

                            // Baixar o PDF
                            const blob = await response.blob();
                            const url = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = 'analise-juridica-' + Date.now() + '.pdf';
                            document.body.appendChild(a);
                            a.click();
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);

                            console.log('✅ PDF gerado e baixado com sucesso (server-side)!');
                            this.innerHTML = '✅ PDF baixado!';
                            setTimeout(() => {
                                this.innerHTML = originalHTML;
                                this.disabled = false;
                            }, 2000);

                        } catch (error) {
                            console.error('❌ Erro ao gerar PDF:', error);
                            this.innerHTML = '❌ Erro ao gerar PDF';
                            setTimeout(() => {
                                this.innerHTML = originalHTML;
                                this.disabled = false;
                            }, 3000);
                        }
                    });
                } else {
                    // Mostrar erro
                    document.getElementById('errorMessage').textContent = result.error || 'Erro desconhecido';
                    document.getElementById('errorContainer').style.display = 'block';
                }

            } catch (error) {
                console.error('Error:', error);
                document.getElementById('loadingContainer').style.display = 'none';
                document.getElementById('errorMessage').textContent = 'Erro de conexão: ' + error.message;
                document.getElementById('errorContainer').style.display = 'block';
            }
        });

            } catch (error) {
                console.error('Error initializing survey:', error);
                document.getElementById('surveyContainer').innerHTML = '<div class="alert alert-danger">Erro ao inicializar formulário: ' + error.message + '</div>';
            }
        }
    </script>
    <?php endif; ?>

    <!-- Mock Mode Toggle Script -->
    <script>
        // Inicializar estado do botão
        document.addEventListener('DOMContentLoaded', function() {
            const mockModeActive = <?= json_encode($mock_mode_active) ?>;
            updateMockModeUI(mockModeActive);
        });

        function toggleMockMode() {
            const button = document.getElementById('mockModeToggle');
            button.disabled = true;
            button.innerHTML = '🔄 Alternando...';

            fetch('<?= BASE_URL ?>/api/canvas/toggle-mock-mode.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateMockModeUI(data.mock_mode_active);

                    // Recarregar página para aplicar mudanças
                    setTimeout(() => {
                        window.location.reload();
                    }, 500);
                } else {
                    alert('Erro ao alternar Modo Teste: ' + (data.error || 'Erro desconhecido'));
                    button.disabled = false;
                    updateMockModeUI(!data.mock_mode_active);
                }
            })
            .catch(error => {
                console.error('Error toggling mock mode:', error);
                alert('Erro de conexão ao alternar Modo Teste');
                button.disabled = false;
            });
        }

        function updateMockModeUI(isActive) {
            const button = document.getElementById('mockModeToggle');
            const statusSpan = document.getElementById('mockModeStatus');

            if (isActive) {
                button.classList.add('active');
                statusSpan.textContent = 'Ativado';
            } else {
                button.classList.remove('active');
                statusSpan.textContent = 'Desativado';
            }

            button.disabled = false;
            button.innerHTML = '🧪 Modo Teste: <span id="mockModeStatus">' +
                (isActive ? 'Ativado' : 'Desativado') + '</span>';
        }
    </script>

    <!-- Copy & Email Actions Script -->
    <script>
        // Botão Copiar Texto
        document.addEventListener('DOMContentLoaded', function() {
            const btnCopy = document.getElementById('btnCopyText');
            const btnEmail = document.getElementById('btnSendEmail');

            if (btnCopy) {
                btnCopy.addEventListener('click', async function() {
                    const element = document.getElementById('claudeResponse');

                    if (!element || !element.innerText || element.innerText.trim().length === 0) {
                        alert('Nenhum conteúdo para copiar');
                        return;
                    }

                    const originalHTML = this.innerHTML;
                    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Copiando...';
                    this.disabled = true;

                    try {
                        // Copiar texto puro (sem HTML)
                        await navigator.clipboard.writeText(element.innerText);

                        this.innerHTML = '✅ Copiado!';
                        setTimeout(() => {
                            this.innerHTML = originalHTML;
                            this.disabled = false;
                        }, 2000);
                    } catch (error) {
                        console.error('Erro ao copiar:', error);
                        alert('Erro ao copiar texto. Seu navegador pode não suportar esta funcionalidade.');
                        this.innerHTML = originalHTML;
                        this.disabled = false;
                    }
                });
            }

            // Botão Enviar Email
            if (btnEmail) {
                btnEmail.addEventListener('click', function() {
                    const element = document.getElementById('claudeResponse');

                    if (!element || !element.innerHTML || element.innerHTML.trim().length === 0) {
                        alert('Nenhum conteúdo para enviar');
                        return;
                    }

                    const email = prompt('Digite o email de destino:');
                    if (!email) return;

                    // Validar email
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(email)) {
                        alert('Email inválido');
                        return;
                    }

                    const originalHTML = this.innerHTML;
                    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';
                    this.disabled = true;

                    // Enviar email
                    fetch('<?= BASE_URL ?>/api/canvas/send-email.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            to_email: email,
                            html_content: element.innerHTML,
                            canvas_name: '<?= isset($canvas['name']) ? addslashes($canvas['name']) : 'Análise Jurídica' ?>'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.innerHTML = '✅ Enviado!';
                            setTimeout(() => {
                                this.innerHTML = originalHTML;
                                this.disabled = false;
                            }, 3000);
                        } else {
                            throw new Error(data.error || 'Erro ao enviar email');
                        }
                    })
                    .catch(error => {
                        console.error('Erro ao enviar email:', error);
                        alert('Erro ao enviar email: ' + error.message);
                        this.innerHTML = originalHTML;
                        this.disabled = false;
                    });
                });
            }
        });
    </script>
</body>
</html>
