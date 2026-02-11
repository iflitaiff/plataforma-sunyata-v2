<?php
/**
 * Treinamento EP - Engenharia de Prompts Avançada
 * Wrapper PHP com autenticação para o treinamento corporativo
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

// Verificar acesso à vertical prompt-builder
$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

// Verificar se tem acesso (prompt-builder, demo ou admin)
if ($user_vertical !== 'prompt-builder' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta ferramenta';
    redirect(BASE_URL . '/dashboard.php');
}

// Servir o arquivo HTML diretamente
$htmlFile = __DIR__ . '/treinamentoEPADM.html';
if (file_exists($htmlFile)) {
    // Ler o conteúdo HTML
    $htmlContent = file_get_contents($htmlFile);

    // Adicionar barra de navegação no início do body
    $navBar = '
    <div style="position: fixed; top: 0; left: 0; right: 0; background: rgba(255,255,255,0.95); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 9999;">
        <a href="' . BASE_URL . '/areas/prompt-builder/" style="text-decoration: none; color: #1e3a5f; font-weight: 600; display: flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 8px; border: 2px solid #1e3a5f; transition: all 0.3s;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/></svg>
            Voltar
        </a>
        <span style="color: #5a6c7d; font-size: 0.9rem;">Logado como: <strong>' . htmlspecialchars($_SESSION['user']['name'] ?? 'Usuário') . '</strong></span>
    </div>
    <div style="height: 60px;"></div>
    ';

    // Inserir navbar após a tag <body>
    $htmlContent = preg_replace('/<body([^>]*)>/', '<body$1>' . $navBar, $htmlContent, 1);

    echo $htmlContent;
} else {
    $_SESSION['error'] = 'Arquivo de treinamento não encontrado';
    redirect(BASE_URL . '/areas/prompt-builder/');
}
