<?php
/**
 * Admin: Processar criação de Canvas do Zero
 *
 * @package Sunyata\Admin
 * @since 2026-02-18 (Fase 3.5)
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
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Método inválido';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Verificar CSRF
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Token de segurança inválido';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

$db = Database::getInstance();

// Validar campos obrigatórios
$required = ['nome', 'slug', 'vertical'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        $_SESSION['error'] = "Campo obrigatório ausente: $field";
        redirect(BASE_URL . '/admin/canvas-templates.php');
    }
}

// Validar slug format
if (!preg_match('/^[a-z0-9-]+$/', $_POST['slug'])) {
    $_SESSION['error'] = 'Slug inválido. Use apenas letras minúsculas, números e hífens.';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Verificar se slug já existe
try {
    $existing = $db->fetchOne("
        SELECT id FROM canvas_templates WHERE slug = :slug
    ", ['slug' => $_POST['slug']]);

    if ($existing) {
        $_SESSION['error'] = "Slug '{$_POST['slug']}' já existe. Escolha outro.";
        redirect(BASE_URL . '/admin/canvas-templates.php');
    }
} catch (Exception $e) {
    // Ignorar erro se tabela não existe
}

// Form config inicial vazio (estrutura mínima SurveyJS)
$formConfigInicial = json_encode([
    'title' => $_POST['nome'],
    'description' => 'Canvas criado via admin. Use o Editor Visual para adicionar campos.',
    'pages' => [
        [
            'name' => 'page1',
            'elements' => [
                [
                    'type' => 'comment',
                    'name' => 'placeholder',
                    'title' => 'Este canvas está vazio. Use o Editor Visual para adicionar campos.',
                    'isRequired' => false,
                ]
            ]
        ]
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Preparar dados para inserção
$canvasData = [
    'slug' => $_POST['slug'],
    'name' => $_POST['nome'],
    'vertical' => $_POST['vertical'],
    'form_config' => $formConfigInicial,
    'system_prompt' => $_POST['system_prompt'] ?? '',
    'user_prompt_template' => '', // Vazio = auto-geração
    'max_questions' => 5,
    'is_active' => isset($_POST['is_active']),
    'status' => 'draft', // Criado como draft
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
];

try {
    $canvasId = $db->insert('canvas_templates', $canvasData);

    $_SESSION['success'] = "Canvas '{$_POST['nome']}' criado com sucesso! Agora você pode usar o Editor Visual para adicionar campos.";

    // Redirect para página de edição
    redirect(BASE_URL . "/admin/canvas-edit.php?id=$canvasId");

} catch (Exception $e) {
    error_log("Erro ao criar canvas: " . $e->getMessage());
    $_SESSION['error'] = 'Erro ao criar canvas: ' . $e->getMessage();
    redirect(BASE_URL . '/admin/canvas-templates.php');
}
