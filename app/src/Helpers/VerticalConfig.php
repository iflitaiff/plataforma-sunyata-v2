<?php
/**
 * Vertical Configuration Helper
 *
 * Gerencia parâmetros de configuração para verticais com sistema híbrido:
 * - Arquivo config/verticals.php = Defaults seguros (versionado no Git)
 * - Banco verticals.config (JSON) = Overrides editáveis via admin UI
 *
 * @package Sunyata\Helpers
 * @since 2025-12-17
 */

namespace Sunyata\Helpers;

use Sunyata\Core\Database;

class VerticalConfig
{
    /**
     * Retorna parâmetros efetivos de uma vertical (merge de arquivo + banco)
     *
     * @param string $slug Slug da vertical (ex: 'iatr', 'juridico')
     * @return array Parâmetros mesclados
     *
     * @example
     * $config = VerticalConfig::get('iatr');
     * echo $config['temperature']; // 0.3 (default ou override)
     */
    public static function get(string $slug): array
    {
        // 1. Defaults do arquivo config/verticals.php
        $fileDefaults = self::getFileDefaults($slug);

        // 2. Overrides do banco (verticals.config)
        $dbOverrides = self::getDatabaseOverrides($slug);

        // 3. Merge: banco sobrescreve arquivo
        return array_merge($fileDefaults, $dbOverrides);
    }

    /**
     * Retorna apenas os defaults do arquivo (sem overrides do banco)
     *
     * @param string $slug
     * @return array
     */
    public static function getFileDefaults(string $slug): array
    {
        $allVerticals = require BASE_PATH . '/config/verticals.php';

        if (!isset($allVerticals[$slug])) {
            return [];
        }

        // Retorna apenas 'api_params', se existir
        return $allVerticals[$slug]['api_params'] ?? [];
    }

    /**
     * Retorna apenas os overrides do banco
     *
     * @param string $slug
     * @return array
     */
    public static function getDatabaseOverrides(string $slug): array
    {
        try {
            $db = Database::getInstance();
            $vertical = $db->fetchOne(
                "SELECT config FROM verticals WHERE slug = :slug",
                [':slug' => $slug]
            );

            if (!$vertical || $vertical['config'] === null) {
                return [];
            }

            $decoded = json_decode($vertical['config'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("VerticalConfig: Invalid JSON in verticals.config for slug=$slug");
                return [];
            }

            return $decoded ?? [];

        } catch (\Exception $e) {
            error_log("VerticalConfig: CRITICAL - Database error for slug=$slug: " . $e->getMessage());
            // Re-throw para forçar tratamento explícito no código chamador
            throw new \RuntimeException(
                "Failed to load vertical config from database for slug='$slug': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Salva overrides no banco
     *
     * @param string $slug
     * @param array $overrides Apenas os parâmetros que diferem dos defaults
     * @return bool
     */
    public static function saveOverrides(string $slug, array $overrides): bool
    {
        try {
            $db = Database::getInstance();

            // Se overrides está vazio, salva NULL (usa defaults do arquivo)
            $jsonValue = empty($overrides) ? null : json_encode($overrides, JSON_UNESCAPED_UNICODE);

            $db->update(
                'verticals',
                ['config' => $jsonValue],
                'slug = :slug',
                [':slug' => $slug]
            );

            return true;

        } catch (\Exception $e) {
            error_log("VerticalConfig: Failed to save overrides for slug=$slug: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Valida parâmetros de configuração
     *
     * @param array $params
     * @param string $context 'vertical' (default) ou 'canvas' — canvas rejeita system_prompt e chaves desconhecidas
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate(array $params, string $context = 'vertical'): array
    {
        $errors = [];

        // Chaves válidas para canvas override (apenas parâmetros API)
        $validCanvasKeys = ['claude_model', 'temperature', 'max_tokens', 'top_p'];

        // No contexto canvas, rejeitar chaves desconhecidas (incluindo system_prompt)
        if ($context === 'canvas') {
            $unknownKeys = array_diff(array_keys($params), $validCanvasKeys);
            if (!empty($unknownKeys)) {
                $errors[] = "Chave(s) não permitida(s) em override de canvas: " . implode(', ', $unknownKeys)
                    . ". Chaves válidas: " . implode(', ', $validCanvasKeys);
            }
        }

        // Validar temperature
        if (isset($params['temperature'])) {
            if (!is_numeric($params['temperature'])) {
                $errors[] = "'temperature' deve ser um número";
            } elseif ($params['temperature'] < 0 || $params['temperature'] > 1) {
                $errors[] = "'temperature' deve estar entre 0.0 e 1.0";
            }
        }

        // Validar max_tokens
        if (isset($params['max_tokens'])) {
            if (!is_int($params['max_tokens']) && !ctype_digit($params['max_tokens'])) {
                $errors[] = "'max_tokens' deve ser um número inteiro";
            } elseif ($params['max_tokens'] < 1 || $params['max_tokens'] > 200000) {
                $errors[] = "'max_tokens' deve estar entre 1 e 200000";
            }
        }

        // Validar top_p
        if (isset($params['top_p'])) {
            if (!is_numeric($params['top_p'])) {
                $errors[] = "'top_p' deve ser um número";
            } elseif ($params['top_p'] < 0 || $params['top_p'] > 1) {
                $errors[] = "'top_p' deve estar entre 0.0 e 1.0";
            }
        }

        // Validar claude_model (dinâmico via API Anthropic)
        if (isset($params['claude_model'])) {
            $modelService = \Sunyata\AI\ModelService::getInstance();
            if (!$modelService->isValidModel($params['claude_model'])) {
                $availableIds = $modelService->getAvailableModelIds();
                $preview = array_slice($availableIds, 0, 10);
                $suffix = count($availableIds) > 10
                    ? ' (e mais ' . (count($availableIds) - 10) . ')'
                    : '';
                $errors[] = "'claude_model' inválido. Modelos disponíveis: " . implode(', ', $preview) . $suffix;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Retorna preview dos parâmetros efetivos (para exibir no admin UI)
     *
     * @param string $slug
     * @param string $jsonOverrides JSON string com overrides
     * @return array ['effective' => array, 'error' => string|null]
     */
    public static function preview(string $slug, string $jsonOverrides): array
    {
        // 1. Defaults do arquivo
        $defaults = self::getFileDefaults($slug);

        // 2. Parse overrides
        $overrides = json_decode($jsonOverrides, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'effective' => null,
                'error' => 'JSON inválido: ' . json_last_error_msg()
            ];
        }

        // 3. Merge
        $effective = array_merge($defaults, $overrides ?? []);

        return [
            'effective' => $effective,
            'error' => null
        ];
    }
}
