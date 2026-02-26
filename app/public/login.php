<?php
/**
 * Login Page — Email/Password
 *
 * @package Sunyata
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Auth\PasswordAuth;

// Redirect if already logged in
if (isset($_SESSION['user'])) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token de segurança inválido. Recarregue a página.';
    } else {
        $auth = new PasswordAuth();
        $action = $_POST['action'] ?? 'login';

        if ($action === 'register') {
            $result = $auth->register(
                $_POST['email'] ?? '',
                $_POST['password'] ?? '',
                $_POST['name'] ?? ''
            );

            if ($result['success']) {
                // Auto-login after registration
                $loginResult = $auth->login($_POST['email'], $_POST['password']);
                if ($loginResult['success']) {
                    redirect(consume_redirect_after_login() ?: BASE_URL . '/dashboard.php');
                }
                $success = 'Conta criada com sucesso! Faça login.';
            } else {
                $error = $result['error'];
            }
        } else {
            $result = $auth->login(
                $_POST['email'] ?? '',
                $_POST['password'] ?? ''
            );

            if ($result['success']) {
                redirect(consume_redirect_after_login() ?: BASE_URL . '/dashboard.php');
            } else {
                $error = $result['error'];
            }
        }
    }
}

$tab = $_GET['tab'] ?? 'login';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row min-vh-100 align-items-center justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="text-center mb-4">
                    <h1 class="h3 fw-bold text-primary"><?= APP_NAME ?></h1>
                    <p class="text-muted">Ensino e Consultoria em IA Generativa</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= sanitize_output($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= sanitize_output($success) ?></div>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <ul class="nav nav-tabs mb-4" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $tab === 'login' ? 'active' : '' ?>" id="login-tab" data-bs-toggle="tab" data-bs-target="#login-pane" type="button">Entrar</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $tab === 'register' ? 'active' : '' ?>" id="register-tab" data-bs-toggle="tab" data-bs-target="#register-pane" type="button">Criar Conta</button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <!-- Login Tab -->
                            <div class="tab-pane fade <?= $tab === 'login' ? 'show active' : '' ?>" id="login-pane">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="login">
                                    <div class="mb-3">
                                        <label for="login-email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="login-email" name="email" required autofocus>
                                    </div>
                                    <div class="mb-3">
                                        <label for="login-password" class="form-label">Senha</label>
                                        <input type="password" class="form-control" id="login-password" name="password" required>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">Entrar</button>
                                    </div>
                                </form>
                            </div>

                            <!-- Register Tab -->
                            <div class="tab-pane fade <?= $tab === 'register' ? 'show active' : '' ?>" id="register-pane">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="register">
                                    <div class="mb-3">
                                        <label for="reg-name" class="form-label">Nome completo</label>
                                        <input type="text" class="form-control" id="reg-name" name="name" required minlength="2">
                                    </div>
                                    <div class="mb-3">
                                        <label for="reg-email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="reg-email" name="email" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="reg-password" class="form-label">Senha</label>
                                        <input type="password" class="form-control" id="reg-password" name="password" required minlength="8">
                                        <div class="form-text">Mínimo 8 caracteres.</div>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-success">Criar Conta</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <a href="<?= BASE_URL ?>/" class="text-muted small">Voltar para a página inicial</a>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
