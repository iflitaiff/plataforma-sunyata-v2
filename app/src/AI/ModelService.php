<?php
/**
 * Claude Model Service - Lista dinâmica de modelos via API Anthropic
 *
 * Busca, cacheia e valida modelos Claude disponíveis usando o endpoint
 * GET /v1/models da API Anthropic. Cache armazenado na tabela settings.
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
    private string $apiKey;
    private string $apiBaseUrl = 'https://api.anthropic.com/v1';

    private const CACHE_KEY = 'claude_models_cache';
    private const CACHE_TIMESTAMP_KEY = 'claude_models_cache_updated_at';
    private const CACHE_TTL_SECONDS = 86400; // 24 horas

    /**
     * Fallback caso cache esteja vazio E API indisponível
     */
    private const FALLBACK_MODELS = [
        ['id' => 'claude-sonnet-4-5-20250514', 'display_name' => 'Claude Sonnet 4.5', 'created_at' => ''],
        ['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5', 'created_at' => ''],
        ['id' => 'claude-3-5-sonnet-20241022', 'display_name' => 'Claude 3.5 Sonnet', 'created_at' => ''],
        ['id' => 'claude-3-5-haiku-20241022', 'display_name' => 'Claude 3.5 Haiku', 'created_at' => ''],
        ['id' => 'claude-3-opus-20240229', 'display_name' => 'Claude 3 Opus', 'created_at' => ''],
    ];

    private function __construct()
    {
        $this->settings = Settings::getInstance();

        if (!defined('CLAUDE_API_KEY')) {
            throw new Exception('CLAUDE_API_KEY não definida em secrets.php');
        }
        $this->apiKey = CLAUDE_API_KEY;
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
     * Força refresh do cache buscando modelos da API Anthropic.
     *
     * @return bool true se refresh bem-sucedido
     */
    public function refreshCache(): bool
    {
        try {
            $models = $this->fetchModelsFromApi();

            if (empty($models)) {
                error_log('ModelService: API retornou lista vazia de modelos');
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
     * Busca todos os modelos da API Anthropic com paginação.
     *
     * @return array Lista de modelos [{id, display_name, created_at}, ...]
     * @throws Exception em caso de erro de rede ou API
     */
    private function fetchModelsFromApi(): array
    {
        $allModels = [];
        $afterId = null;

        do {
            $url = $this->apiBaseUrl . '/models?limit=1000';
            if ($afterId !== null) {
                $url .= '&after_id=' . urlencode($afterId);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: 2023-06-01',
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception("cURL error ao buscar modelos: {$curlError}");
            }

            if ($httpCode !== 200) {
                $data = json_decode($response, true);
                $errorMsg = $data['error']['message'] ?? 'Erro desconhecido';
                throw new Exception("API Anthropic /v1/models retornou HTTP {$httpCode}: {$errorMsg}");
            }

            $data = json_decode($response, true);
            if (!$data || !isset($data['data'])) {
                throw new Exception('Resposta inválida da API /v1/models');
            }

            foreach ($data['data'] as $model) {
                $allModels[] = [
                    'id' => $model['id'],
                    'display_name' => $model['display_name'] ?? '',
                    'created_at' => $model['created_at'] ?? '',
                ];
            }

            $hasMore = $data['has_more'] ?? false;
            $afterId = $data['last_id'] ?? null;

        } while ($hasMore && $afterId !== null);

        return $allModels;
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
