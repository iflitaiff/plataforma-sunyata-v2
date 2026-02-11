<?php
/**
 * API: Send Canvas Response via Email
 * Envia análise jurídica por email para destinatário especificado
 */

require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

// Headers
header('Content-Type: application/json; charset=utf-8');

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Pegar dados JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido']);
    exit;
}

$toEmail = $input['to_email'] ?? null;
$htmlContent = $input['html_content'] ?? null;
$canvasName = $input['canvas_name'] ?? 'Análise Jurídica';

// Validações
if (!$toEmail || !$htmlContent) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'to_email e html_content são obrigatórios']);
    exit;
}

// Validar formato de email
if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email inválido']);
    exit;
}

// Limitar tamanho do HTML (anti-abuse)
if (strlen($htmlContent) > 500000) { // Max 500KB
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Conteúdo muito grande']);
    exit;
}

try {
    // Preparar email
    $userName = $_SESSION['user']['name'] ?? 'Usuário';
    $userEmail = $_SESSION['user']['email'] ?? SUPPORT_EMAIL;

    $subject = "[$canvasName] - Plataforma Sunyata";

    // Template HTML do email
    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.6;
                color: #2c3e50;
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
            }
            .header {
                background: linear-gradient(135deg, #1a365d 0%, #2d5a87 100%);
                color: white;
                padding: 20px;
                border-radius: 8px 8px 0 0;
                text-align: center;
            }
            .content {
                background: white;
                padding: 30px;
                border: 1px solid #e9ecef;
            }
            .footer {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 0 0 8px 8px;
                text-align: center;
                font-size: 12px;
                color: #6c757d;
            }
            h1, h2, h3, h4 {
                color: #1a365d;
            }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>📋 " . htmlspecialchars($canvasName) . "</h1>
            <p>Plataforma Sunyata Consulting</p>
        </div>
        <div class='content'>
            " . $htmlContent . "
        </div>
        <div class='footer'>
            <p><strong>Enviado por:</strong> " . htmlspecialchars($userName) . " (" . htmlspecialchars($userEmail) . ")</p>
            <p><strong>Data:</strong> " . date('d/m/Y H:i:s') . "</p>
            <p>Este email foi enviado através da Plataforma Sunyata Consulting.</p>
            <p><a href='https://portal.sunyataconsulting.com'>portal.sunyataconsulting.com</a></p>
        </div>
    </body>
    </html>
    ";

    // Headers do email
    $headers = array();
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=utf-8';
    $headers[] = 'From: Plataforma Sunyata <noreply@sunyataconsulting.com>';
    $headers[] = 'Reply-To: ' . $userEmail;
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headers[] = 'X-Sender: Plataforma Sunyata';

    // Enviar email
    $success = mail(
        $toEmail,
        $subject,
        $emailBody,
        implode("\r\n", $headers)
    );

    if ($success) {
        // Log de auditoria
        error_log(sprintf(
            'Email sent - User: %d, To: %s, Canvas: %s',
            $_SESSION['user_id'],
            $toEmail,
            $canvasName
        ));

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Email enviado com sucesso para ' . $toEmail
        ]);
    } else {
        throw new Exception('Falha ao enviar email');
    }

} catch (Exception $e) {
    error_log('Email send error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao enviar email: ' . $e->getMessage()
    ]);
}
