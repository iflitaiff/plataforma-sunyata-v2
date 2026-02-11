<?php
/**
 * API Endpoint: Chat com IA
 *
 * Envia mensagem para Claude AI com contexto de documentos anexados
 *
 * Method: POST
 * Content-Type: application/json
 * Headers Required: X-CSRF-Token
 *
 * @return JSON
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';

use App\Services\ConversationService;
use App\Services\FileUploadService;
use App\AI\ClaudeService;

// Start session for authentication and CSRF
session_start();

// Set JSON response header
header('Content-Type: application/json');

try {
    // 1. Validate HTTP method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed',
            'message' => 'Only POST requests are accepted'
        ]);
        exit;
    }

    // 2. Validate authentication
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'User not authenticated'
        ]);
        exit;
    }

    // 3. Validate CSRF token
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'CSRF validation failed',
            'message' => 'Invalid or missing CSRF token'
        ]);
        exit;
    }

    // 4. Parse JSON body
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON',
            'message' => 'Request body must be valid JSON'
        ]);
        exit;
    }

    // 5. Validate required fields
    if (!isset($data['message']) || empty(trim($data['message']))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing message',
            'message' => 'The "message" field is required and cannot be empty'
        ]);
        exit;
    }

    $userMessage = trim($data['message']);

    // Bug #6 Fix: Validate message length
    $maxMessageLength = 50000; // 50,000 characters (~10,000 words)
    if (strlen($userMessage) > $maxMessageLength) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Message too long',
            'message' => "Message cannot exceed {$maxMessageLength} characters",
            'current_length' => strlen($userMessage),
            'max_length' => $maxMessageLength
        ]);
        exit;
    }

    $conversationId = isset($data['conversation_id']) ? (int) $data['conversation_id'] : null;
    $fileIds = isset($data['file_ids']) && is_array($data['file_ids']) ? $data['file_ids'] : [];

    $userId = (int) $_SESSION['user_id'];

    // Get service instances
    $conversationService = ConversationService::getInstance();
    $claudeService = new ClaudeService();

    // 5.1. Check chat rate limit (Bug #1 Fix)
    $rateLimitResult = $conversationService->checkChatRateLimit($userId);

    if (!$rateLimitResult['allowed']) {
        http_response_code(429); // Too Many Requests
        echo json_encode([
            'success' => false,
            'error' => 'Rate limit exceeded',
            'message' => 'You have exceeded the chat rate limit. Please try again later.',
            'retry_after' => $rateLimitResult['retry_after'], // seconds
            'current_count' => $rateLimitResult['current_count'],
            'limit' => $rateLimitResult['limit']
        ]);
        exit;
    }

    // 6. Create or validate conversation
    if ($conversationId === null) {
        // Create new conversation
        $createResult = $conversationService->createConversation($userId, 'Nova conversa');

        if (!$createResult['success']) {
            http_response_code(500);
            echo json_encode($createResult);
            exit;
        }

        $conversationId = $createResult['conversation_id'];
    } else {
        // Bug #3 Fix: Validate that conversation belongs to user (EXPLICIT CHECK)
        $conversation = $conversationService->getConversation($conversationId, $userId);

        if (!$conversation) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Conversation not found',
                'message' => 'Conversation does not exist or you do not have access to it'
            ]);
            exit;
        }
    }

    // 7. Attach files to conversation (if provided)
    if (!empty($fileIds)) {
        // Bug #8 Fix: Validate ownership of EACH file BEFORE attaching
        $fileUploadService = FileUploadService::getInstance();

        foreach ($fileIds as $fileId) {
            $fileData = $fileUploadService->getFileById((int) $fileId, $userId);

            if (!$fileData) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'File access denied',
                    'message' => "You do not have access to file ID: {$fileId}"
                ]);
                exit;
            }
        }

        // Now attach files (ownership already validated)
        $attachResult = $conversationService->attachFiles($conversationId, $userId, $fileIds);

        if (!$attachResult['success']) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'File attachment failed',
                'message' => $attachResult['message']
            ]);
            exit;
        }
    }

    // 8. Add user message to conversation
    $addUserMessageResult = $conversationService->addMessage(
        $conversationId,
        'user',
        $userMessage,
        $userId
    );

    if (!$addUserMessageResult['success']) {
        http_response_code(400);
        echo json_encode($addUserMessageResult);
        exit;
    }

    $userMessageId = $addUserMessageResult['message_id'];

    // 9. Generate AI response with context
    $aiResult = $claudeService->generateWithContext($userMessage, $fileIds, $userId);

    if (!$aiResult['success']) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'AI generation failed',
            'message' => $aiResult['message'] ?? 'Failed to generate AI response'
        ]);
        exit;
    }

    $aiResponse = $aiResult['response'];

    // 10. Add AI response to conversation
    $addAiMessageResult = $conversationService->addMessage(
        $conversationId,
        'assistant',
        $aiResponse,
        $userId
    );

    if (!$addAiMessageResult['success']) {
        http_response_code(500);
        echo json_encode($addAiMessageResult);
        exit;
    }

    $aiMessageId = $addAiMessageResult['message_id'];

    // 11. Generate conversation title if it's the first exchange
    $title = null;
    $titleResult = $conversationService->generateTitle($conversationId);
    if ($titleResult['success']) {
        $title = $titleResult['title'];
    }

    // 12. Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'conversation_id' => $conversationId,
        'user_message_id' => $userMessageId,
        'ai_message_id' => $aiMessageId,
        'response' => $aiResponse,
        'title' => $title
    ]);

} catch (Exception $e) {
    // Log unexpected errors
    error_log('API Error (chat.php): ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'An unexpected error occurred while processing your request'
    ]);
}
