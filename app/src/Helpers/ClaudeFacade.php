<?php
/**
 * Claude API Facade - Wrapper com parâmetros automáticos por vertical
 *
 * Injeta automaticamente parâmetros de VerticalConfig nas chamadas Claude API.
 * Simplifica uso: não precisa mais passar model/temperature/system manualmente.
 *
 * @package Sunyata\Helpers
 * @since 2025-12-17 (Canvas MVP - Sprint 1)
 */

namespace Sunyata\Helpers;

use Sunyata\AI\ClaudeService;
use Sunyata\Core\Settings;
use Sunyata\Services\AiServiceClient;

class ClaudeFacade
{
    /**
     * Retorna defaults de API do portal (Nível 0).
     * Lê de settings.portal_api_params (JSON).
     * Hardcoded fallback se portal não definir.
     *
     * @return array ['claude_model' => ..., 'temperature' => ..., 'max_tokens' => ...]
     */
    private static function getPortalDefaults(): array
    {
        $hardcoded = [
            'claude_model' => 'claude-haiku-4-5-20251001',
            'temperature' => 1.0,
            'max_tokens' => 4096
        ];

        $portalParams = Settings::getInstance()->get('portal_api_params', []);
        if (!is_array($portalParams)) {
            $portalParams = [];
        }

        return array_merge($hardcoded, $portalParams);
    }

    /**
     * Traduz chaves de config (verticals.php / canvas override) para chaves do ClaudeService.
     * Config usa: claude_model
     * ClaudeService espera: model
     *
     * NOTA: system_prompt NÃO é traduzido aqui. System prompts são geridos
     * exclusivamente pela hierarquia de 4 níveis em CanvasHelper::getCompleteSystemPrompt().
     * Qualquer system_prompt vindo de overrides é silenciosamente removido com log warning.
     */
    private static function translateConfigKeys(array $overrides): array
    {
        // Bloquear system_prompt/system em overrides — hierarquia é responsabilidade do CanvasHelper
        if (isset($overrides['system_prompt']) || isset($overrides['system'])) {
            error_log("ClaudeFacade::translateConfigKeys() WARNING: system_prompt/system detected in overrides and removed. System prompts must use the 4-level hierarchy (CanvasHelper).");
            unset($overrides['system_prompt'], $overrides['system']);
        }

        $keyMap = [
            'claude_model' => 'model',
        ];

        $translated = [];
        foreach ($overrides as $key => $value) {
            $translated[$keyMap[$key] ?? $key] = $value;
        }
        return $translated;
    }

    /**
     * Aplica overrides traduzidos sobre options, respeitando exclusividade temperature/top_p.
     *
     * API Claude 4.x: temperature e top_p NÃO podem coexistir.
     * Se override define top_p, remove temperature herdado (e vice-versa).
     *
     * @param array $options Options base (portal + vertical)
     * @param array $overrideOptions Overrides crus (chaves config-style)
     * @return array Options final com overrides aplicados
     */
    private static function applyOverrides(array $options, array $overrideOptions): array
    {
        $translated = self::translateConfigKeys($overrideOptions);
        $options = array_merge($options, $translated);

        // temperature e top_p são mutuamente exclusivos na API Claude 4.x
        // Se override define explicitamente um, remover o outro
        if (array_key_exists('top_p', $translated)) {
            unset($options['temperature']);
        } elseif (array_key_exists('temperature', $translated)) {
            unset($options['top_p']);
        }

        return $options;
    }

    /**
     * Gera resposta via Claude API com parâmetros automáticos da vertical
     *
     * @param string $verticalSlug Slug da vertical (ex: 'iatr', 'juridico')
     * @param string $prompt Prompt do usuário
     * @param int $userId ID do usuário
     * @param string $toolName Nome da ferramenta
     * @param array $inputData Dados do formulário
     * @param array $overrideOptions Opções que sobrescrevem vertical config (opcional)
     * @return array Resposta do ClaudeService
     *
     * @example
     * $result = ClaudeFacade::generate(
     *     verticalSlug: 'iatr',
     *     prompt: $userPrompt,
     *     userId: $_SESSION['user']['id'],
     *     toolName: 'canvas_juridico',
     *     inputData: $formData
     * );
     */
    public static function generate(
        string $verticalSlug,
        string $prompt,
        int $userId,
        string $toolName,
        array $inputData = [],
        array $overrideOptions = []
    ): array {
        // 1. Portal defaults (Nível 0) + Vertical config (Nível 1+2)
        $portalDefaults = self::getPortalDefaults();
        $verticalConfig = VerticalConfig::get($verticalSlug);

        // 2. Preparar options: portal → vertical → overrides
        $options = [
            'model' => $verticalConfig['claude_model'] ?? $portalDefaults['claude_model'],
            'temperature' => $verticalConfig['temperature'] ?? $portalDefaults['temperature'],
            'max_tokens' => $verticalConfig['max_tokens'] ?? $portalDefaults['max_tokens'],
            'system' => $verticalConfig['system_prompt'] ?? null
        ];

        // 3. Aplicar overrides (traduz chaves + exclusividade temperature/top_p)
        $options = self::applyOverrides($options, $overrideOptions);

        // 4. Chamar ClaudeService com options preparadas
        $claudeService = new ClaudeService();

        return $claudeService->generate(
            prompt: $prompt,
            userId: $userId,
            vertical: $verticalSlug,
            toolName: $toolName,
            inputData: $inputData,
            options: $options
        );
    }

