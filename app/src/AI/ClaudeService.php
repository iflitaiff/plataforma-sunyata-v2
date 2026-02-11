<?php
/**
 * Claude API Service - Integração com Anthropic Claude API
 *
 * Gerencia chamadas à API Claude para geração de conteúdo.
 * Salva histórico completo de forma transparente ao usuário.
 *
 * @package Sunyata\AI
 * @author Claude Code
 * @version 1.0.0
 */

namespace Sunyata\AI;

use Sunyata\Core\Database;
use Sunyata\Core\MarkdownLogger;
use Exception;

class ClaudeService {
    private $db;
    private $apiKey;
    private $apiUrl = 'https://api.anthropic.com/v1/messages';
    private $defaultModel = 'claude-haiku-4-5-20251001'; // Haiku 4.5 (mais econômico)
    private $defaultMaxTokens = 4096;

    public function __construct() {
        $this->db = Database::getInstance();

        // API Key de secrets.php
        if (!defined('CLAUDE_API_KEY')) {
            throw new Exception('CLAUDE_API_KEY não definida em secrets.php');
        }
        $this->apiKey = CLAUDE_API_KEY;
    }

    /**
     * Gera resposta via Claude API
     *
     * @param string $prompt Prompt a ser enviado
     * @param int $userId ID do usuário
     * @param string $vertical Vertical (juridico, docencia, etc)
     * @param string $toolName Nome da ferramenta (canvas_juridico, etc)
     * @param array $inputData Dados do formulário preenchido
     * @param array $options Opções customizadas (model, max_tokens, temperature)
     * @return array ['success' => bool, 'response' => string, 'history_id' => int]
     */
    public function generate(
        string $prompt,
        int $userId,
        string $vertical,
        string $toolName,
        array $inputData = [],
        array $options = []
    ): array {
        $startTime = microtime(true);

        // Criar registro de histórico (status: pending)
        $historyId = $this->createHistoryRecord(
            $userId,
            $vertical,
            $toolName,
            $inputData,
            $prompt
        );

        try {
            // Preparar payload
            $model = $options['model'] ?? $this->defaultModel;
            $maxTokens = $options['max_tokens'] ?? $this->defaultMaxTokens;
            $temperature = array_key_exists('temperature', $options) ? $options['temperature'] : null;
            $topP = array_key_exists('top_p', $options) ? $options['top_p'] : null;
            $systemPrompt = $options['system'] ?? null;

            $payload = [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ]
            ];

            // API Claude 4.x: temperature e top_p não podem coexistir
            // Prioridade: temperature > top_p. Se nenhum, default temperature=1.0
            if ($temperature !== null) {
                $payload['temperature'] = $temperature;
            } elseif ($topP !== null) {
                $payload['top_p'] = $topP;
            } else {
                $payload['temperature'] = 1.0;
            }

            if ($systemPrompt) {
                $payload['system'] = $systemPrompt;
            }

            // Fazer chamada HTTP via cURL
            $response = $this->callClaudeApi($payload);

            // Calcular tempo de resposta
            $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);

            // Extrair resposta
            $claudeResponse = $response['content'][0]['text'] ?? '';
            $tokensInput = $response['usage']['input_tokens'] ?? 0;
            $tokensOutput = $response['usage']['output_tokens'] ?? 0;
            $tokensTotal = $tokensInput + $tokensOutput;

            // Calcular custo baseado no modelo real
            $costUsd = $this->isMockModeActive()
                ? 0.00
                : $this->calculateCost($model, $tokensInput, $tokensOutput);

            // Atualizar histórico com sucesso
            $this->updateHistoryRecord($historyId, [
                'claude_response' => $claudeResponse,
                'claude_model' => $this->isMockModeActive() ? 'claude-mock-v1' : $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'top_p' => $topP,
                'system_prompt_sent' => $systemPrompt,
                'tokens_input' => $tokensInput,
                'tokens_output' => $tokensOutput,
                'tokens_total' => $tokensTotal,
                'cost_usd' => $costUsd,
                'response_time_ms' => $responseTimeMs,
                'status' => 'success'
            ]);

            // Log Claude API call
            MarkdownLogger::getInstance()->claudeApiCall(
                userId: $userId,
                canvas: $vertical,
                inputTokens: $tokensInput,
                outputTokens: $tokensOutput,
                costUsd: $costUsd,
                responseTime: $responseTimeMs / 1000, // converter ms para segundos
                status: 'success'
            );

