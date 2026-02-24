<?php
/**
 * Generic Edital Resolver
 *
 * Entry point for deep-links from PNCP email notifications.
 * Redirects to the correct vertical's edital page based on user session.
 *
 * GET /edital.php?pncp_id=xxx           — view edital
 * GET /edital.php?pncp_id=xxx&acao=analise — view + auto-trigger AI analysis
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

// require_login() will save REQUEST_URI to session before redirecting
require_login();

// Build query string to forward
$pncpId = $_GET['pncp_id'] ?? null;
$id = $_GET['id'] ?? null;
$acao = $_GET['acao'] ?? null;

if (!$pncpId && !$id) {
    $_SESSION['error'] = 'ID do edital não informado';
    redirect(BASE_URL . '/dashboard.php');
}

// Detect user's vertical
$userVertical = $_SESSION['user']['selected_vertical'] ?? null;

// Verticals that have edital pages
$editalVerticals = ['iatr', 'licitacoes'];

// Use user's vertical if it supports editais, otherwise fallback to iatr
$targetVertical = in_array($userVertical, $editalVerticals) ? $userVertical : 'iatr';

// Build redirect URL preserving query params
$params = [];
if ($pncpId) $params['pncp_id'] = $pncpId;
if ($id) $params['id'] = $id;
if ($acao) $params['acao'] = $acao;

redirect(BASE_URL . '/areas/' . $targetVertical . '/edital.php?' . http_build_query($params));