    /**
     * Gera resposta com contexto (múltiplas mensagens) com parâmetros automáticos
     *
     * @param string $verticalSlug Slug da vertical
     * @param array $messages Array de mensagens Claude API
     * @param array $overrideOptions Opções customizadas (opcional)
     * @return array Resposta do ClaudeService
     *
     * @example
     * $result = ClaudeFacade::generateWithContext(
     *     verticalSlug: 'juridico',
     *     messages: [
     *         ['role' => 'user', 'content' => 'Primeira pergunta'],
     *         ['role' => 'assistant', 'content' => 'Resposta Claude'],
     *         ['role' => 'user', 'content' => 'Pergunta seguinte']
     *     ]
     * );
     */
    public static function generateWithContext(
        string $verticalSlug,
        array $messages,
        array $overrideOptions = []
    ): array {
        // 1. Portal defaults (Nível 0) + Vertical config (Nível 1+2)
        $portalDefaults = self::getPortalDefaults();
        $verticalConfig = VerticalConfig::get($verticalSlug);

        // 2. Preparar options: portal → vertical → overrides
        $systemPrompt = $verticalConfig['system_prompt'] ?? '';
        $maxTokens = $verticalConfig['max_tokens'] ?? $portalDefaults['max_tokens'];

        $options = [
            'model' => $verticalConfig['claude_model'] ?? $portalDefaults['claude_model'],
            'temperature' => $verticalConfig['temperature'] ?? $portalDefaults['temperature']
        ];

        // 3. Aplicar overrides (traduz chaves + exclusividade temperature/top_p)
        $options = self::applyOverrides($options, $overrideOptions);

        // Extrair max_tokens e system de options (podem ter vindo de override)
        if (isset($options['max_tokens'])) {
            $maxTokens = $options['max_tokens'];
        }
        if (isset($options['system'])) {
            $systemPrompt = $options['system'];
        }

        // 4. Chamar ClaudeService
        $claudeService = new ClaudeService();

        return $claudeService->generateWithContext(
            systemPrompt: $systemPrompt,
            messages: $messages,
            maxTokens: $maxTokens,
            options: $options
        );
    }

    /**
     * Gera resposta para Canvas com hierarquia completa de system prompts (4 níveis)
     *
     * HIERARQUIA AUTOMÁTICA:
     * 0. Portal (settings.portal_system_prompt)
     * 1. Vertical (config/verticals.php + verticals.config)
     * 2. Canvas Template (canvas_templates.system_prompt)
     * 3. Form Config JSON (ajSystemPrompt)
     *
     * @param string $verticalSlug Slug da vertical (ex: 'iatr', 'juridico')
     * @param int $canvasTemplateId ID do canvas_template
     * @param string $prompt Prompt do usuário
     * @param int $userId ID do usuário
     * @param string $toolName Nome da ferramenta
     * @param array $inputData Dados do formulário
     * @param array $overrideOptions Opções que sobrescrevem (opcional)
     * @return array Resposta do ClaudeService
     *
     * @example
     * // Uso em formulários (ex: formulario.php)
     * $result = ClaudeFacade::generateForCanvas(
     *     verticalSlug: 'juridico',
     *     canvasTemplateId: 15,  // ← ID do canvas_template
     *     prompt: $finalPrompt,
     *     userId: $_SESSION['user']['id'],
     *     toolName: 'analise_contratos',
     *     inputData: $formData
     * );
     * // System prompt será: Vertical + Canvas + ajSystemPrompt (automático!)
     */
    public static function generateForCanvas(
        string $verticalSlug,
        int $canvasTemplateId,
        string $prompt,
        int $userId,
        string $toolName,
        array $inputData = [],
        array $overrideOptions = []
    ): array {
        // 1. Portal defaults (Nível 0) + Vertical config (Nível 1+2)
        $portalDefaults = self::getPortalDefaults();
        $verticalConfig = VerticalConfig::get($verticalSlug);

        // 2. Buscar system prompt completo com HIERARQUIA DE 4 NÍVEIS
        $systemPrompt = CanvasHelper::getCompleteSystemPrompt($verticalSlug, $canvasTemplateId);

        // 3. Preparar options: portal → vertical → overrides
        $options = [
            'model' => $verticalConfig['claude_model'] ?? $portalDefaults['claude_model'],
            'temperature' => $verticalConfig['temperature'] ?? $portalDefaults['temperature'],
            'max_tokens' => $verticalConfig['max_tokens'] ?? $portalDefaults['max_tokens'],
            'system' => $systemPrompt  // ← System prompt completo (4 níveis merged)
        ];

        // 4. Aplicar overrides (traduz chaves + exclusividade temperature/top_p)
        $options = self::applyOverrides($options, $overrideOptions);

        // 5. Chamar ClaudeService
        $claudeService = new ClaudeService();

        return $claudeService->generate(
            prompt: $prompt,
            userId: $userId,
            vertical: $verticalSlug,
            toolName: $toolName,
            inputData: $inputData,
            options: $options
        );
    }

