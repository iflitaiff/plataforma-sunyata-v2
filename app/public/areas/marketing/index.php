<?php
/**
 * Vertical: Marketing (Em breve)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

$pageTitle = 'Vertical: Marketing - Em Breve';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .coming-soon-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>
<body>
    <div class="coming-soon-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow-lg">
                        <div class="card-body text-center p-5">
                            <div style="font-size: 5rem;" class="mb-4">📢</div>
                            <h1 class="mb-3">Marketing</h1>
                            <p class="text-muted lead mb-4">Recursos para criação de conteúdo, campanhas e estratégias de marketing digital</p>

                            <div class="alert alert-warning">
                                <strong>🚧 Em Desenvolvimento</strong>
                                <p class="mb-0 mt-2">
                                    Esta vertical está sendo desenvolvida e estará disponível em breve.
                                    Você receberá um email quando for liberada!
                                </p>
                            </div>

                            <div class="d-grid gap-2 mt-4">
                                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary">
                                    ← Voltar ao Dashboard
                                </a>
                                <a href="<?= BASE_URL ?>/onboarding-step2.php" class="btn btn-outline-secondary">
                                    Escolher Outra Vertical
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
