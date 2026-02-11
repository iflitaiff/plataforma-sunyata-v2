<?php
/**
 * API Endpoint: Exportar Conversa para PDF
 *
 * Gera PDF de uma conversa completa com todas as mensagens
 *
 * Method: GET or POST
 * Parameters: conversation_id (int)
 *
 * @return PDF file (binary)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';

use App\Services\ConversationService;
use App\Database\Database;
use Mpdf\Mpdf;

// Start session for authentication
session_start();

try {
    // 1. Validate authentication
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'User not authenticated'
        ]);
        exit;
    }

    $userId = (int) $_SESSION['user_id'];

    // Bug #7 Fix: Accept only POST (no GET to prevent CSRF)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed',
            'message' => 'Only POST requests are accepted'
        ]);
        exit;
    }

    // 2. Validate CSRF token
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'CSRF validation failed',
            'message' => 'Invalid or missing CSRF token'
        ]);
        exit;
    }

    // 3. Parse JSON body
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON',
            'message' => 'Request body must be valid JSON'
        ]);
        exit;
    }

    $conversationId = isset($data['conversation_id']) ? (int) $data['conversation_id'] : null;

    // 3. Validate conversation_id
    if ($conversationId === null || $conversationId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Missing conversation_id',
            'message' => 'The "conversation_id" parameter is required'
        ]);
        exit;
    }

    // 4. Fetch conversation and validate ownership
    $db = Database::getInstance();

    $conversation = $db->fetchOne(
        'SELECT id, user_id, title, created_at, updated_at
         FROM conversations
         WHERE id = ? AND deleted_at IS NULL',
        [$conversationId]
    );

    if (!$conversation) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Conversation not found',
            'message' => 'Conversation does not exist or has been deleted'
        ]);
        exit;
    }

    // Check ownership
    if ((int) $conversation['user_id'] !== $userId) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Access denied',
            'message' => 'You do not have permission to access this conversation'
        ]);
        exit;
    }

    // 5. Fetch messages in conversation (Bug #11 Fix: limit to 500)
    $maxMessages = 500;

    $messages = $db->fetchAll(
        'SELECT id, role, content, created_at
         FROM conversation_messages
         WHERE conversation_id = ?
         ORDER BY created_at ASC
         LIMIT ?',
        [$conversationId, $maxMessages]
    );

    if (empty($messages)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Empty conversation',
            'message' => 'This conversation has no messages to export'
        ]);
        exit;
    }

    // Check if conversation was truncated
    $totalMessagesResult = $db->fetchOne(
        'SELECT COUNT(*) as count FROM conversation_messages WHERE conversation_id = ?',
        [$conversationId]
    );
    $totalMessages = (int) $totalMessagesResult['count'];
    $wasTruncated = $totalMessages > $maxMessages;

    // 6. Generate PDF using mPDF
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 20,
        'margin_bottom' => 20,
        'margin_header' => 10,
        'margin_footer' => 10
    ]);

    // Set PDF metadata
    $title = htmlspecialchars($conversation['title'], ENT_QUOTES, 'UTF-8');
    $mpdf->SetTitle($title);
    $mpdf->SetAuthor('Plataforma Sunyata');
    $mpdf->SetCreator('Plataforma Sunyata - Claude AI');

    // Build HTML content
    $html = '
    <style>
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 11pt;
            line-height: 1.6;
        }
        h1 {
            color: #1f6feb;
            font-size: 18pt;
            margin-bottom: 5px;
        }
        .conversation-info {
            color: #666;
            font-size: 9pt;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #1f6feb;
        }
        .message {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 8px;
            page-break-inside: avoid;
        }
        .message.user {
            background-color: #f0f6ff;
            border-left: 4px solid #1f6feb;
        }
        .message.assistant {
            background-color: #f9f9f9;
            border-left: 4px solid #238636;
        }
        .message-header {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 10pt;
        }
        .message.user .message-header {
            color: #1f6feb;
        }
        .message.assistant .message-header {
            color: #238636;
        }
        .message-timestamp {
            color: #666;
            font-size: 8pt;
            font-style: italic;
        }
        .message-content {
            color: #24292f;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }
    </style>
    ';

    $html .= '<h1>' . $title . '</h1>';
    $html .= '<div class="conversation-info">';
    $html .= 'Criada em: ' . date('d/m/Y H:i', strtotime($conversation['created_at'])) . '<br>';
    $html .= 'Total de mensagens: ' . count($messages);
    $html .= '</div>';

    // Bug #11 Fix: Warn if conversation was truncated
    if ($wasTruncated) {
        $html .= '<div style="background:#fff3cd;padding:10px;margin-bottom:20px;border-left:4px solid #ffc107;">';
        $html .= '⚠️ <strong>Aviso:</strong> Esta conversa possui ' . $totalMessages . ' mensagens. ';
        $html .= 'Apenas as primeiras ' . $maxMessages . ' mensagens foram exportadas.';
        $html .= '</div>';
    }

    // Add messages
    foreach ($messages as $message) {
        $role = htmlspecialchars($message['role'], ENT_QUOTES, 'UTF-8');
        $content = htmlspecialchars($message['content'], ENT_QUOTES, 'UTF-8');
        $timestamp = date('d/m/Y H:i', strtotime($message['created_at']));

        $roleLabel = $role === 'user' ? 'Você' : 'Assistente IA';
        $messageClass = $role === 'user' ? 'user' : 'assistant';

        $html .= '<div class="message ' . $messageClass . '">';
        $html .= '<div class="message-header">' . $roleLabel;
        $html .= ' <span class="message-timestamp">(' . $timestamp . ')</span>';
        $html .= '</div>';
        $html .= '<div class="message-content">' . nl2br($content) . '</div>';
        $html .= '</div>';
    }

    $html .= '<div class="footer">';
    $html .= 'Exportado em ' . date('d/m/Y H:i') . ' pela Plataforma Sunyata';
    $html .= '</div>';

    // Write HTML to PDF
    $mpdf->WriteHTML($html);

    // Bug #5 Fix: Generate PDF to string FIRST (don't send headers yet)
    $pdfContent = $mpdf->Output('', 'S'); // 'S' = return as string

    // 7. Output PDF as download
    // Now that PDF is successfully generated, send headers and content
    $filename = 'conversa-' . $conversationId . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));

    echo $pdfContent;
    exit;

} catch (Exception $e) {
    // Log unexpected errors
    error_log('API Error (export-conversation.php): ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'PDF generation failed',
        'message' => 'An unexpected error occurred while generating the PDF'
    ]);
}
