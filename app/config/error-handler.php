<?php
/**
 * Global Error Handler
 * Captura exceções e erros fatais e registra em MarkdownLogger
 */

use Sunyata\Core\MarkdownLogger;

// Exception Handler - captura exceções não tratadas
set_exception_handler(function($exception) {
    // Log do erro
    try {
        if (class_exists(MarkdownLogger::class, true)) {
            MarkdownLogger::getInstance()->errorWithContext(
                errorMessage: $exception->getMessage(),
                file: $exception->getFile(),
                line: $exception->getLine(),
                stackTrace: $exception->getTraceAsString(),
                context: [
                    'user_id' => $_SESSION['user_id'] ?? 0,
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]
            );
        } else {
            error_log('MarkdownLogger not available. Exception: ' . $exception->getMessage());
        }
    } catch (\Throwable $e) {
        // Se o logger falhar, registrar no error_log padrão
        error_log('MarkdownLogger failed: ' . $e->getMessage());
        error_log('Original exception: ' . $exception->getMessage());
    }

    // Resposta ao usuário
    http_response_code(500);

    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        // Em modo debug, mostrar detalhes
        echo "<h1>Error</h1>";
        echo "<p><strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . ":" . $exception->getLine() . "</p>";
        echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
    } else {
        // Em produção, mensagem genérica
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') ||
            str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            // Para APIs, retornar JSON
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Internal server error',
                'message' => 'An unexpected error occurred. Please try again later.'
            ]);
        } else {
            // Para páginas HTML, mostrar página de erro amigável
            echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Erro - " . (APP_NAME ?? 'Sistema') . "</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .error-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; max-width: 500px; }
        h1 { color: #dc3545; margin: 0 0 20px 0; }
        p { color: #666; line-height: 1.6; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class='error-box'>
        <h1>😔 Ops! Algo deu errado</h1>
        <p>Ocorreu um erro inesperado. Nossa equipe foi notificada e está trabalhando para resolver.</p>
        <p>Por favor, tente novamente em alguns minutos.</p>
        <p><a href='" . (BASE_URL ?? '/') . "'>← Voltar para página inicial</a></p>
    </div>
</body>
</html>";
        }
    }

    exit;
});

// Shutdown Handler - captura erros fatais que exception handler não pega
register_shutdown_function(function() {
    $error = error_get_last();

    // Verificar se foi erro fatal
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        try {
            if (class_exists(MarkdownLogger::class, true)) {
                MarkdownLogger::getInstance()->errorWithContext(
                    errorMessage: $error['message'],
                    file: $error['file'],
                    line: $error['line'],
                    stackTrace: 'Fatal error - no stack trace available',
                    context: [
                        'type' => 'FATAL',
                        'error_type_code' => $error['type'],
                        'user_id' => $_SESSION['user_id'] ?? 0,
                        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
                    ]
                );
            } else {
                error_log('MarkdownLogger not available. Fatal error: ' . $error['message']);
            }
        } catch (\Throwable $e) {
            error_log('MarkdownLogger failed in shutdown handler: ' . $e->getMessage());
            error_log('Fatal error: ' . $error['message']);
        }

        // Limpar output buffer
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Resposta ao usuário para erros fatais
        http_response_code(500);

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            echo "<h1>Fatal Error</h1>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($error['message']) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . ":" . $error['line'] . "</p>";
        } else {
            if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Internal server error']);
            } else {
                echo "<!DOCTYPE html>
<html><head><title>Erro Fatal</title></head>
<body style='font-family: Arial; text-align: center; padding: 50px;'>
<h1 style='color: #dc3545;'>Erro Fatal</h1>
<p>O sistema encontrou um erro crítico. Por favor, tente novamente mais tarde.</p>
<p><a href='" . (BASE_URL ?? '/') . "'>Voltar</a></p>
</body></html>";
            }
        }
    }
});
