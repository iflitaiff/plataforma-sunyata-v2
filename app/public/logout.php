<?php
/**
 * Logout Handler
 *
 * @package Sunyata
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Auth\GoogleAuth;

$auth = new GoogleAuth();
$auth->logout();

redirect(BASE_URL . '/index.php');
