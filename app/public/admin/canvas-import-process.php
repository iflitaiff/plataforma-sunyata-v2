<?php
/**
 * Admin: Canvas Import - Processing
 * Processa o upload de JSON e cria novo Canvas
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

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Método não permitido';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Verificar CSRF
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Token de segurança inválido';
    redirect(BASE_URL . '/admin/canvas-import.php');
}

$db = Database::getInstance();

try {
    // Validar dados obrigatórios
    $canvasName = trim($_POST['canvas_name'] ?? '');
    $canvasSlug = trim($_POST['canvas_slug'] ?? '');
    $canvasVertical = trim($_POST['canvas_vertical'] ?? '');
    $systemPrompt = trim($_POST['system_prompt'] ?? '');
    $maxQuestions = (int)($_POST['max_questions'] ?? 5);
    $jsonData = trim($_POST['json_data'] ?? '');
    $useAutoGeneration = isset($_POST['use_auto_generation']);
    $activateNow = isset($_POST['activate_now']);

    // Validações
    if (empty($canvasName)) {
        throw new Exception('Nome do Canvas é obrigatório');
    }

    if (empty($canvasSlug)) {
        throw new Exception('Slug do Canvas é obrigatório');
    }

    // Validar formato do slug
    if (!preg_match('/^[a-z0-9-]+$/', $canvasSlug)) {
        throw new Exception('Slug inválido. Use apenas letras minúsculas, números e hífens.');
    }

    // Verificar se slug já existe
    $existing = $db->fetchOne("
        SELECT id FROM canvas_templates WHERE slug = :slug
    ", ['slug' => $canvasSlug]);

    if ($existing) {
        throw new Exception('Já existe um Canvas com este slug: ' . $canvasSlug);
    }

    if (empty($canvasVertical)) {
        throw new Exception('Vertical é obrigatória');
    }

    if (empty($systemPrompt)) {
        throw new Exception('System Prompt é obrigatório');
    }

    if (empty($jsonData)) {
        throw new Exception('JSON data não fornecido');
    }

    // Validar JSON
    $formConfig = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido: ' . json_last_error_msg());
    }

    // Validar estrutura mínima
    if (!isset($formConfig['pages']) || !is_array($formConfig['pages'])) {
        throw new Exception('JSON inválido: propriedade "pages" não encontrada');
    }

    // User Prompt Template
    $userPromptTemplate = '';

    if (!$useAutoGeneration) {
        // Se não usar auto-geração, criar template Handlebars básico
        $fields = [];
        foreach ($formConfig['pages'] as $page) {
            if (isset($page['elements'])) {
                foreach ($page['elements'] as $element) {
                    if (isset($element['name']) && ($element['type'] ?? '') !== 'html') {
                        $fields[] = $element['name'];
                    }
                }
            }
        }

        // Template básico com todos os campos
        $templateParts = [];
        foreach ($fields as $field) {
            $label = strtoupper(str_replace(['_', '-'], ' ', $field));
            $templateParts[] = "**{$label}:**\n{{{{$field}}}}\n";
        }

        $userPromptTemplate = implode("\n", $templateParts);
    }
    // Se $useAutoGeneration = true, deixar vazio (auto-geração ativa)

    // Inserir Canvas
    $insertData = [
        'slug' => $canvasSlug,
        'name' => $canvasName,
        'vertical' => $canvasVertical,
        'form_config' => $jsonData,
        'system_prompt' => $systemPrompt,
        'user_prompt_template' => $userPromptTemplate,
        'max_questions' => $maxQuestions,
        'is_active' => $activateNow ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $canvasId = $db->insert('canvas_templates', $insertData);

    if ($canvasId) {
        // Log de auditoria (opcional)
        error_log("Canvas imported successfully: ID=$canvasId, slug=$canvasSlug, user=" . $_SESSION['user_id']);

        $_SESSION['success'] = "✅ Canvas '{$canvasName}' criado com sucesso!" .
            ($activateNow ? ' (ATIVO)' : ' (Inativo - edite para ativar)') .
            ($useAutoGeneration ? ' | Auto-geração de prompt habilitada.' : '');

        // Redirecionar para edição do Canvas
        redirect(BASE_URL . '/admin/canvas-edit.php?id=' . $canvasId);

    } else {
        throw new Exception('Erro ao inserir Canvas no banco de dados');
    }

} catch (Exception $e) {
    $_SESSION['error'] = 'Erro ao importar Canvas: ' . $e->getMessage();
    error_log('Canvas import error: ' . $e->getMessage());

    // Redirecionar de volta para a página de import
    redirect(BASE_URL . '/admin/canvas-import.php');
}
