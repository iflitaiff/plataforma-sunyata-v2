<?php
/**
 * Account Deleted - Confirmação de conta deletada
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

// Não requer login - conta já foi deletada
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conta Deletada - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-body p-5 text-center">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>

                        <h2 class="mt-4 mb-3">Conta Deletada com Sucesso</h2>

                        <p class="text-muted mb-4">
                            Todos os seus dados foram permanentemente removidos do nosso sistema,
                            conforme solicitado.
                        </p>

                        <div class="alert alert-info text-start">
                            <h6 class="alert-heading">✅ O que foi removido:</h6>
                            <ul class="mb-0 small">
                                <li>Dados pessoais (nome, email)</li>
                                <li>Histórico de uso</li>
                                <li>Prompts e interações</li>
                                <li>Solicitações de acesso</li>
                                <li>Logs e consentimentos</li>
                            </ul>
                        </div>

                        <div class="alert alert-success">
                            <strong>🔒 Privacidade Respeitada</strong>
                            <p class="mb-0 small">
                                Sua solicitação foi processada em conformidade com a LGPD (Lei Geral
                                de Proteção de Dados).
                            </p>
                        </div>

                        <hr class="my-4">

                        <h5 class="mb-3">Deseja voltar?</h5>
                        <p class="text-muted mb-4">
                            Você pode criar uma nova conta a qualquer momento.
                        </p>

                        <div class="d-grid gap-2">
                            <a href="<?= BASE_URL ?>/" class="btn btn-primary">
                                <i class="bi bi-house-door"></i>
                                Ir para Página Inicial
                            </a>
                            <a href="<?= BASE_URL ?>/login.php" class="btn btn-outline-secondary">
                                <i class="bi bi-box-arrow-in-right"></i>
                                Criar Nova Conta
                            </a>
                        </div>

                        <div class="mt-4 pt-4 border-top">
                            <p class="text-muted small mb-0">
                                Obrigado por ter usado nossos serviços.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