    /**
     * Gera resposta para Canvas com contexto (multi-turn) usando hierarquia completa
     *
     * @param string $verticalSlug Slug da vertical
     * @param int $canvasTemplateId ID do canvas_template
     * @param array $messages Array de mensagens Claude API
     * @param array $overrideOptions Opções customizadas (opcional)
     * @return array Resposta do ClaudeService
     *
     * @example
     * $result = ClaudeFacade::generateForCanvasWithContext(
     *     verticalSlug: 'iatr',
     *     canvasTemplateId: 10,
     *     messages: [
     *         ['role' => 'user', 'content' => 'Primeira pergunta'],
     *         ['role' => 'assistant', 'content' => 'Resposta'],
     *         ['role' => 'user', 'content' => 'Segunda pergunta']
     *     ]
     * );
     */
    public static function generateForCanvasWithContext(
        string $verticalSlug,
        int $canvasTemplateId,
        array $messages,
        array $overrideOptions = []
    ): array {
        // 1. Portal defaults (Nível 0) + Vertical config (Nível 1+2)
        $portalDefaults = self::getPortalDefaults();
        $verticalConfig = VerticalConfig::get($verticalSlug);

        // 2. Buscar system prompt completo (4 níveis)
        $systemPrompt = CanvasHelper::getCompleteSystemPrompt($verticalSlug, $canvasTemplateId);

        // 3. Preparar options: portal → vertical → overrides
        $maxTokens = $verticalConfig['max_tokens'] ?? $portalDefaults['max_tokens'];

        $options = [
            'model' => $verticalConfig['claude_model'] ?? $portalDefaults['claude_model'],
            'temperature' => $verticalConfig['temperature'] ?? $portalDefaults['temperature']
        ];

        // 4. Aplicar overrides (traduz chaves + exclusividade temperature/top_p)
        $options = self::applyOverrides($options, $overrideOptions);

        if (isset($options['max_tokens'])) {
            $maxTokens = $options['max_tokens'];
        }
        if (isset($options['system'])) {
            $systemPrompt = $options['system'];
        }

        // 5. Chamar ClaudeService
        $claudeService = new ClaudeService();

        return $claudeService->generateWithContext(
            systemPrompt: $systemPrompt,
            messages: $messages,
            maxTokens: $maxTokens,
            options: $options
        );
    }

    /**
     * Determina se devemos usar o microservice FastAPI ou chamada direta.
     * Controlado pela setting 'ai_service_mode' = 'direct' | 'microservice'
     */
    private static function usesMicroservice(): bool
    {
        $mode = Settings::getInstance()->get('ai_service_mode', 'direct');
        return $mode === 'microservice';
    }

