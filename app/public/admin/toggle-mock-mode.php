<?php
/**
 * Toggle Mock Mode para Canvas
 * Arquivo temporário para debug
 */

require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

// Toggle mock mode
if (isset($_SESSION['canvas_mock_mode']) && $_SESSION['canvas_mock_mode']) {
    $_SESSION['canvas_mock_mode'] = false;
    $status = 'DESATIVADO';
    $emoji = '❌';
} else {
    $_SESSION['canvas_mock_mode'] = true;
    $status = 'ATIVADO';
    $emoji = '✅';
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mock Mode Toggle</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .status {
            font-size: 48px;
            margin: 20px 0;
        }
        .status-text {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            margin: 10px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
        }
        .btn:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔧 Mock Mode - Canvas</h1>
        <div class="status"><?= $emoji ?></div>
        <div class="status-text">Mock Mode <?= $status ?></div>
        <p>
            <?php if ($_SESSION['canvas_mock_mode']): ?>
                Os canvas vão retornar respostas Lorem Ipsum jurídicas sem chamar a API do Claude.
            <?php else: ?>
                Os canvas vão usar a API real do Claude.
            <?php endif; ?>
        </p>
        <div style="margin-top: 30px;">
            <a href="?" class="btn">Toggle Mock Mode</a>
            <a href="/areas/juridico/canvas-juridico-v2.php" class="btn btn-secondary">Voltar para Canvas</a>
        </div>
    </div>
</body>
</html>
