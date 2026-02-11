<?php
/**
 * IATR - Canvas Redirect (Rota B)
 * In v2, canvas_templates is the unified table. This page redirects to
 * formulario.php for backward compatibility with old URLs.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

$canvas_id = $_GET['id'] ?? null;

if (!$canvas_id || !is_numeric($canvas_id)) {
    $_SESSION['error'] = 'Canvas inválido';
    redirect(BASE_URL . '/areas/iatr/index.php');
}

$db = Database::getInstance();

// In v2, the canvas IS a canvas_template row
$template = $db->fetchOne("
    SELECT slug FROM canvas_templates
    WHERE id = :id AND vertical = 'iatr' AND is_active = TRUE
", ['id' => $canvas_id]);

if (!$template) {
    $_SESSION['error'] = 'Canvas não encontrado ou inativo';
    redirect(BASE_URL . '/areas/iatr/index.php');
}

// Redirect to formulario.php
redirect(BASE_URL . '/areas/iatr/formulario.php?template=' . $template['slug']);