            return [
                'success' => true,
                'response' => $claudeResponse,
                'history_id' => $historyId,
                'tokens' => [
                    'input' => $tokensInput,
                    'output' => $tokensOutput,
                    'total' => $tokensTotal
                ],
                'cost_usd' => $costUsd,
                'response_time_ms' => $responseTimeMs
            ];

        } catch (Exception $e) {
            // Registrar erro (custom debug log)
            $debugLog = __DIR__ . '/../../logs/canvas-debug.log';
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] ClaudeService::generate() EXCEPTION\n";
            $logEntry .= "Message: " . $e->getMessage() . "\n";
            $logEntry .= "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
            $logEntry .= "Stack Trace:\n" . $e->getTraceAsString() . "\n";
            $logEntry .= "---\n";
            file_put_contents($debugLog, $logEntry, FILE_APPEND);

            error_log('ClaudeService::generate() failed: ' . $e->getMessage());

            $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);

            // Atualizar histórico com erro
            $this->updateHistoryRecord($historyId, [
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'response_time_ms' => $responseTimeMs
            ]);

            // Log Claude API call failure
            MarkdownLogger::getInstance()->claudeApiCall(
                userId: $userId,
                canvas: $vertical,
                inputTokens: 0,
                outputTokens: 0,
                costUsd: 0.0,
                responseTime: $responseTimeMs / 1000,
                status: 'error',
                extraContext: ['error' => $e->getMessage()]
            );

            return [
                'success' => false,
                'error' => 'Erro ao gerar conteúdo. Por favor, tente novamente.',
                'error_detail' => $e->getMessage(),
                'history_id' => $historyId
            ];
        }
    }

    /**
     * Gera resposta mock (Lorem ipsum jurídico) para testes sem gastar créditos API
     *
     * @param array $payload Payload que seria enviado para API (usado para calcular tokens simulados)
     * @return array Resposta simulada no formato da API Claude
     */
    private function generateMockResponse(array $payload): array {
        // Variações de Lorem ipsum com tom jurídico
        $mockResponses = [
            "**ANÁLISE JURÍDICA PRELIMINAR**\n\nLorem ipsum dolor sit amet, consectetur adipiscing elit. Com base na análise do documento fornecido e nas informações do canvas, apresento a seguinte fundamentação:\n\n**1. Fundamentação Legal**\nSed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.\n\n**2. Teses Aplicáveis**\nDuis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur:\n- Excepteur sint occaecat cupidatat non proident\n- Sunt in culpa qui officia deserunt mollit anim\n- Id est laborum et dolorum fuga\n\n**3. Precedentes Relevantes**\nEt harum quidem rerum facilis est et expedita distinctio. Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit.\n\n**4. Considerações Finais**\nTemporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae.",

            "**PARECER TÉCNICO-JURÍDICO**\n\nAt vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti.\n\n**I. RELATÓRIO**\nQuos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga.\n\n**II. ANÁLISE DO MÉRITO**\nEt harum quidem rerum facilis est et expedita distinctio:\n1. Nam libero tempore, cum soluta nobis\n2. Eligendi optio cumque nihil impedit\n3. Quo minus id quod maxime placeat\n\n**III. FUNDAMENTAÇÃO**\nFacere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus.\n\n**IV. CONCLUSÃO**\nItaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat.",

            "**ESTRUTURA DE PEÇA PROCESSUAL**\n\nExcelentíssimo Senhor Doutor Juiz de Direito da [lorem ipsum] Vara [dolor sit amet] da Comarca de [consectetur adipiscing].\n\n**DOS FATOS**\nSed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis.\n\n**DO DIREITO**\nNemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit:\n- Sed quia consequuntur magni dolores\n- Eos qui ratione voluptatem sequi nesciunt\n- Neque porro quisquam est qui dolorem\n\n**DOS PEDIDOS**\nQuis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur.\n\nNestes termos, pede deferimento.\n\n[Local], [data].\n[Advogado(a)]",

            "**RESPOSTA AO CANVAS JURÍDICO**\n\nConsiderando os critérios estabelecidos e a documentação anexada, apresento a seguinte análise estruturada:\n\n**CONTEXTUALIZAÇÃO**\nUt enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur.\n\n**DESENVOLVIMENTO**\nQuis autem vel eum iure reprehenderit:\n• Qui in ea voluptate velit esse\n• Quam nihil molestiae consequatur\n• Vel illum qui dolorem eum fugiat\n\n**ARGUMENTAÇÃO CENTRAL**\nAt vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores.\n\n**FUNDAMENTAÇÃO NORMATIVA**\nEt quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi.\n\n**CONCLUSÃO PROPOSTA**\nId est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio.",

            "**MINUTA JURÍDICA**\n\nSed do eiusmod tempor incididunt ut labore et dolore magna aliqua.\n\n**IDENTIFICAÇÃO**\nLorem ipsum dolor sit amet, consectetur adipiscing elit, conforme documentação em anexo.\n\n**QUALIFICAÇÃO JURÍDICA**\nUt enim ad minim veniam, quis nostrud exercitation:\na) Ullamco laboris nisi ut aliquip\nb) Ex ea commodo consequat\nc) Duis aute irure dolor\n\n**DISPOSITIVOS APLICÁVEIS**\nIn reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident.\n\n**ORIENTAÇÃO**\nSunt in culpa qui officia deserunt mollit anim id est laborum. Sed ut perspiciatis unde omnis iste natus error sit voluptatem.\n\n**OBSERVAÇÕES COMPLEMENTARES**\nAccusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo."
        ];

        // Selecionar resposta aleatória
        $selectedResponse = $mockResponses[array_rand($mockResponses)];

        // Simular tokens baseado no tamanho (aproximado)
        $promptText = $payload['messages'][0]['content'] ?? '';
        $systemText = $payload['system'] ?? '';
        $inputTokens = (int)((strlen($promptText) + strlen($systemText)) / 4); // ~4 chars por token
        $outputTokens = (int)(strlen($selectedResponse) / 4);

        // Simular tempo de resposta (500-1500ms)
        usleep(rand(500000, 1500000)); // microseconds

        return [
            'content' => [
                ['text' => $selectedResponse]
            ],
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens
            ],
            'stop_reason' => 'end_turn',
            'model' => 'claude-mock-v1'
        ];
    }

    /**
     * Faz chamada HTTP para Claude API
     *
     * @param array $payload Dados a enviar
     * @return array Resposta da API
     * @throws Exception
     */
    /**
     * Verifica se Mock Mode está ativo (.env ou sessão)
     */
    private function isMockModeActive(): bool {
        // Verificar sessão primeiro (prioridade)
        if (isset($_SESSION['canvas_mock_mode']) && $_SESSION['canvas_mock_mode']) {
            return true;
        }

        // Fallback para .env
        return (defined('CLAUDE_MOCK_MODE') && CLAUDE_MOCK_MODE);
    }

    /**
     * Calcula custo baseado no modelo utilizado
     *
     * @param string $model Model ID usado na chamada
     * @param int $inputTokens Tokens de input
     * @param int $outputTokens Tokens de output
     * @return float Custo em USD
     */
    private function calculateCost(string $model, int $inputTokens, int $outputTokens): float {
        // Pricing per token (input/output) — atualizado 2026-02
        // Formato: [input_per_token, output_per_token]
        $pricing = [
            'haiku'  => [0.000001, 0.000005],   // Haiku 4.5: $1/$5 per MTok
            'sonnet' => [0.000003, 0.000015],    // Sonnet 3.5/4.5: $3/$15 per MTok
            'opus'   => [0.000015, 0.000075],    // Opus 4/4.6: $15/$75 per MTok
        ];

        // Detectar família do modelo pelo nome
        $modelLower = strtolower($model);
        if (str_contains($modelLower, 'haiku')) {
            $rates = $pricing['haiku'];
        } elseif (str_contains($modelLower, 'opus')) {
            $rates = $pricing['opus'];
        } else {
            // Default: Sonnet (mais comum, fallback seguro)
            $rates = $pricing['sonnet'];
        }

        return ($inputTokens * $rates[0]) + ($outputTokens * $rates[1]);
    }

    private function callClaudeApi(array $payload): array {
        // MODO MOCK: Retorna resposta simulada sem chamar API
        if ($this->isMockModeActive()) {
            return $this->generateMockResponse($payload);
        }
        $ch = curl_init($this->apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 300, // 5 minutos (dentro do maxExecutionTime de 360s)
            CURLOPT_CONNECTTIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlError) {
            // Detectar timeout especificamente para mensagem amigável
            if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
                throw new Exception("TIMEOUT: A API Claude demorou mais que o esperado para responder. Tente novamente — textos muito longos podem precisar de mais tempo.");
            }
            throw new Exception("cURL error: {$curlError}");
        }

        if (!$response) {
            throw new Exception('Empty response from Claude API');
        }

        $data = json_decode($response, true);

        if (!$data) {
            throw new Exception('Invalid JSON response from Claude API');
        }

        // Verificar erros da API
        if ($httpCode !== 200) {
            $errorMsg = $data['error']['message'] ?? 'Unknown API error';
            throw new Exception("Claude API error (HTTP {$httpCode}): {$errorMsg}");
        }

        return $data;
    }

    /**
     * Cria registro inicial no histórico
     *
     * @param int $userId
     * @param string $vertical
     * @param string $toolName
     * @param array $inputData
     * @param string $generatedPrompt
     * @return int ID do registro criado
     */
    private function createHistoryRecord(
        int $userId,
        string $vertical,
        string $toolName,
        array $inputData,
        string $generatedPrompt
    ): int {
        return $this->db->insert('prompt_history', [
            'user_id' => $userId,
            'vertical' => $vertical,
            'tool_name' => $toolName,
            'input_data' => json_encode($inputData, JSON_UNESCAPED_UNICODE),
            'generated_prompt' => $generatedPrompt,
            'status' => 'pending',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    /**
     * Atualiza registro do histórico
     *
     * @param int $historyId
     * @param array $data
     */
    private function updateHistoryRecord(int $historyId, array $data): void {
        // Debug logging para rastrear problema de update
        $debugLog = __DIR__ . '/../../logs/canvas-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($debugLog, "[$timestamp] updateHistoryRecord() CALLED - ID: $historyId\n", FILE_APPEND);
        file_put_contents($debugLog, "[$timestamp] Data keys: " . implode(', ', array_keys($data)) . "\n---\n", FILE_APPEND);

        try {
            $rowsAffected = $this->db->update('prompt_history', $data, 'id = :id', ['id' => $historyId]);
            file_put_contents($debugLog, "[$timestamp] updateHistoryRecord() SUCCESS - Rows affected: $rowsAffected\n---\n", FILE_APPEND);
        } catch (\Exception $e) {
            // Log o erro mas não quebra o fluxo
            file_put_contents($debugLog, "[$timestamp] updateHistoryRecord() EXCEPTION: " . $e->getMessage() . "\n---\n", FILE_APPEND);
            error_log('WARNING: Failed to update history record #' . $historyId . ': ' . $e->getMessage());
            error_log('Data being updated: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Gera resposta via Claude API com contexto de conversa (múltiplas mensagens)
     *
     * @param string $systemPrompt System prompt do Canvas
     * @param array $messages Array de mensagens no formato Claude API: [['role' => 'user'|'assistant', 'content' => 'texto'], ...]
     * @param int $maxTokens Máximo de tokens na resposta (padrão: 4096)
     * @param array $options Opções adicionais (model, temperature)
     * @return array ['success' => bool, 'content' => string, 'message_type' => string, 'finish_reason' => string, 'usage' => [...]]
     */
    public function generateWithContext(
        string $systemPrompt,
        array $messages,
        int $maxTokens = 4096,
        array $options = []
    ): array {
        $startTime = microtime(true);

        try {
            // Preparar payload
            $model = $options['model'] ?? $this->defaultModel;
            $temperature = array_key_exists('temperature', $options) ? $options['temperature'] : null;
            $topP = array_key_exists('top_p', $options) ? $options['top_p'] : null;

            $payload = [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => $messages
            ];

            // API Claude 4.x: temperature e top_p não podem coexistir
            if ($temperature !== null) {
                $payload['temperature'] = $temperature;
            } elseif ($topP !== null) {
                $payload['top_p'] = $topP;
            } else {
                $payload['temperature'] = 1.0;
            }

            // Fazer chamada HTTP via cURL
            $response = $this->callClaudeApi($payload);

            // Calcular tempo de resposta
            $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);

            // Extrair resposta
            $claudeResponse = $response['content'][0]['text'] ?? '';
            $finishReason = $response['stop_reason'] ?? 'unknown';
            $tokensInput = $response['usage']['input_tokens'] ?? 0;
            $tokensOutput = $response['usage']['output_tokens'] ?? 0;
            $tokensTotal = $tokensInput + $tokensOutput;

            // Detectar tipo de mensagem baseado em marcadores
            $messageType = $this->detectMessageType($claudeResponse);

            // Calcular custo baseado no modelo real
            $costUsd = $this->isMockModeActive()
                ? 0.00
                : $this->calculateCost($model, $tokensInput, $tokensOutput);

            return [
                'success' => true,
                'content' => $claudeResponse,
                'message_type' => $messageType,
                'finish_reason' => $finishReason,
                'model' => $this->isMockModeActive() ? 'claude-mock-v1' : $model,
                'usage' => [
                    'input_tokens' => $tokensInput,
                    'output_tokens' => $tokensOutput,
                    'total_tokens' => $tokensTotal
                ],
                'cost_usd' => $costUsd,
                'response_time_ms' => $responseTimeMs
            ];

        } catch (Exception $e) {
            // Registrar erro
            error_log('ClaudeService::generateWithContext() failed: ' . $e->getMessage());

            return [
                'success' => false,
                'content' => '',
                'message_type' => 'error',
                'error' => 'Erro ao gerar conteúdo. Por favor, tente novamente.',
                'error_detail' => $e->getMessage(),
                'response_time_ms' => (int)((microtime(true) - $startTime) * 1000)
            ];
        }
    }

    /**
     * Detecta o tipo de mensagem baseado em marcadores no conteúdo
     *
     * @param string $content Conteúdo da mensagem do Claude
     * @return string Tipo: 'question', 'final_answer', ou 'context'
     */
    private function detectMessageType(string $content): string {
        // Detectar [PERGUNTA-N]
        if (preg_match('/^\[PERGUNTA-\d+\]/', trim($content))) {
            return 'question';
        }

        // Detectar [RESPOSTA-FINAL]
        if (preg_match('/^\[RESPOSTA-FINAL\]/', trim($content))) {
            return 'final_answer';
        }

        // Caso contrário, é contexto/informação adicional
        return 'context';
    }

    /**
     * Public wrapper for createHistoryRecord — used by ClaudeFacade::generateViaService()
     * to create the audit record before calling the microservice.
     */
    public function createPendingHistory(
        int $userId,
        string $vertical,
        string $toolName,
        array $inputData,
        string $generatedPrompt
    ): int {
        return $this->createHistoryRecord($userId, $vertical, $toolName, $inputData, $generatedPrompt);
    }

    /**
     * Public wrapper for updateHistoryRecord — used by ClaudeFacade::generateViaService()
     * to update the audit record after microservice responds.
     */
    public function updateHistory(int $historyId, array $data): void
    {
        $this->updateHistoryRecord($historyId, $data);
    }

    /**
     * Obtém histórico de um usuário
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUserHistory(int $userId, int $limit = 50): array {
        return $this->db->fetchAll(
            "SELECT id, vertical, tool_name, input_data, claude_response,
                    tokens_total, cost_usd, response_time_ms, status, created_at
             FROM prompt_history
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT :limit",
            ['user_id' => $userId, 'limit' => $limit]
        );
    }

    /**
     * Obtém estatísticas de uso (admin)
     *
     * @return array
     */
    public function getUsageStats(): array {
        $stats = [];

        // Total de prompts gerados
        $stats['total_prompts'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM prompt_history WHERE status = 'success'"
        )['count'];

        // Total de tokens usados
        $stats['total_tokens'] = $this->db->fetchOne(
            "SELECT SUM(tokens_total) as total FROM prompt_history WHERE status = 'success'"
        )['total'] ?? 0;

        // Custo total
        $stats['total_cost_usd'] = $this->db->fetchOne(
            "SELECT SUM(cost_usd) as total FROM prompt_history WHERE status = 'success'"
        )['total'] ?? 0;

        // Por vertical
        $stats['by_vertical'] = $this->db->fetchAll(
            "SELECT vertical, COUNT(*) as count, SUM(tokens_total) as tokens, SUM(cost_usd) as cost
             FROM prompt_history
             WHERE status = 'success'
             GROUP BY vertical
             ORDER BY count DESC"
        );

        // Últimos 7 dias
        $stats['last_7_days'] = $this->db->fetchAll(
            "SELECT created_at::date as date, COUNT(*) as count, SUM(tokens_total) as tokens
             FROM prompt_history
             WHERE status = 'success' AND created_at >= NOW() - INTERVAL '7 days'
             GROUP BY created_at::date
             ORDER BY date DESC"
        );

        return $stats;
    }
}
