<?php
/**
 * Admin: Canvas Clone - Processar Clonagem
 * Clona um Canvas template existente com novo slug, nome e vertical
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
    $_SESSION['error'] = 'Método inválido.';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Validar CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error'] = 'Token CSRF inválido. Tente novamente.';
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

// Pegar dados do formulário
$source_id = $_POST['source_id'] ?? null;
$new_slug = trim($_POST['new_slug'] ?? '');
$new_name = trim($_POST['new_name'] ?? '');
$new_vertical = $_POST['new_vertical'] ?? null;
$is_active = isset($_POST['is_active']) ? 1 : 0;

// Validações
$errors = [];

if (!$source_id || !is_numeric($source_id)) {
    $errors[] = 'Canvas de origem inválido.';
}

if (empty($new_slug)) {
    $errors[] = 'Slug é obrigatório.';
} elseif (!preg_match('/^[a-z0-9-]+$/', $new_slug)) {
    $errors[] = 'Slug deve conter apenas letras minúsculas, números e hífens.';
}

if (empty($new_name)) {
    $errors[] = 'Nome é obrigatório.';
}

if (empty($new_vertical)) {
    $errors[] = 'Vertical é obrigatória.';
}

// Validar vertical
$verticals_config = require __DIR__ . '/../../config/verticals.php';
$valid_verticals = array_keys($verticals_config);

if (!in_array($new_vertical, $valid_verticals)) {
    $errors[] = 'Vertical inválida.';
}

// Se há erros, redirecionar
if (!empty($errors)) {
    $_SESSION['error'] = implode('<br>', $errors);
    redirect(BASE_URL . '/admin/canvas-templates.php');
}

try {
    $db = Database::getInstance();

    // Buscar canvas de origem
    $source_canvas = $db->fetchOne("
        SELECT *
        FROM canvas_templates
        WHERE id = :id
    ", ['id' => $source_id]);

    if (!$source_canvas) {
        $_SESSION['error'] = 'Canvas de origem não encontrado.';
        redirect(BASE_URL . '/admin/canvas-templates.php');
    }

    // Verificar se slug já existe
    $existing = $db->fetchOne("
        SELECT id FROM canvas_templates WHERE slug = :slug
    ", ['slug' => $new_slug]);

    if ($existing) {
        $_SESSION['error'] = 'Já existe um Canvas com o slug "' . $new_slug . '". Escolha outro slug.';
        redirect(BASE_URL . '/admin/canvas-templates.php');
    }

    // Clonar canvas (inserir novo registro) - Phase 3.5: no 'vertical' column
    $db->execute("
        INSERT INTO canvas_templates (
            slug,
            name,
            form_config,
            system_prompt,
            user_prompt_template,
            max_questions,
            is_active,
            created_at,
            updated_at
        ) VALUES (
            :slug,
            :name,
            :form_config,
            :system_prompt,
            :user_prompt_template,
            :max_questions,
            :is_active,
            NOW(),
            NOW()
        )
    ", [
        'slug' => $new_slug,
        'name' => $new_name,
        'form_config' => $source_canvas['form_config'],
        'system_prompt' => $source_canvas['system_prompt'],
        'user_prompt_template' => $source_canvas['user_prompt_template'],
        'max_questions' => $source_canvas['max_questions'],
        'is_active' => $is_active
    ]);

    $new_id = $db->lastInsertId('canvas_templates_id_seq');

    // Phase 3.5: Create vertical assignment in junction table
    $db->execute("
        INSERT INTO canvas_vertical_assignments (canvas_id, vertical_slug, display_order, created_at, updated_at)
        VALUES (:canvas_id, :vertical_slug, 0, NOW(), NOW())
    ", [
        'canvas_id' => $new_id,
        'vertical_slug' => $new_vertical
    ]);

    // Log da ação
    error_log(sprintf(
        '[ADMIN] User %d cloned canvas %d (%s) to new canvas %d (%s) in vertical %s',
        $_SESSION['user_id'],
        $source_id,
        $source_canvas['slug'],
        $new_id,
        $new_slug,
        $new_vertical
    ));

    $_SESSION['success'] = sprintf(
        'Canvas "%s" clonado com sucesso! Novo canvas: "%s" (ID: %d)',
        $source_canvas['name'],
        $new_name,
        $new_id
    );

    redirect(BASE_URL . '/admin/canvas-templates.php');

} catch (Exception $e) {
    error_log('Error cloning canvas: ' . $e->getMessage());
    $_SESSION['error'] = 'Erro ao clonar Canvas: ' . $e->getMessage();
    redirect(BASE_URL . '/admin/canvas-templates.php');
}
