<?php
/**
 * Conversation Service
 *
 * Manages conversations, messages, and file attachments for Canvas interactions
 *
 * @package Sunyata\Services
 */

namespace Sunyata\Services;

use Sunyata\Core\Database;
use Exception;

class ConversationService {
    private static $instance = null;
    private $db;

    private function __construct() {
        $this->db = Database::getInstance();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create a new conversation
     *
     * @param int $userId User ID
     * @param int $canvasId Canvas Template ID
     * @return int Conversation ID
     * @throws Exception If creation fails
     */
    public function createConversation(int $userId, int $canvasId): int {
        try {
            $conversationId = $this->db->insert('conversations', [
                'user_id' => $userId,
                'canvas_id' => $canvasId,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return (int)$conversationId;

        } catch (Exception $e) {
            error_log('ConversationService::createConversation error: ' . $e->getMessage());
            throw new Exception('Erro ao criar conversa');
        }
    }

    /**
     * Add a message to a conversation
     *
     * @param int $conversationId Conversation ID
     * @param string $role 'user' or 'assistant'
     * @param string $content Message content
     * @param string|null $messageType Optional message type (question, answer, form_submission, context)
     * @return int Message ID
     * @throws Exception If adding message fails
     */
    public function addMessage(
        int $conversationId,
        string $role,
        string $content,
        ?string $messageType = null
    ): int {
        try {
            // Validate role
            if (!in_array($role, ['user', 'assistant'])) {
                throw new Exception('Invalid role. Must be "user" or "assistant"');
            }

            // Validate message type if provided
            $validTypes = ['question', 'answer', 'form_submission', 'context'];
            if ($messageType !== null && !in_array($messageType, $validTypes)) {
                throw new Exception('Invalid message type');
            }

            // Bug #9 Fix: Validate content length (TEXT field limit ~65KB)
            if (strlen($content) > 65000) {
                $content = substr($content, 0, 65000);
                $content .= "\n\n[NOTA: Conteúdo truncado devido ao tamanho]";
                error_log("Message content truncated for conversation {$conversationId}");
            }

            // Insert message
            $messageId = $this->db->insert('conversation_messages', [
                'conversation_id' => $conversationId,
                'role' => $role,
                'content' => $content,
                'message_type' => $messageType,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Update conversation updated_at
            $this->db->update(
                'conversations',
                ['updated_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $conversationId]
            );

            // Check if this message contains [RESPOSTA-FINAL] marker
            if ($role === 'assistant' && strpos($content, '[RESPOSTA-FINAL]') !== false) {
                $this->completeConversation($conversationId);
            }

            return (int)$messageId;

        } catch (Exception $e) {
            error_log('ConversationService::addMessage error: ' . $e->getMessage());
            throw new Exception('Erro ao adicionar mensagem');
        }
    }

    /**
     * Get complete conversation with messages and attached files
     *
     * @param int $conversationId Conversation ID
     * @param int $userId User ID (security check - user must own conversation)
     * @return array|null Conversation data or null if not found/not owned
     */
    public function getConversation(int $conversationId, int $userId): ?array {
        try {
            // Get conversation (with ownership check)
            $conversation = $this->db->fetchOne(
                "SELECT * FROM conversations WHERE id = :id AND user_id = :user_id",
                [
                    'id' => $conversationId,
                    'user_id' => $userId
                ]
            );

            if (!$conversation) {
                return null;
            }

            // Get all messages
            $messages = $this->db->fetchAll(
                "SELECT * FROM conversation_messages
                 WHERE conversation_id = :conversation_id
                 ORDER BY created_at ASC",
                ['conversation_id' => $conversationId]
            );

            // Get attached files
            $files = $this->db->fetchAll(
                "SELECT uf.*
                 FROM user_files uf
                 INNER JOIN conversation_files cf ON uf.id = cf.file_id
                 WHERE cf.conversation_id = :conversation_id
                 ORDER BY uf.created_at ASC",
                ['conversation_id' => $conversationId]
            );

            return [
                'conversation' => $conversation,
                'messages' => $messages,
                'files' => $files
            ];

        } catch (Exception $e) {
            error_log('ConversationService::getConversation error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all conversations for a user
     *
     * @param int $userId User ID
     * @param string|null $status Filter by status (optional)
     * @return array Array of conversations
     */
    public function getUserConversations(int $userId, ?string $status = null): array {
        try {
            $sql = "SELECT c.*, ct.name as canvas_name, ct.slug as canvas_slug
                    FROM conversations c
                    INNER JOIN canvas_templates ct ON c.canvas_id = ct.id
                    WHERE c.user_id = :user_id";

            $params = ['user_id' => $userId];

            if ($status !== null) {
                $sql .= " AND c.status = :status";
                $params['status'] = $status;
            }

            $sql .= " ORDER BY c.updated_at DESC";

            return $this->db->fetchAll($sql, $params);

        } catch (Exception $e) {
            error_log('ConversationService::getUserConversations CRITICAL error: ' . $e->getMessage());
            // Re-throw para forçar tratamento explícito no código chamador
            throw new Exception(
                'Failed to fetch user conversations: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Attach files to a conversation
     *
     * @param int $conversationId Conversation ID
     * @param int $userId User ID (ownership check)
     * @param array $fileIds Array of file IDs
     * @return bool True if attached successfully
     */
    public function attachFiles(int $conversationId, int $userId, array $fileIds): bool {
        try {
            $attachedCount = 0;

            foreach ($fileIds as $fileId) {
                // Bug #4 Fix: Verify file ownership
                $file = $this->db->fetchOne(
                    "SELECT id FROM user_files WHERE id = :file_id AND user_id = :user_id",
                    [
                        'file_id' => $fileId,
                        'user_id' => $userId
                    ]
                );

                if (!$file) {
                    error_log("User {$userId} tried to attach file {$fileId} they don't own");
                    continue; // Skip files that don't belong to user
                }

                // Use try-catch to handle duplicate key gracefully
                try {
                    $this->db->insert('conversation_files', [
                        'conversation_id' => $conversationId,
                        'file_id' => $fileId
                    ]);
                    $attachedCount++;
                } catch (Exception $e) {
                    // Duplicate key - file already attached, continue
                    if (strpos($e->getMessage(), 'Duplicate') === false) {
                        throw $e; // Other error, propagate
                    }
                }
            }

            return $attachedCount > 0 || count($fileIds) === 0;

        } catch (Exception $e) {
            error_log('ConversationService::attachFiles error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Complete a conversation (change status to 'completed')
     *
     * @param int $conversationId Conversation ID
     * @param int|null $userId User ID for ownership check (null for internal calls)
     * @return bool True if completed successfully
     */
    public function completeConversation(int $conversationId, ?int $userId = null): bool {
        try {
            // Bug #5 Fix: If userId provided, verify ownership
            if ($userId !== null) {
                $conversation = $this->db->fetchOne(
                    "SELECT id FROM conversations WHERE id = :id AND user_id = :user_id",
                    [
                        'id' => $conversationId,
                        'user_id' => $userId
                    ]
                );

                if (!$conversation) {
                    return false; // Conversation not found or user doesn't own it
                }
            }

            $updated = $this->db->update(
                'conversations',
                [
                    'status' => 'completed',
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $conversationId]
            );

            return $updated > 0;

        } catch (Exception $e) {
            error_log('ConversationService::completeConversation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Archive a conversation (change status to 'archived')
     *
     * @param int $conversationId Conversation ID
     * @param int $userId User ID (security check)
     * @return bool True if archived successfully
     */
    public function archiveConversation(int $conversationId, int $userId): bool {
        try {
            // Verify ownership
            $conversation = $this->db->fetchOne(
                "SELECT id FROM conversations WHERE id = :id AND user_id = :user_id",
                [
                    'id' => $conversationId,
                    'user_id' => $userId
                ]
            );

            if (!$conversation) {
                return false;
            }

            $updated = $this->db->update(
                'conversations',
                [
                    'status' => 'archived',
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $conversationId]
            );

            return $updated > 0;

        } catch (Exception $e) {
            error_log('ConversationService::archiveConversation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check chat rate limit for a user
     *
     * Limit: 100 messages per hour
     *
     * @param int $userId User ID
     * @return array ['allowed' => bool, 'retry_after' => int|null]
     */
    public function checkChatRateLimit(int $userId): array {
        try {
            // Count user messages in the last hour
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) as count
                 FROM conversation_messages cm
                 INNER JOIN conversations c ON cm.conversation_id = c.id
                 WHERE c.user_id = ?
                   AND cm.role = "user"
                   AND cm.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $stmt->execute([$userId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            $count = (int) $result['count'];
            $limit = 100; // 100 messages per hour

            if ($count >= $limit) {
                return [
                    'allowed' => false,
                    'retry_after' => 3600, // 1 hour in seconds
                    'current_count' => $count,
                    'limit' => $limit
                ];
            }

            return [
                'allowed' => true,
                'current_count' => $count,
                'limit' => $limit
            ];

        } catch (Exception $e) {
            error_log('ConversationService::checkChatRateLimit error: ' . $e->getMessage());
            // On error, allow the request (fail open)
            return ['allowed' => true];
        }
    }

    /**
     * Generate automatic title for conversation based on content
     *
     * @param int $conversationId Conversation ID
     * @return string Generated title
     */
    public function generateTitle(int $conversationId): string {
        try {
            // Get first user message (form submission)
            $firstMessage = $this->db->fetchOne(
                "SELECT content FROM conversation_messages
                 WHERE conversation_id = :conversation_id
                 AND role = 'user'
                 ORDER BY created_at ASC
                 LIMIT 1",
                ['conversation_id' => $conversationId]
            );

            if (!$firstMessage) {
                return 'Nova Conversa';
            }

            // Extract a meaningful title (first 50 chars of content)
            $content = $firstMessage['content'];

            // Try to extract something meaningful from JSON form data if present
            if (strpos($content, '{') === 0) {
                $data = json_decode($content, true);
                if ($data && isset($data['descricao_caso'])) {
                    $content = $data['descricao_caso'];
                } elseif ($data && isset($data['descricao'])) {
                    $content = $data['descricao'];
                } elseif ($data) {
                    // Get first non-empty value
                    foreach ($data as $value) {
                        if (is_string($value) && strlen($value) > 10) {
                            $content = $value;
                            break;
                        }
                    }
                }
            }

            // Clean and truncate
            $title = strip_tags($content);
            $title = preg_replace('/\s+/', ' ', $title);
            $title = trim($title);

            // Ensure title is not empty or too short
            if (empty($title) || strlen($title) < 3) {
                $title = 'Conversa ' . date('d/m/Y H:i');
            }

            if (strlen($title) > 50) {
                $title = substr($title, 0, 47) . '...';
            }

            // Update conversation with generated title
            $this->db->update(
                'conversations',
                ['title' => $title],
                'id = :id',
                ['id' => $conversationId]
            );

            return $title;

        } catch (Exception $e) {
            error_log('ConversationService::generateTitle error: ' . $e->getMessage());
            return 'Nova Conversa';
        }
    }
}