    /**
     * Gera resposta para Canvas via microservice FastAPI (alternativa ao ClaudeService direto).
     * Retorna resposta no mesmo formato que generateForCanvas() para compatibilidade.
     *
     * @param string $verticalSlug Slug da vertical
     * @param int $canvasTemplateId ID do canvas_template
     * @param string $prompt Prompt do usuário
     * @param int $userId ID do usuário
     * @param string $toolName Nome da ferramenta
     * @param array $inputData Dados do formulário
     * @param array $overrideOptions Opções customizadas
     * @return array Resposta compatível com ClaudeService::generate()
     */
    public static function generateViaService(
        string $verticalSlug,
        int $canvasTemplateId,
        string $prompt,
        int $userId,
        string $toolName,
        array $inputData = [],
        array $overrideOptions = []
    ): array {
        // 1. Resolve full options via hierarchy (same as generateForCanvas)
        $portalDefaults = self::getPortalDefaults();
        $verticalConfig = VerticalConfig::get($verticalSlug);
        $systemPrompt = CanvasHelper::getCompleteSystemPrompt($verticalSlug, $canvasTemplateId);

        $options = [
            'model' => $verticalConfig['claude_model'] ?? $portalDefaults['claude_model'],
            'temperature' => $verticalConfig['temperature'] ?? $portalDefaults['temperature'],
            'max_tokens' => $verticalConfig['max_tokens'] ?? $portalDefaults['max_tokens'],
            'system' => $systemPrompt,
        ];
        $options = self::applyOverrides($options, $overrideOptions);

        // 2. Create history record via ClaudeService (audit trail)
        $claudeService = new ClaudeService();
        $historyId = $claudeService->createPendingHistory(
            $userId, $verticalSlug, $toolName, $inputData, $prompt
        );

        // 3. Call microservice
        $client = new AiServiceClient();
        $result = $client->generate([
            'model' => $options['model'],
            'system' => $options['system'] ?? null,
            'prompt' => $prompt,
            'max_tokens' => $options['max_tokens'],
            'temperature' => $options['temperature'] ?? null,
            'top_p' => $options['top_p'] ?? null,
            'user_id' => $userId,
            'vertical' => $verticalSlug,
            'tool_name' => $toolName,
        ]);

        // 4. Update history with result
        if ($result['success']) {
            $claudeService->updateHistory($historyId, [
                'claude_response' => $result['response'],
                'claude_model' => $result['model'],
                'tokens_input' => $result['tokens']['input'] ?? 0,
                'tokens_output' => $result['tokens']['output'] ?? 0,
                'tokens_total' => ($result['tokens']['input'] ?? 0) + ($result['tokens']['output'] ?? 0),
                'cost_usd' => $result['cost_usd'],
                'response_time_ms' => $result['response_time_ms'],
                'status' => 'success',
            ]);
        } else {
            $claudeService->updateHistory($historyId, [
                'status' => 'error',
                'error_message' => $result['error'] ?? 'Unknown microservice error',
            ]);
        }

        $result['history_id'] = $historyId;
        return $result;
    }

    /**
     * Build SSE stream parameters for the browser.
     * PHP validates the request, builds the full options, and returns
     * the stream URL + params for the frontend to connect directly to FastAPI.
     *
     * @return array ['stream_url' => string, 'params' => array, 'internal_key' => string]
     */
    public static function buildStreamParams(
        string $verticalSlug,
        int $canvasTemplateId,
        string $prompt,
        array $overrideOptions = []
    ): array {
        $portalDefaults = self::getPortalDefaults();
        $verticalConfig = VerticalConfig::get($verticalSlug);
        $systemPrompt = CanvasHelper::getCompleteSystemPrompt($verticalSlug, $canvasTemplateId);

        $options = [
            'model' => $verticalConfig['claude_model'] ?? $portalDefaults['claude_model'],
            'temperature' => $verticalConfig['temperature'] ?? $portalDefaults['temperature'],
            'max_tokens' => $verticalConfig['max_tokens'] ?? $portalDefaults['max_tokens'],
            'system' => $systemPrompt,
        ];
        $options = self::applyOverrides($options, $overrideOptions);

        $client = new AiServiceClient();

        return [
            'stream_url' => $client->getStreamUrl([]),
            'params' => [
                'model' => $options['model'],
                'system' => $options['system'] ?? null,
                'prompt' => $prompt,
                'max_tokens' => $options['max_tokens'],
                'temperature' => $options['temperature'] ?? null,
                'top_p' => $options['top_p'] ?? null,
            ],
            'internal_key' => $client->getInternalKey(),
        ];
    }

    /**
     * Helper: Retorna apenas parâmetros efetivos de uma vertical (para debug/preview)
     *
     * @param string $verticalSlug
     * @return array
     */
    public static function getEffectiveParams(string $verticalSlug): array
    {
        return VerticalConfig::get($verticalSlug);
    }

    /**
     * Debug: Retorna breakdown completo da hierarquia de system prompts
     *
     * @param string $verticalSlug
     * @param int $canvasTemplateId
     * @return array Breakdown dos 4 níveis
     */
    public static function debugSystemPromptHierarchy(
        string $verticalSlug,
        int $canvasTemplateId
    ): array {
        return CanvasHelper::debugSystemPromptHierarchy($verticalSlug, $canvasTemplateId);
    }
}
