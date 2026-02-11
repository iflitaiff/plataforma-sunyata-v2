<?php
/**
 * Meu Trabalho — Submission detail view.
 *
 * Shows the full submission: result, form data, metadata, version history.
 * Redirects to canvas/result.php which is the primary viewer.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

$submissionId = (int)($_GET['id'] ?? 0);
if (!$submissionId) {
    $_SESSION['error'] = 'Submissao nao especificada.';
    redirect(BASE_URL . '/meu-trabalho/');
}

// Redirect to the canonical result viewer
redirect(BASE_URL . '/canvas/result.php?id=' . $submissionId);
