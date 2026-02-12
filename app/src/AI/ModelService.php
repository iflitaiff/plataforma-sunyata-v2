<?php
/**
 * Model Service - Lista dinâmica de modelos via LiteLLM proxy
 *
 * Busca, cacheia e valida modelos disponíveis usando o endpoint
 * GET /v1/models do LiteLLM (OpenAI-compatible). Suporta múltiplos
 * provedores (Anthropic, OpenAI, Google) de forma transparente.
 * Cache armazenado na tabela settings.
 *
 * @package Sunyata\AI
 * @since 2026-01-27
 */

namespace Sunyata\AI;

use Sunyata\Core\Settings;
use Exception;

class ModelService
{
    private static ?self $instance = null;
    private Settings $settings;
    private string $litellmBaseUrl;
    private string $litellmApiKey;

    private const CACHE_KEY = 'claude_models_cache';
    private const CACHE_TIMESTAMP_KEY = 'claude_models_cache_updated_at';
    private const CACHE_TTL_SECONDS = 86400; // 24 horas

    /**
     * Map known model ID prefixes to provider names for display_name generation.
     */
    private const PROVIDER_MAP = [
        'claude-' => 'Anthropic',
        'gpt-'    => 'OpenAI',
        'o1'      => 'OpenAI',
        'o3'      => 'OpenAI',
        'gemini-' => 'Google',
    ];

    /**
     * Fallback caso cache esteja vazio E LiteLLM indisponível
     */
    private const FALLBACK_MODELS = [
        ['id' => 'claude-sonnet-4-5-20250514', 'display_name' => 'Claude Sonnet 4.5 (Anthropic)', 'created_at' => ''],
        ['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5 (Anthropic)', 'created_at' => ''],
        ['id' => 'gpt-4o-mini', 'display_name' => 'GPT-4o Mini (OpenAI)', 'created_at' => ''],
        ['id' => 'gemini-2.0-flash', 'display_name' => 'Gemini 2.0 Flash (Google)', 'created_at' => ''],
    ];

