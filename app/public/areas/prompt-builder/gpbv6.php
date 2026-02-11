<?php
/**
 * gpbV6 - Framework Profissional de Delimitação de Problemas
 * Wrapper PHP com autenticação para a ferramenta Gemini Prompt Builder v2.0
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

// Verificar acesso à vertical prompt_builder
$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

// Verificar se tem acesso (prompt-builder, demo ou admin)
if ($user_vertical !== 'prompt-builder' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta ferramenta';
    redirect(BASE_URL . '/dashboard.php');
}

$userName = $_SESSION['user']['name'] ?? 'Usuário';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>gpbV6 - Framework de Delimitação - <?= APP_NAME ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #667eea;
            --primary-dark: #5568d3;
            --secondary: #764ba2;
            --success: #4CAF50;
            --warning: #ffc107;
            --danger: #ef5350;
            --light: #f8f9fa;
            --dark: #333;
            --border: #e0e0e0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            min-height: 100vh;
            padding: 20px;
            line-height: 1.6;
        }

        /* Barra de navegação superior */
        .navbar-top {
            background: white;
            border-radius: 10px;
            padding: 15px 25px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .navbar-left a {
            text-decoration: none;
            color: var(--primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            border: 2px solid var(--primary);
            transition: all 0.3s;
        }

        .navbar-left a:hover {
            background: var(--primary);
            color: white;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--dark);
        }

        .navbar-right .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .navbar-right .user-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
        }

        header h1 {
            font-size: 2.8em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        header .subtitle {
            font-size: 1.3em;
            opacity: 0.95;
            margin-bottom: 5px;
        }

        header .tagline {
            font-size: 1em;
            opacity: 0.85;
        }

        /* Task Selection Grid */
        .task-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .task-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: 3px solid transparent;
        }

        .task-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }

        .task-card.selected {
            border-color: var(--primary);
            background: linear-gradient(to bottom, white 0%, #f0f4ff 100%);
        }

        .task-icon {
            font-size: 2.5em;
            margin-bottom: 12px;
        }

        .task-card h3 {
            color: var(--dark);
            margin-bottom: 8px;
            font-size: 1.2em;
        }

        .task-card p {
            color: #666;
            font-size: 0.9em;
        }

        /* Main Workspace */
        .workspace {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: none;
        }

        .workspace.active {
            display: block;
            animation: slideUp 0.4s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .workspace-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border);
        }

        .workspace-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .workspace-title h2 {
            color: var(--dark);
            font-size: 1.8em;
        }

        .back-btn {
            background: #666;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95em;
            transition: all 0.3s;
        }

        .back-btn:hover {
            background: #555;
        }

        /* Guidelines Box */
        .guidelines-box {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-left: 4px solid var(--primary);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .guidelines-box h4 {
            color: var(--primary);
            margin-bottom: 15px;
            font-size: 1.1em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .guidelines-list {
            list-style: none;
            margin-bottom: 15px;
        }

        .guidelines-list li {
            padding: 8px 0;
            padding-left: 25px;
            position: relative;
            color: var(--dark);
        }

        .guidelines-list li::before {
            content: "•";
            position: absolute;
            left: 8px;
            color: var(--primary);
            font-size: 1.3em;
        }

        .guidelines-list.practices li::before {
            content: "✓";
            color: var(--success);
        }

        .guidelines-list.pitfalls li::before {
            content: "⚠";
            color: var(--warning);
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 30px;
            padding: 25px;
            background: var(--light);
            border-radius: 10px;
            border: 2px solid var(--border);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .section-number {
            background: var(--primary);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1em;
        }

        .section-header h3 {
            color: var(--dark);
            font-size: 1.3em;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: var(--dark);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 1em;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1em;
            font-family: inherit;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .hint {
            display: flex;
            align-items: start;
            gap: 8px;
            margin-top: 8px;
            padding: 10px;
            background: white;
            border-radius: 6px;
            font-size: 0.9em;
            color: #666;
        }

        .hint-icon {
            color: var(--primary);
            font-weight: bold;
        }

        /* File Upload Area */
        .upload-area {
            border: 3px dashed var(--border);
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            background: white;
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: #f8f9ff;
        }

        .upload-area.dragover {
            border-color: var(--primary);
            background: #e8edff;
        }

        .upload-icon {
            font-size: 3em;
            margin-bottom: 10px;
            color: var(--primary);
        }

        .file-list {
            margin-top: 15px;
            text-align: left;
        }

        .file-item {
            display: flex;
            flex-direction: column;
            padding: 10px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .file-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-actions {
            display: flex;
            gap: 8px;
        }

        .add-description-btn {
            background: none;
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 4px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85em;
            transition: all 0.3s;
        }

        .add-description-btn:hover {
            background: var(--primary);
            color: white;
        }

        .file-description-area {
            display: none;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .file-description-area.active {
            display: block;
            animation: slideDown 0.3s ease;
        }

        .file-description-area label {
            display: block;
            color: var(--dark);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.95em;
        }

        .file-description-area textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid var(--border);
            border-radius: 6px;
            font-size: 0.9em;
            font-family: inherit;
            resize: vertical;
            min-height: 60px;
        }

        .file-description-area textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .file-description-hint {
            font-size: 0.85em;
            color: #666;
            margin-top: 6px;
            font-style: italic;
        }

        .file-description-buttons {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .file-description-buttons button {
            padding: 6px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.3s;
        }

        .confirm-description {
            background: var(--success);
            color: white;
        }

        .confirm-description:hover {
            background: #45a049;
        }

        .cancel-description {
            background: #ddd;
            color: var(--dark);
        }

        .cancel-description:hover {
            background: #ccc;
        }

        .file-has-description {
            border-left: 3px solid var(--primary);
        }

        .remove-file {
            background: var(--danger);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85em;
        }

        /* URL Input */
        .url-input-container {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .url-input-container input {
            flex: 1;
        }

        .add-url-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            white-space: nowrap;
        }

        /* Constraints Section */
        .constraint-group {
            margin-bottom: 25px;
        }

        .constraint-group label {
            display: block;
            color: var(--dark);
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 1em;
        }

        .slider-container {
            padding: 15px 0;
        }

        .slider {
            width: 100%;
            height: 8px;
            border-radius: 5px;
            background: var(--border);
            outline: none;
            -webkit-appearance: none;
        }

        .slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .slider-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 0.9em;
            color: #666;
        }

        /* Format Section */
        .format-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .format-option {
            background: white;
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .format-option:hover {
            border-color: var(--primary);
        }

        .format-option input[type="radio"] {
            margin-right: 10px;
        }

        .format-option.selected {
            border-color: var(--primary);
            background: #f0f4ff;
        }

        /* Advanced Toggle */
        .advanced-toggle {
            text-align: center;
            margin: 20px 0;
        }

        .advanced-toggle button {
            background: none;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .advanced-toggle button:hover {
            background: var(--primary);
            color: white;
        }

        .advanced-options {
            display: none;
            margin-top: 20px;
        }

        .advanced-options.active {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
            }
            to {
                opacity: 1;
                max-height: 1000px;
            }
        }

        /* Generate Button */
        .generate-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border: none;
            padding: 18px 50px;
            font-size: 1.2em;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            display: block;
            margin: 40px auto 0;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .generate-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.5);
        }

        /* Output Section */
        .output-container {
            display: none;
            margin-top: 40px;
        }

        .output-container.active {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            animation: slideUp 0.5s ease;
        }

        @media (max-width: 992px) {
            .output-container.active {
                grid-template-columns: 1fr;
            }
        }

        .output-box {
            background: var(--light);
            border-radius: 12px;
            padding: 25px;
            border: 2px solid var(--border);
        }

        .output-box h3 {
            color: var(--dark);
            margin-bottom: 20px;
            font-size: 1.3em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .prompt-display {
            background: white;
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            font-family: 'Courier New', monospace;
            font-size: 0.95em;
            line-height: 1.7;
            color: var(--dark);
            white-space: pre-wrap;
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 15px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .copy-btn, .gemini-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .copy-btn {
            background: var(--success);
            color: white;
        }

        .copy-btn:hover {
            background: #45a049;
        }

        .gemini-btn {
            background: var(--primary);
            color: white;
        }

        .gemini-btn:hover {
            background: var(--primary-dark);
        }

        /* Techniques List */
        .techniques-list {
            list-style: none;
        }

        .techniques-list li {
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .technique-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 5px;
            font-size: 1.05em;
        }

        .technique-desc {
            color: #666;
            font-size: 0.95em;
            line-height: 1.5;
        }

        /* Examples Section */
        .examples-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--border);
        }

        .example-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .example-card {
            background: white;
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .example-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .example-card h5 {
            color: var(--primary);
            margin-bottom: 5px;
        }

        .example-card p {
            font-size: 0.9em;
            color: #666;
        }

        /* Utility Classes */
        .hidden {
            display: none;
        }

        .text-center {
            text-align: center;
        }

        .mb-20 {
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            header h1 {
                font-size: 2em;
            }

            .workspace {
                padding: 25px;
            }

            .task-grid {
                grid-template-columns: 1fr;
            }

            .navbar-top {
                flex-direction: column;
                gap: 15px;
            }

            .navbar-right {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Barra de navegação superior -->
        <nav class="navbar-top">
            <div class="navbar-left">
                <a href="<?= BASE_URL ?>/areas/prompt-builder/">
                    ← Voltar para Prompt Builder
                </a>
            </div>
            <div class="navbar-right">
                <div class="user-info">
                    <div class="user-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                    <span>Logado como: <strong><?= htmlspecialchars($userName) ?></strong></span>
                </div>
            </div>
        </nav>

        <header>
            <h1>gpbV6 - Prompt Builder</h1>
            <p class="subtitle">Framework Profissional de Delimitação de Problemas</p>
            <p class="tagline">Transforme tarefas complexas em prompts precisos e efetivos</p>
        </header>

        <!-- Task Selection -->
        <div id="taskSelection" class="task-grid">
            <div class="task-card" data-task="email">
                <div class="task-icon">📧</div>
                <h3>Email Profissional</h3>
                <p>Comunicações claras para solicitações, respostas e atualizações</p>
            </div>

            <div class="task-card" data-task="relatorio">
                <div class="task-icon">📊</div>
                <h3>Análise & Relatório</h3>
                <p>Estruture dados em insights acionáveis e decisões informadas</p>
            </div>

            <div class="task-card" data-task="resumo">
                <div class="task-icon">📄</div>
                <h3>Resumo de Documento</h3>
                <p>Extraia informações essenciais de textos longos rapidamente</p>
            </div>

            <div class="task-card" data-task="revisao">
                <div class="task-icon">✍️</div>
                <h3>Revisão de Texto</h3>
                <p>Melhore clareza, tom e qualidade de documentos importantes</p>
            </div>

            <div class="task-card" data-task="planejamento">
                <div class="task-icon">📋</div>
                <h3>Planejamento & Checklist</h3>
                <p>Organize projetos e processos com listas acionáveis</p>
            </div>

            <div class="task-card" data-task="criativo">
                <div class="task-icon">💡</div>
                <h3>Brainstorm & Ideação</h3>
                <p>Gere ideias criativas para problemas e oportunidades</p>
            </div>
        </div>

        <!-- Main Workspace -->
        <div id="workspace" class="workspace">
            <div class="workspace-header">
                <div class="workspace-title">
                    <span id="workspaceIcon" class="task-icon"></span>
                    <h2 id="workspaceTitle"></h2>
                </div>
                <button class="back-btn" id="backButton">← Voltar</button>
            </div>

            <!-- Guidelines Box -->
            <div id="guidelinesBox" class="guidelines-box"></div>

            <!-- Framework Form -->
            <form id="promptForm" onsubmit="generatePrompt(event)">
                <!-- Step 1: Tarefa/Objetivo -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-number">1</div>
                        <h3>Tarefa / Objetivo</h3>
                    </div>
                    <div class="form-group">
                        <label for="tarefa">O que você precisa fazer?</label>
                        <input type="text" id="tarefa" required placeholder="Seja específico sobre o resultado esperado">
                        <div class="hint">
                            <span class="hint-icon">💡</span>
                            <span id="tarefaHint"></span>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Contexto -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-number">2</div>
                        <h3>Contexto</h3>
                    </div>
                    <div class="form-group">
                        <label for="papel">Qual seu papel nesta tarefa?</label>
                        <select id="papel" required>
                            <option value="">Selecione...</option>
                            <option value="Coordenador/Gerente">Coordenador/Gerente</option>
                            <option value="Analista/Técnico">Analista/Técnico</option>
                            <option value="Executivo/Diretor">Executivo/Diretor</option>
                            <option value="Assistente/Suporte">Assistente/Suporte</option>
                            <option value="Especialista">Especialista</option>
                            <option value="custom">Outro (especificar)</option>
                        </select>
                        <input type="text" id="papelCustom" class="hidden" placeholder="Especifique seu papel">
                    </div>
                    <div class="form-group">
                        <label for="publico">Para quem é este trabalho?</label>
                        <input type="text" id="publico" required placeholder="Ex: Equipe interna, Cliente externo, Diretoria">
                        <div class="hint">
                            <span class="hint-icon">💡</span>
                            <span>Conhecer o público ajusta automaticamente o nível técnico e tom</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="contexto">Qual a situação ou histórico relevante?</label>
                        <textarea id="contexto" required placeholder="Forneça background que ajude a entender o contexto..."></textarea>
                        <div class="hint">
                            <span class="hint-icon">💡</span>
                            <span id="contextoHint"></span>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Documentos & Informações -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-number">3</div>
                        <h3>Documentos & Informações Disponíveis</h3>
                    </div>

                    <div class="form-group">
                        <label>Arquivos de Referência (opcional)</label>
                        <div class="upload-area" id="uploadArea">
                            <div class="upload-icon">📎</div>
                            <p><strong>Arraste arquivos aqui</strong> ou clique para selecionar</p>
                            <p style="font-size: 0.9em; color: #666; margin-top: 10px;">
                                <strong>Arquivos de texto (.txt, .csv, .md):</strong> Conteúdo será incluído diretamente no prompt<br>
                                <strong>Outros formatos:</strong> Você precisará anexá-los manualmente no Gemini
                            </p>
                            <input type="file" id="fileInput" multiple style="display: none;">
                        </div>
                        <div id="fileList" class="file-list"></div>
                    </div>

                    <div class="form-group">
                        <label>URLs de Referência (opcional)</label>
                        <div class="url-input-container">
                            <input type="url" id="urlInput" placeholder="https://exemplo.com/artigo-relevante">
                            <button type="button" class="add-url-btn" id="addUrlButton">+ Adicionar URL</button>
                        </div>
                        <div id="urlList" class="file-list" style="margin-top: 15px;"></div>
                        <div class="hint">
                            <span class="hint-icon">💡</span>
                            <span>O Gemini pode buscar e analisar conteúdo de URLs públicas</span>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Restrições -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-number">4</div>
                        <h3>Restrições</h3>
                    </div>

                    <div class="constraint-group">
                        <label>Tom e Formalidade</label>
                        <div class="slider-container">
                            <input type="range" min="1" max="5" value="3" class="slider" id="formalidade">
                            <div class="slider-labels">
                                <span>Casual</span>
                                <span>Neutro</span>
                                <span>Muito Formal</span>
                            </div>
                        </div>
                    </div>

                    <div class="constraint-group">
                        <label>Extensão do Resultado</label>
                        <div class="slider-container">
                            <input type="range" min="1" max="5" value="3" class="slider" id="extensao">
                            <div class="slider-labels">
                                <span>Conciso</span>
                                <span>Balanceado</span>
                                <span>Detalhado</span>
                            </div>
                        </div>
                    </div>

                    <div class="advanced-toggle">
                        <button type="button" id="toggleAdvancedButton">⚙️ Opções Avançadas</button>
                    </div>

                    <div id="advancedOptions" class="advanced-options">
                        <div class="constraint-group">
                            <label>Nível de Criatividade</label>
                            <div class="slider-container">
                                <input type="range" min="1" max="5" value="3" class="slider" id="criatividade">
                                <div class="slider-labels">
                                    <span>Factual</span>
                                    <span>Balanceado</span>
                                    <span>Criativo</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="urgencia">Urgência / Prazo</label>
                            <select id="urgencia">
                                <option value="nao-urgente">Não urgente (flexível)</option>
                                <option value="normal" selected>Normal (alguns dias)</option>
                                <option value="urgente">Urgente (horas/1 dia)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="evitar">O que evitar? (opcional)</label>
                            <input type="text" id="evitar" placeholder="Ex: jargão técnico, linguagem muito informal, etc.">
                        </div>
                    </div>
                </div>

                <!-- Step 5: Formato de Entrega -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-number">5</div>
                        <h3>Formato de Entrega</h3>
                    </div>

                    <div id="formatOptions" class="format-options">
                        <!-- Populated dynamically based on task type -->
                    </div>

                    <div class="form-group" style="margin-top: 20px;">
                        <label for="formatoDetalhes">Especificações Adicionais de Formato (opcional)</label>
                        <textarea id="formatoDetalhes" rows="3" placeholder="Ex: Incluir sumário executivo, usar bullet points, anexar referências..."></textarea>
                    </div>
                </div>

                <button type="submit" class="generate-btn">✨ Gerar Prompt Otimizado</button>
            </form>

            <!-- Examples Section -->
            <div class="examples-section">
                <h4 style="color: var(--dark); margin-bottom: 15px;">
                    📚 Exemplos Prontos - Clique para Auto-Preencher
                </h4>
                <div id="exampleCards" class="example-cards"></div>
            </div>
        </div>

        <!-- Output Section -->
        <div id="outputContainer" class="output-container">
            <div class="output-box">
                <h3>📋 Seu Prompt Otimizado</h3>
                <div id="promptOutput" class="prompt-display"></div>
                <div class="action-buttons">
                    <button class="copy-btn" id="copyPromptButton">
                        📋 Copiar Prompt
                    </button>
                    <button class="gemini-btn" id="openGeminiButton">
                        🚀 Abrir no Gemini
                    </button>
                </div>
            </div>

            <div class="output-box">
                <h3>🎓 Técnicas Aplicadas</h3>
                <ul id="techniquesList" class="techniques-list"></ul>
            </div>
        </div>
    </div>

    <script>
        // Global state
        let currentTask = null;
        let uploadedFiles = [];
        let urls = [];

        // Task configurations
        const taskConfigs = {
            email: {
                icon: '📧',
                title: 'Email Profissional',
                guidelines: {
                    practices: [
                        'Defina claramente o propósito no primeiro parágrafo',
                        'Use estrutura escaneável (bullet points para múltiplos itens)',
                        'Termine com call-to-action específico e próximos passos',
                        'Adapte formalidade ao relacionamento e contexto'
                    ],
                    pitfalls: [
                        'Emails longos sem estrutura clara',
                        'Assumir que destinatário conhece todo o contexto',
                        'Tom ambíguo (muito casual para situação formal)',
                        'Falta de objetivo claro ou ação esperada'
                    ]
                },
                hints: {
                    tarefa: 'Ex: "Solicitar aprovação de orçamento" ou "Responder reclamação de cliente"',
                    contexto: 'Ex: "Cliente atrasou pagamento 30 dias, segundo lembrete" ou "Proposta foi aprovada pela diretoria"'
                },
                formats: [
                    'Email completo (assunto + corpo estruturado)',
                    'Email em bullet points (para múltiplas informações)',
                    'Resposta formal (para superiores/externos)',
                    'Atualização de status (breve e objetivo)'
                ],
                examples: [
                    {
                        nome: 'Solicitação de Aprovação',
                        desc: 'Pedir aprovação de orçamento para projeto',
                        data: {
                            tarefa: 'Solicitar aprovação de orçamento de R$ 50.000 para campanha de marketing Q1',
                            papel: 'Coordenador/Gerente',
                            publico: 'Diretoria Executiva',
                            contexto: 'Campanha focada em captação digital, com ROI projetado de 3:1 baseado em dados históricos. Precisa iniciar até dia 15 para pegar período sazonal.',
                            formalidade: 4,
                            extensao: 3
                        }
                    },
                    {
                        nome: 'Comunicado de Mudança',
                        desc: 'Informar equipe sobre alteração de processo',
                        data: {
                            tarefa: 'Comunicar mudança no horário das reuniões semanais',
                            papel: 'Coordenador/Gerente',
                            publico: 'Equipe de 15 pessoas',
                            contexto: 'Reuniões passam de terça 14h para quinta 10h a partir da próxima semana, para melhor alinhamento com time internacional.',
                            formalidade: 2,
                            extensao: 2
                        }
                    }
                ]
            },
            relatorio: {
                icon: '📊',
                title: 'Análise & Relatório',
                guidelines: {
                    practices: [
                        'Comece com sumário executivo (para público sênior)',
                        'Use dados quantitativos para suportar conclusões',
                        'Separe fatos (o que aconteceu) de interpretação (por quê)',
                        'Termine com recomendações acionáveis e priorizadas'
                    ],
                    pitfalls: [
                        'Excesso de dados sem insights claros',
                        'Estrutura confusa (misturar análise com recomendação)',
                        'Não adaptar profundidade ao público',
                        'Conclusões sem evidência ou contexto'
                    ]
                },
                hints: {
                    tarefa: 'Ex: "Analisar queda nas vendas do Q3" ou "Avaliar efetividade do novo processo"',
                    contexto: 'Ex: "Vendas caíram 15% vs Q2, mas margem subiu 3%. Novo concorrente entrou no mercado em agosto."'
                },
                formats: [
                    'Relatório executivo (high-level, focado em decisão)',
                    'Análise técnica detalhada (metodologia + dados completos)',
                    'Comparativo (antes/depois, nós/concorrentes)',
                    'Dashboard narrativo (métricas + interpretação)'
                ],
                examples: [
                    {
                        nome: 'Análise de Desempenho',
                        desc: 'Avaliar métricas do trimestre',
                        data: {
                            tarefa: 'Analisar desempenho de vendas Q3 e recomendar ajustes para Q4',
                            papel: 'Analista/Técnico',
                            publico: 'Diretoria Comercial',
                            contexto: 'Vendas totais: R$ 2.8M (meta: R$ 3M). Produto A superou meta em 20%, Produto B ficou 30% abaixo. Churn aumentou de 5% para 8%.',
                            formalidade: 4,
                            extensao: 4
                        }
                    }
                ]
            },
            resumo: {
                icon: '📄',
                title: 'Resumo de Documento',
                guidelines: {
                    practices: [
                        'Identifique o propósito: resumo executivo, técnico ou educacional?',
                        'Preserve hierarquia de informações (principais vs secundárias)',
                        'Mantenha terminologia-chave do documento original',
                        'Indique quando algo requer leitura completa do original'
                    ],
                    pitfalls: [
                        'Resumo tão longo quanto o original',
                        'Perder nuances críticas por excesso de síntese',
                        'Não indicar qual parte do documento foi resumida',
                        'Omitir contexto necessário para compreensão'
                    ]
                },
                hints: {
                    tarefa: 'Ex: "Resumir contrato para revisão jurídica" ou "Extrair pontos-chave de relatório técnico"',
                    contexto: 'Ex: "Documento de 50 páginas sobre política de privacidade. Preciso focar em obrigações da empresa."'
                },
                formats: [
                    'Sumário executivo (5-10 linhas, apenas essencial)',
                    'Resumo estruturado (por seções do documento)',
                    'Bullet points (lista de pontos principais)',
                    'Perguntas e respostas (foco em tópicos específicos)'
                ],
                examples: [
                    {
                        nome: 'Resumir Proposta Comercial',
                        desc: 'Extrair pontos-chave para decisão',
                        data: {
                            tarefa: 'Resumir proposta de fornecedor focando em custos, prazos e diferenciais',
                            papel: 'Analista/Técnico',
                            publico: 'Gerente de Compras',
                            contexto: 'Proposta de 30 páginas para novo sistema de gestão. Decisão precisa ser tomada em 3 dias.',
                            formalidade: 3,
                            extensao: 2
                        }
                    }
                ]
            },
            revisao: {
                icon: '✍️',
                title: 'Revisão de Texto',
                guidelines: {
                    practices: [
                        'Especifique tipo de revisão: gramática, clareza, tom ou estrutura',
                        'Indique se deve preservar voz/estilo original ou reescrever',
                        'Mencione público-alvo (afeta vocabulário e complexidade)',
                        'Peça sugestões, não apenas correções'
                    ],
                    pitfalls: [
                        'Revisão genérica sem foco (corrige tudo superficialmente)',
                        'Mudar tom drasticamente sem permissão',
                        'Não explicar razão das mudanças sugeridas',
                        'Perder mensagem original por excesso de edição'
                    ]
                },
                hints: {
                    tarefa: 'Ex: "Revisar proposta comercial para clareza" ou "Melhorar tom de email de resposta a reclamação"',
                    contexto: 'Ex: "Texto escrito às pressas, precisa soar mais profissional mas manter urgência"'
                },
                formats: [
                    'Revisão com track changes (mostra original + sugestões)',
                    'Versão reescrita (texto final melhorado)',
                    'Comentários e sugestões (sem reescrever)',
                    'Revisão multi-passo (gramática → clareza → tom)'
                ],
                examples: [
                    {
                        nome: 'Melhorar Email Importante',
                        desc: 'Revisar email para cliente insatisfeito',
                        data: {
                            tarefa: 'Revisar email de resposta a reclamação, melhorando empatia sem perder objetividade',
                            papel: 'Coordenador/Gerente',
                            publico: 'Cliente B2B de alto valor',
                            contexto: 'Cliente reclamou de atraso na entrega. Temos justificativa (greve), mas precisamos manter relacionamento.',
                            formalidade: 4,
                            extensao: 3
                        }
                    }
                ]
            },
            planejamento: {
                icon: '📋',
                title: 'Planejamento & Checklist',
                guidelines: {
                    practices: [
                        'Organize por fases, prioridade ou sequência lógica',
                        'Inclua responsáveis e prazos quando relevante',
                        'Seja específico: itens acionáveis, não abstratos',
                        'Preveja dependências entre tarefas'
                    ],
                    pitfalls: [
                        'Itens vagos ("planejar campanha" vs "definir público-alvo")',
                        'Não ordenar por importância ou urgência',
                        'Esquecer critérios de conclusão (como saber se está pronto?)',
                        'Checklists muito longas sem agrupamento lógico'
                    ]
                },
                hints: {
                    tarefa: 'Ex: "Criar checklist para onboarding de funcionário" ou "Planejar lançamento de produto"',
                    contexto: 'Ex: "Processo atual é informal, queremos padronizar para garantir nada seja esquecido"'
                },
                formats: [
                    'Checklist simples (lista de verificação)',
                    'Plano de projeto (fases + responsáveis + prazos)',
                    'Processo passo-a-passo (workflow detalhado)',
                    'Template reutilizável (para repetir processo)'
                ],
                examples: [
                    {
                        nome: 'Checklist de Evento',
                        desc: 'Organizar evento corporativo',
                        data: {
                            tarefa: 'Criar checklist completa para organização de evento corporativo com 100 pessoas',
                            papel: 'Coordenador/Gerente',
                            publico: 'Equipe de organização (3 pessoas)',
                            contexto: 'Evento em 60 dias. Precisa cobrir: local, catering, palestrantes, logística, comunicação.',
                            formalidade: 2,
                            extensao: 4
                        }
                    }
                ]
            },
            criativo: {
                icon: '💡',
                title: 'Brainstorm & Ideação',
                guidelines: {
                    practices: [
                        'Defina claramente o desafio ou oportunidade',
                        'Especifique restrições (orçamento, prazo, recursos)',
                        'Peça quantidade antes de qualidade (divergir antes de convergir)',
                        'Solicite perspectivas diversas ou técnicas específicas'
                    ],
                    pitfalls: [
                        'Desafio vago ("ideias para melhorar vendas")',
                        'Não mencionar o que já foi tentado',
                        'Pedir apenas "ideias criativas" sem critérios',
                        'Esquecer de filtrar ideias por viabilidade depois'
                    ]
                },
                hints: {
                    tarefa: 'Ex: "Gerar ideias para reduzir custo operacional" ou "Nomear novo produto de forma memorável"',
                    contexto: 'Ex: "Tentamos desconto e não funcionou. Orçamento limitado. Público: classe B, 30-45 anos."'
                },
                formats: [
                    'Lista de ideias (quantidade, sem filtro inicial)',
                    'Ideias categorizadas (por tipo, custo, viabilidade)',
                    'Ideias detalhadas (poucas ideias, muito desenvolvidas)',
                    'Matriz de avaliação (ideias vs critérios)'
                ],
                examples: [
                    {
                        nome: 'Campanha de Engajamento',
                        desc: 'Ideias para aumentar participação de equipe',
                        data: {
                            tarefa: 'Gerar 10 ideias criativas para aumentar engajamento da equipe em treinamentos',
                            papel: 'Coordenador/Gerente',
                            publico: 'Time de RH',
                            contexto: 'Participação caiu de 80% para 50%. Equipe trabalha híbrido. Orçamento: R$ 5k.',
                            formalidade: 2,
                            extensao: 3,
                            criatividade: 5
                        }
                    }
                ]
            }
        };

        // Initialize
        function selectTask(taskId) {
            currentTask = taskId;
            const config = taskConfigs[taskId];

            // Update workspace
            document.getElementById('workspaceIcon').textContent = config.icon;
            document.getElementById('workspaceTitle').textContent = config.title;

            // Populate guidelines
            const guidelinesBox = document.getElementById('guidelinesBox');
            guidelinesBox.innerHTML = `
                <h4>📌 Boas Práticas para ${config.title}</h4>
                <ul class="guidelines-list practices">
                    ${config.guidelines.practices.map(p => `<li>${p}</li>`).join('')}
                </ul>
                <h4 style="margin-top: 20px;">⚠️ Armadilhas Comuns</h4>
                <ul class="guidelines-list pitfalls">
                    ${config.guidelines.pitfalls.map(p => `<li>${p}</li>`).join('')}
                </ul>
            `;

            // Update hints
            document.getElementById('tarefaHint').textContent = config.hints.tarefa;
            document.getElementById('contextoHint').textContent = config.hints.contexto;

            // Populate format options
            const formatOptions = document.getElementById('formatOptions');
            formatOptions.innerHTML = config.formats.map((format, index) => `
                <label class="format-option" data-format-index="${index}">
                    <input type="radio" name="formato" value="${format}" ${index === 0 ? 'checked' : ''}>
                    <span>${format}</span>
                </label>
            `).join('');

            // Add event listeners to format options
            formatOptions.querySelectorAll('.format-option').forEach((option, index) => {
                option.addEventListener('click', function() {
                    selectFormat(index);
                });
            });

            // Populate examples
            const exampleCards = document.getElementById('exampleCards');
            exampleCards.innerHTML = config.examples.map((ex, index) => `
                <div class="example-card" data-example-index="${index}">
                    <h5>${ex.nome}</h5>
                    <p>${ex.desc}</p>
                </div>
            `).join('');

            // Add event listeners to example cards
            exampleCards.querySelectorAll('.example-card').forEach((card, index) => {
                card.addEventListener('click', function() {
                    loadExample(index);
                });
            });

            // Show workspace, hide selection
            document.getElementById('taskSelection').style.display = 'none';
            document.getElementById('workspace').classList.add('active');

            // Reset form
            document.getElementById('promptForm').reset();
            uploadedFiles = [];
            urls = [];
            document.getElementById('fileList').innerHTML = '';
            document.getElementById('urlList').innerHTML = '';
        }

        function backToSelection() {
            document.getElementById('workspace').classList.remove('active');
            document.getElementById('outputContainer').classList.remove('active');
            document.getElementById('taskSelection').style.display = 'grid';
            currentTask = null;
        }

        function selectFormat(index) {
            document.querySelectorAll('.format-option').forEach((opt, i) => {
                opt.classList.toggle('selected', i === index);
            });
        }

        function loadExample(index) {
            const example = taskConfigs[currentTask].examples[index];
            const data = example.data;

            // Fill form
            document.getElementById('tarefa').value = data.tarefa;
            document.getElementById('papel').value = data.papel;
            document.getElementById('publico').value = data.publico;
            document.getElementById('contexto').value = data.contexto;
            document.getElementById('formalidade').value = data.formalidade || 3;
            document.getElementById('extensao').value = data.extensao || 3;
            if (data.criatividade) {
                document.getElementById('criatividade').value = data.criatividade;
            }

            // Scroll to top of form
            document.getElementById('promptForm').scrollIntoView({ behavior: 'smooth' });
        }

        // File upload handling
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');

        uploadArea.addEventListener('click', () => fileInput.click());

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            handleFiles(e.dataTransfer.files);
        });

        fileInput.addEventListener('change', (e) => {
            handleFiles(e.target.files);
        });

        function handleFiles(files) {
            Array.from(files).forEach(file => {
                // Read text files immediately
                const isText = file.name.match(/\.(txt|csv|md)$/i);
                if (isText && file.size < 500000) { // Max 500KB for text files
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        file.textContent = e.target.result;
                        uploadedFiles.push(file);
                        addFileToList(file);
                    };
                    reader.onerror = function() {
                        console.error('Erro ao ler arquivo:', file.name);
                        uploadedFiles.push(file);
                        addFileToList(file);
                    };
                    reader.readAsText(file);
                } else {
                    uploadedFiles.push(file);
                    addFileToList(file);
                }
            });
        }

        function addFileToList(file) {
            const fileList = document.getElementById('fileList');
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            const fileId = file.name.replace(/[^a-zA-Z0-9]/g, '_');
            fileItem.id = `file-${fileId}`;
            fileItem.dataset.fileName = file.name;

            fileItem.innerHTML = `
                <div class="file-item-header">
                    <div class="file-info">
                        <span>📎</span>
                        <span><strong>${file.name}</strong> (${(file.size / 1024).toFixed(1)} KB)</span>
                    </div>
                    <div class="file-actions">
                        <button type="button" class="add-description-btn" data-filename="${file.name}">
                            📝 Como usar?
                        </button>
                        <button type="button" class="remove-file" data-filename="${file.name}">Remover</button>
                    </div>
                </div>
                <div class="file-description-area" id="desc-${fileId}">
                    <label>Como o Gemini deve usar este arquivo?</label>
                    <textarea id="textarea-${fileId}"
                              placeholder="Ex: 'Foque apenas nas vendas do Produto B' ou 'Use como referência de formato' ou 'Analise apenas dados após março'"></textarea>
                    <div class="file-description-hint">
                        💡 Dica: Seja específico sobre qual informação importa ou como o arquivo deve ser interpretado
                    </div>
                    <div class="file-description-buttons">
                        <button type="button" class="confirm-description" data-filename="${file.name}">✓ Confirmar</button>
                        <button type="button" class="cancel-description" data-filename="${file.name}">× Cancelar</button>
                    </div>
                </div>
            `;

            fileList.appendChild(fileItem);

            // Add event listeners
            const addDescBtn = fileItem.querySelector('.add-description-btn');
            const removeBtn = fileItem.querySelector('.remove-file');
            const confirmBtn = fileItem.querySelector('.confirm-description');
            const cancelBtn = fileItem.querySelector('.cancel-description');

            addDescBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleFileDescription(file.name, e);
            });

            removeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                removeFile(file.name);
            });

            confirmBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                confirmFileDescription(file.name, e);
            });

            cancelBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                cancelFileDescription(file.name, e);
            });
        }

        function toggleFileDescription(fileName, eventOrButton) {
            const fileId = fileName.replace(/[^a-zA-Z0-9]/g, '_');
            const descArea = document.getElementById(`desc-${fileId}`);
            const fileItem = document.getElementById(`file-${fileId}`);
            const btn = fileItem ? fileItem.querySelector('.add-description-btn') : null;

            if (!descArea || !btn) return;

            if (descArea.classList.contains('active')) {
                descArea.classList.remove('active');
                btn.textContent = '📝 Como usar?';
            } else {
                descArea.classList.add('active');
                btn.textContent = '📝 Editando...';
                // Focus on textarea
                setTimeout(() => {
                    const textarea = document.getElementById(`textarea-${fileId}`);
                    if (textarea) textarea.focus();
                }, 100);
            }
        }

        function confirmFileDescription(fileName, evt) {
            const fileId = fileName.replace(/[^a-zA-Z0-9]/g, '_');
            const textarea = document.getElementById(`textarea-${fileId}`);
            const descArea = document.getElementById(`desc-${fileId}`);
            const fileItem = document.getElementById(`file-${fileId}`);
            const btn = fileItem ? fileItem.querySelector('.add-description-btn') : null;

            if (!textarea || !descArea || !btn) return;

            // Store description in the file object
            const file = uploadedFiles.find(f => f.name === fileName);
            if (file) {
                file.description = textarea.value.trim();

                // Update UI
                if (file.description) {
                    fileItem.classList.add('file-has-description');
                    btn.textContent = '✓ Com instruções';
                    btn.style.background = 'var(--success)';
                    btn.style.color = 'white';
                    btn.style.borderColor = 'var(--success)';
                } else {
                    fileItem.classList.remove('file-has-description');
                    btn.textContent = '📝 Como usar?';
                    btn.style.background = 'none';
                    btn.style.color = 'var(--primary)';
                    btn.style.borderColor = 'var(--primary)';
                }
            }

            descArea.classList.remove('active');
        }

        function cancelFileDescription(fileName, evt) {
            const fileId = fileName.replace(/[^a-zA-Z0-9]/g, '_');
            const descArea = document.getElementById(`desc-${fileId}`);
            const fileItem = document.getElementById(`file-${fileId}`);
            const btn = fileItem ? fileItem.querySelector('.add-description-btn') : null;

            if (!descArea || !btn) return;

            descArea.classList.remove('active');
            const file = uploadedFiles.find(f => f.name === fileName);
            btn.textContent = file?.description ? '✓ Com instruções' : '📝 Como usar?';
        }

        function removeFile(fileName) {
            uploadedFiles = uploadedFiles.filter(f => f.name !== fileName);
            updateFileList();
        }

        function updateFileList() {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            uploadedFiles.forEach(file => addFileToList(file));
        }

        // URL handling
        function addURL() {
            const urlInput = document.getElementById('urlInput');
            const url = urlInput.value.trim();

            if (url && isValidURL(url)) {
                urls.push(url);
                addURLToList(url);
                urlInput.value = '';
            } else {
                alert('Por favor, insira uma URL válida');
            }
        }

        function isValidURL(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;
            }
        }

        function addURLToList(url) {
            const urlList = document.getElementById('urlList');
            const urlItem = document.createElement('div');
            urlItem.className = 'file-item';
            urlItem.dataset.url = url;

            urlItem.innerHTML = `
                <div class="file-info">
                    <span>🔗</span>
                    <span>${url}</span>
                </div>
                <button type="button" class="remove-file" data-url="${url}">Remover</button>
            `;

            urlList.appendChild(urlItem);

            // Add event listener
            const removeBtn = urlItem.querySelector('.remove-file');
            removeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                removeURL(url);
            });
        }

        function removeURL(url) {
            urls = urls.filter(u => u !== url);
            updateURLList();
        }

        function updateURLList() {
            const urlList = document.getElementById('urlList');
            urlList.innerHTML = '';
            urls.forEach(url => addURLToList(url));
        }

        // Advanced options toggle
        function toggleAdvanced() {
            const advancedOptions = document.getElementById('advancedOptions');
            advancedOptions.classList.toggle('active');
        }

        // Generate prompt
        function generatePrompt(event) {
            event.preventDefault();

            try {
                // Validate formato selection
                const formatoSelected = document.querySelector('input[name="formato"]:checked');
                if (!formatoSelected) {
                    alert('Por favor, selecione um formato de entrega.');
                    return;
                }

                const formData = {
                    tarefa: document.getElementById('tarefa').value,
                    papel: document.getElementById('papel').value === 'custom'
                        ? document.getElementById('papelCustom').value
                        : document.getElementById('papel').value,
                    publico: document.getElementById('publico').value,
                    contexto: document.getElementById('contexto').value,
                    formalidade: parseInt(document.getElementById('formalidade').value),
                    extensao: parseInt(document.getElementById('extensao').value),
                    criatividade: parseInt(document.getElementById('criatividade').value),
                    urgencia: document.getElementById('urgencia').value,
                    evitar: document.getElementById('evitar').value,
                    formato: formatoSelected.value,
                    formatoDetalhes: document.getElementById('formatoDetalhes').value
                };

                const prompt = buildPrompt(formData);
                displayOutput(prompt);
            } catch (error) {
                console.error('Erro ao gerar prompt:', error);
                alert('Erro ao gerar prompt. Verifique se todos os campos obrigatórios estão preenchidos.');
            }
        }

        function buildPrompt(data) {
            // Mapping sliders to text
            const formalidadeMap = {
                1: 'casual e descontraído',
                2: 'informal mas profissional',
                3: 'neutro e balanceado',
                4: 'formal',
                5: 'muito formal e protocolar'
            };

            const extensaoMap = {
                1: 'muito conciso (1-2 parágrafos)',
                2: 'conciso (2-3 parágrafos)',
                3: 'médio (3-5 parágrafos)',
                4: 'detalhado (5-8 parágrafos)',
                5: 'muito detalhado (análise completa)'
            };

            const criatividadeMap = {
                1: 'estritamente factual',
                2: 'predominantemente factual',
                3: 'balanceado',
                4: 'criativo',
                5: 'altamente criativo e inovador'
            };

            const urgenciaMap = {
                'nao-urgente': '',
                'normal': '',
                'urgente': '\n- URGENTE: Resposta necessária em curto prazo'
            };

            let prompt = `Você é ${data.papel} e precisa: ${data.tarefa}\n\n`;

            prompt += `CONTEXTO:\n`;
            prompt += `Público-alvo: ${data.publico}\n`;
            prompt += `Situação: ${data.contexto}\n\n`;

            // Add files and URLs if present
            if (uploadedFiles.length > 0 || urls.length > 0) {
                prompt += `DOCUMENTOS E FONTES DE REFERÊNCIA:\n`;

                if (uploadedFiles.length > 0) {
                    const textFiles = uploadedFiles.filter(f => f.textContent);
                    const otherFiles = uploadedFiles.filter(f => !f.textContent);

                    if (textFiles.length > 0) {
                        prompt += `\nConteúdo dos arquivos de texto:\n`;
                        textFiles.forEach(file => {
                            prompt += `\n--- INÍCIO: ${file.name} ---\n`;

                            // Add description if provided
                            if (file.description) {
                                prompt += `[INSTRUÇÃO: ${file.description}]\n\n`;
                            }

                            // Limit to first 2000 characters to avoid huge prompts
                            const content = file.textContent.length > 2000
                                ? file.textContent.substring(0, 2000) + '\n[... conteúdo truncado ...]'
                                : file.textContent;
                            prompt += content;
                            prompt += `\n--- FIM: ${file.name} ---\n`;
                        });
                        prompt += '\n';
                    }

                    if (otherFiles.length > 0) {
                        prompt += `Arquivos para anexar ao Gemini antes de usar este prompt:\n`;
                        otherFiles.forEach(file => {
                            if (file.description) {
                                prompt += `- ${file.name} (${(file.size / 1024).toFixed(1)} KB)\n`;
                                prompt += `  → ${file.description}\n`;
                            } else {
                                prompt += `- ${file.name} (${(file.size / 1024).toFixed(1)} KB)\n`;
                            }
                        });
                        prompt += '\n';
                    }
                }

                if (urls.length > 0) {
                    prompt += `URLs para o Gemini consultar:\n`;
                    urls.forEach(urlData => {
                        if (typeof urlData === 'object') {
                            prompt += `- ${urlData.url}\n`;
                            if (urlData.description) {
                                prompt += `  → ${urlData.description}\n`;
                            }
                        } else {
                            prompt += `- ${urlData}\n`;
                        }
                    });
                    prompt += '\n';
                }
            }

            prompt += `RESTRIÇÕES:\n`;
            prompt += `- Tom: ${formalidadeMap[data.formalidade]}\n`;
            prompt += `- Extensão: ${extensaoMap[data.extensao]}\n`;
            prompt += `- Abordagem: ${criatividadeMap[data.criatividade]}\n`;
            if (data.evitar) {
                prompt += `- Evitar: ${data.evitar}\n`;
            }
            prompt += urgenciaMap[data.urgencia];

            prompt += `\n\nFORMATO DE ENTREGA:\n`;
            prompt += `${data.formato}\n`;
            if (data.formatoDetalhes) {
                prompt += `\nEspecificações adicionais:\n${data.formatoDetalhes}\n`;
            }

            // Add task-specific instructions
            const taskInstructions = getTaskSpecificInstructions(currentTask);
            if (taskInstructions) {
                prompt += `\n${taskInstructions}`;
            }

            return prompt;
        }

        function getTaskSpecificInstructions(taskId) {
            const instructions = {
                email: '\nLEMBRETE: Comece com linha de assunto sugerida entre colchetes. Estruture o email de forma escaneável.',
                relatorio: '\nLEMBRETE: Para público executivo, comece com sumário executivo de 3-5 linhas. Separe claramente fatos de recomendações.',
                resumo: '\nLEMBRETE: Preserve terminologia-chave do original. Indique se há partes que requerem leitura completa.',
                revisao: '\nLEMBRETE: Explique brevemente o motivo de cada sugestão significativa. Preserve a voz original a menos que explicitamente solicitado o contrário.',
                planejamento: '\nLEMBRETE: Organize itens de forma lógica (cronológica, por prioridade, ou por categoria). Cada item deve ser acionável.',
                criativo: '\nLEMBRETE: Gere quantidade primeiro, qualidade depois. Inclua ideias óbvias E não-óbvias. Indique viabilidade relativa se relevante.'
            };

            return instructions[taskId] || '';
        }

        function displayOutput(prompt) {
            document.getElementById('promptOutput').textContent = prompt;

            // Display techniques
            const techniques = getTechniquesForTask(currentTask);
            const techniquesList = document.getElementById('techniquesList');
            techniquesList.innerHTML = '';

            techniques.forEach(tech => {
                const li = document.createElement('li');
                li.innerHTML = `
                    <div class="technique-title">${tech.title}</div>
                    <div class="technique-desc">${tech.desc}</div>
                `;
                techniquesList.appendChild(li);
            });

            // Show output
            document.getElementById('outputContainer').classList.add('active');
            document.getElementById('outputContainer').scrollIntoView({ behavior: 'smooth' });
        }

        function getTechniquesForTask(taskId) {
            const commonTechniques = [
                {
                    title: 'Framework de 5 Passos',
                    desc: 'Estrutura consistente (Tarefa → Contexto → Docs → Restrições → Formato) garante que nenhum elemento crítico seja esquecido.'
                },
                {
                    title: 'Definição de Papel (Role Prompting)',
                    desc: 'Especificar seu papel ajusta automaticamente vocabulário, tom e perspectiva da resposta.'
                },
                {
                    title: 'Contextualização de Público',
                    desc: 'Informar quem vai ler/usar o resultado adapta complexidade técnica e tom apropriados.'
                },
                {
                    title: 'Controle Granular de Restrições',
                    desc: 'Sliders de formalidade, extensão e criatividade permitem ajuste fino sem reescrever o prompt.'
                }
            ];

            const taskSpecific = {
                email: {
                    title: 'Especificação de Formato Email',
                    desc: 'Pedir "assunto + corpo estruturado" garante saída imediatamente utilizável, não apenas rascunho.'
                },
                relatorio: {
                    title: 'Separação Fatos vs Análise',
                    desc: 'Estrutura que distingue "o que aconteceu" de "por que aconteceu" de "o que fazer" melhora clareza para tomada de decisão.'
                },
                resumo: {
                    title: 'Preservação de Terminologia',
                    desc: 'Instrução para manter termos-chave evita perda de precisão técnica no processo de resumo.'
                },
                revisao: {
                    title: 'Revisão Explicativa',
                    desc: 'Pedir justificativa das mudanças transforma revisão em aprendizado, não apenas correção.'
                },
                planejamento: {
                    title: 'Itens Acionáveis',
                    desc: 'Instrução para criar tarefas específicas (não abstratas) garante checklist executável.'
                },
                criativo: {
                    title: 'Divergência antes de Convergência',
                    desc: 'Pedir quantidade primeiro estimula exploração ampla; filtro vem depois.'
                }
            };

            return [...commonTechniques, taskSpecific[taskId]];
        }

        function copyPrompt() {
            const promptText = document.getElementById('promptOutput').textContent;
            navigator.clipboard.writeText(promptText).then(() => {
                const btn = event.target;
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '✅ Copiado!';
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                }, 2000);
            });
        }

        function openGemini() {
            const promptText = document.getElementById('promptOutput').textContent;
            // Try to open Gemini (may not work due to browser restrictions)
            const geminiURL = 'https://gemini.google.com/';
            window.open(geminiURL, '_blank');

            // Copy to clipboard as fallback
            navigator.clipboard.writeText(promptText);
            alert('Prompt copiado! Cole no Gemini que acabou de abrir.');
        }

        // Initialize event listeners when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Task card listeners
            document.querySelectorAll('.task-card').forEach(card => {
                card.addEventListener('click', function() {
                    const taskId = this.dataset.task;
                    selectTask(taskId);
                });
            });

            // Back button
            const backButton = document.getElementById('backButton');
            if (backButton) {
                backButton.addEventListener('click', backToSelection);
            }

            // Add URL button
            const addUrlButton = document.getElementById('addUrlButton');
            if (addUrlButton) {
                addUrlButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    addURL();
                });
            }

            // Toggle advanced button
            const toggleAdvancedButton = document.getElementById('toggleAdvancedButton');
            if (toggleAdvancedButton) {
                toggleAdvancedButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleAdvanced();
                });
            }

            // Copy prompt button
            const copyButton = document.getElementById('copyPromptButton');
            if (copyButton) {
                copyButton.addEventListener('click', copyPrompt);
            }

            // Open Gemini button
            const geminiButton = document.getElementById('openGeminiButton');
            if (geminiButton) {
                geminiButton.addEventListener('click', openGemini);
            }

            // Custom role select
            const papelSelect = document.getElementById('papel');
            if (papelSelect) {
                papelSelect.addEventListener('change', function() {
                    const customInput = document.getElementById('papelCustom');
                    if (this.value === 'custom') {
                        customInput.classList.remove('hidden');
                        customInput.required = true;
                    } else {
                        customInput.classList.add('hidden');
                        customInput.required = false;
                    }
                });
            }
        });
    </script>
</body>
</html>