    private function __construct()
    {
        $this->settings = Settings::getInstance();
        $this->litellmBaseUrl = defined('LITELLM_BASE_URL') ? LITELLM_BASE_URL : 'http://192.168.100.12:4000';
        $this->litellmApiKey = defined('LITELLM_API_KEY') ? LITELLM_API_KEY : '';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Retorna lista completa de modelos disponíveis (id, display_name, created_at).
     * Auto-refresh se cache expirado (> 24h).
     *
     * @return array
     */
    public function getAvailableModels(): array
    {
        $models = $this->settings->get(self::CACHE_KEY, []);
        $updatedAt = $this->settings->get(self::CACHE_TIMESTAMP_KEY, '');

        // Cache vazio — buscar imediatamente
        if (empty($models)) {
            if ($this->refreshCache()) {
                return $this->settings->get(self::CACHE_KEY, []);
            }
            return self::FALLBACK_MODELS;
        }

        // Cache expirado — tentar refresh, mas retornar cache atual se falhar
        if ($this->isCacheStale($updatedAt)) {
            $this->refreshCache(); // best-effort
            // Recarregar do settings (pode ter sido atualizado)
            $refreshed = $this->settings->get(self::CACHE_KEY, []);
            return !empty($refreshed) ? $refreshed : $models;
        }

        return $models;
    }

    /**
     * Retorna apenas os IDs de modelos disponíveis.
     *
     * @return array<string>
     */
    public function getAvailableModelIds(): array
    {
        $models = $this->getAvailableModels();
        return array_column($models, 'id');
    }

    /**
     * Verifica se um modelo é válido (existe na lista de disponíveis).
     *
     * @param string $modelId
     * @return bool
     */
    public function isValidModel(string $modelId): bool
    {
        return in_array($modelId, $this->getAvailableModelIds(), true);
    }

    /**
     * Força refresh do cache buscando modelos do LiteLLM proxy.
     *
     * @return bool true se refresh bem-sucedido
     */
    public function refreshCache(): bool
    {
        try {
            $models = $this->fetchModelsFromApi();

            if (empty($models)) {
                error_log('ModelService: LiteLLM retornou lista vazia de modelos');
                return false;
            }

            // Salvar no settings (sem adminId — operação de sistema)
            $this->settings->set(self::CACHE_KEY, $models);
            $this->settings->set(self::CACHE_TIMESTAMP_KEY, date('c')); // ISO 8601

            return true;

        } catch (Exception $e) {
            error_log('ModelService::refreshCache() falhou: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retorna metadados do cache (timestamp, contagem).
     *
     * @return array{updated_at: string, count: int, is_stale: bool}
     */
    public function getCacheInfo(): array
    {
        $updatedAt = $this->settings->get(self::CACHE_TIMESTAMP_KEY, '');
        $models = $this->settings->get(self::CACHE_KEY, []);

        return [
            'updated_at' => $updatedAt ?: 'nunca',
            'count' => is_array($models) ? count($models) : 0,
            'is_stale' => $this->isCacheStale($updatedAt),
        ];
    }

    /**
     * Busca modelos do LiteLLM proxy (OpenAI-compatible /v1/models endpoint).
     *
     * @return array Lista de modelos [{id, display_name, created_at}, ...]
     * @throws Exception em caso de erro de rede ou API
     */
    private function fetchModelsFromApi(): array
    {
        $url = rtrim($this->litellmBaseUrl, '/') . '/v1/models';

        $headers = ['Content-Type: application/json'];
        if ($this->litellmApiKey) {
            $headers[] = 'Authorization: Bearer ' . $this->litellmApiKey;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("cURL error ao buscar modelos do LiteLLM: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new Exception("LiteLLM /v1/models retornou HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['data'])) {
            throw new Exception('Resposta inválida do LiteLLM /v1/models');
        }

        $allModels = [];
        foreach ($data['data'] as $model) {
            $modelId = $model['id'] ?? '';
            if (!$modelId) {
                continue;
            }

            $allModels[] = [
                'id' => $modelId,
                'display_name' => $this->buildDisplayName($modelId, $model),
                'created_at' => isset($model['created']) ? date('c', $model['created']) : '',
            ];
        }

        // Sort: Anthropic first, then OpenAI, then Google, alphabetical within groups
        usort($allModels, function ($a, $b) {
            $providerA = $this->detectProvider($a['id']);
            $providerB = $this->detectProvider($b['id']);
            if ($providerA !== $providerB) {
                $order = ['Anthropic' => 0, 'OpenAI' => 1, 'Google' => 2, '' => 3];
                return ($order[$providerA] ?? 3) <=> ($order[$providerB] ?? 3);
            }
            return $a['id'] <=> $b['id'];
        });

        return $allModels;
    }

    /**
     * Build a human-readable display name from model ID.
     */
    private function buildDisplayName(string $modelId, array $modelData): string
    {
        // If LiteLLM provides a display_name or name, use it
        if (!empty($modelData['display_name'])) {
            return $modelData['display_name'];
        }

        $provider = $this->detectProvider($modelId);
        $suffix = $provider ? " ({$provider})" : '';

        // Clean up model ID for display: strip date suffixes, capitalize
        $name = $modelId;
        // Remove date suffix like -20250514
        $name = preg_replace('/-\d{8}$/', '', $name);
        // Capitalize parts
        $name = str_replace(['-', '_'], ' ', $name);
        $name = ucwords($name);

        return $name . $suffix;
    }

    /**
     * Detect provider name from model ID.
     */
    private function detectProvider(string $modelId): string
    {
        foreach (self::PROVIDER_MAP as $prefix => $provider) {
            if (str_starts_with($modelId, $prefix)) {
                return $provider;
            }
        }
        return '';
    }

    /**
     * Verifica se o cache está expirado.
     *
     * @param string $updatedAt Timestamp ISO 8601
     * @return bool
     */
    private function isCacheStale(string $updatedAt): bool
    {
        if (empty($updatedAt)) {
            return true;
        }

        $cacheTime = strtotime($updatedAt);
        if ($cacheTime === false) {
            return true;
        }

        return (time() - $cacheTime) > self::CACHE_TTL_SECONDS;
    }
}
