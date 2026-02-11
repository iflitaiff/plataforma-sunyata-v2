<?php
/**
 * Canvas Helper - Utilidades para Canvas/SurveyJS
 *
 * ARQUIVO NOVO - NÃO MODIFICA CÓDIGO EXISTENTE
 * Uso opcional para centralizar validações e extrações
 *
 * @package Sunyata\Helpers
 * @created 2025-12-12
 */

namespace Sunyata\Helpers;

use Sunyata\Core\Settings;

class CanvasHelper
{
    /**
     * Valida e decodifica form_config JSON
     *
     * @param string $json JSON do form_config
     * @return array Array decodificado
     * @throws \Exception Se JSON inválido ou estrutura incorreta
     */
    public static function validateFormConfig(string $json): array
    {
        // Decodificar JSON
        $data = json_decode($json, true);

        // Verificar erros de JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON inválido: ' . json_last_error_msg());
        }

        // Validar estrutura básica SurveyJS
        if (!isset($data['pages']) || !is_array($data['pages'])) {
            throw new \Exception('Estrutura inválida: propriedade "pages" não encontrada ou não é array');
        }

        // Validar que há pelo menos uma página
        if (count($data['pages']) === 0) {
            throw new \Exception('Estrutura inválida: nenhuma página definida');
        }

        return $data;
    }

    /**
     * Extrai nomes de todos os campos (elements) do form_config
     *
     * @param array $formConfig Array do form_config já decodificado
     * @return array Lista de nomes de campos ['field1', 'field2', ...]
     */
    public static function extractFieldNames(array $formConfig): array
    {
        $fields = [];

        // Iterar páginas
        foreach ($formConfig['pages'] ?? [] as $page) {
            // Iterar elementos de cada página
            foreach ($page['elements'] ?? [] as $element) {
                // Só adicionar se tiver nome (campos tipo "html" não têm)
                if (isset($element['name']) && !empty($element['name'])) {
                    $fields[] = $element['name'];
                }
            }
        }

        return $fields;
    }

    /**
     * Extrai placeholders Handlebars de um template
     *
     * @param string $template Template com placeholders {{field_name}}
     * @return array Lista de placeholders ['field1', 'field2', ...]
     */
    public static function extractPlaceholders(string $template): array
    {
        $placeholders = [];

        // Regex para capturar {{field_name}}
        if (preg_match_all('/\{\{(\w+)\}\}/', $template, $matches)) {
            $placeholders = $matches[1];
        }

        // Remover duplicatas
        return array_unique($placeholders);
    }

    /**
     * Valida alinhamento entre form_config e user_prompt_template
     *
     * @param array $formConfig Array do form_config
     * @param string $template String do user_prompt_template
     * @return array ['valid' => bool, 'missing' => [], 'unused' => [], 'message' => '']
     */
    public static function validateAlignment(array $formConfig, string $template): array
    {
        // Extrair campos e placeholders
        $fields = self::extractFieldNames($formConfig);
        $placeholders = self::extractPlaceholders($template);

        // Encontrar problemas
        $missing = array_diff($placeholders, $fields);      // Placeholders que não existem em form_config
        $unused = array_diff($fields, $placeholders);       // Campos que não são usados no template

        // Determinar validade
        $valid = (count($missing) === 0);  // Só é inválido se houver placeholders faltando

        // Gerar mensagem
        $message = '';
        if ($valid && count($unused) === 0) {
            $message = '✅ Alinhamento perfeito! Todos os campos estão cobertos.';
        } elseif ($valid && count($unused) > 0) {
            $message = '✅ Válido, mas há campos não usados: ' . implode(', ', $unused);
        } else {
            $message = '❌ Inválido! Placeholders faltando: ' . implode(', ', $missing);
        }

        return [
            'valid' => $valid,
            'missing' => array_values($missing),
            'unused' => array_values($unused),
            'fields_count' => count($fields),
            'placeholders_count' => count($placeholders),
            'message' => $message
        ];
    }

    /**
     * Gera user_prompt_template básico a partir do form_config
     * Útil para criar template inicial
     *
     * @param array $formConfig Array do form_config
     * @return string Template Handlebars básico
     */
    public static function generateBasicTemplate(array $formConfig): string
    {
        $fields = self::extractFieldNames($formConfig);

        $template = "Por favor, analise as seguintes informações:\n\n";

        foreach ($fields as $field) {
            // Converter snake_case para Title Case
            $label = ucwords(str_replace('_', ' ', $field));
            $template .= "**{$label}:** {{{$field}}}\n";
        }

        $template .= "\nForneça uma análise detalhada com base nestas informações.";

        return $template;
    }

    /**
     * Valida se um canvas está pronto para produção
     * Executa múltiplas validações
     *
     * @param array $canvas Array do canvas (com form_config, user_prompt_template, etc)
     * @return array ['ready' => bool, 'errors' => [], 'warnings' => []]
     */
    public static function validateForProduction(array $canvas): array
    {
        $errors = [];
        $warnings = [];

        // 1. Verificar campos obrigatórios
        $required = ['form_config', 'user_prompt_template', 'system_prompt', 'name', 'slug'];
        foreach ($required as $field) {
            if (empty($canvas[$field])) {
                $errors[] = "Campo obrigatório ausente: {$field}";
            }
        }

        if (count($errors) > 0) {
            return ['ready' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // 2. Validar form_config JSON
        try {
            $formConfig = self::validateFormConfig($canvas['form_config']);
        } catch (\Exception $e) {
            $errors[] = "form_config inválido: " . $e->getMessage();
            return ['ready' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // 3. Validar alinhamento
        $alignment = self::validateAlignment($formConfig, $canvas['user_prompt_template']);

        if (!$alignment['valid']) {
            $errors[] = "Alinhamento inválido: " . implode(', ', $alignment['missing']);
        }

        if (count($alignment['unused']) > 0) {
            $warnings[] = "Campos não usados no template: " . implode(', ', $alignment['unused']);
        }

        // 4. Verificar se há pelo menos 2 campos (senão é muito simples)
        if ($alignment['fields_count'] < 2) {
            $warnings[] = "Apenas {$alignment['fields_count']} campo(s) no formulário. Considere adicionar mais.";
        }

        // 5. Verificar tamanho do system_prompt
        if (strlen($canvas['system_prompt']) < 50) {
            $warnings[] = "system_prompt muito curto (" . strlen($canvas['system_prompt']) . " chars). Considere detalhar mais.";
        }

        return [
            'ready' => (count($errors) === 0),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Retorna system prompt completo com hierarquia de 4 níveis
     *
     * HIERARQUIA (ordem de concatenação):
     * 0. Portal (genérico, cross-vertical) — settings.portal_system_prompt
     * 1. Vertical (config/verticals.php + verticals.config)
     * 2. Canvas Template (canvas_templates.system_prompt)
     * 3. Form Config JSON (ajSystemPrompt)
     *
     * @param string $verticalSlug Slug da vertical (ex: 'iatr', 'juridico')
     * @param int $canvasTemplateId ID do canvas_template
     * @return string System prompt completo (merge dos 4 níveis)
     *
     * @example
     * $systemPrompt = CanvasHelper::getCompleteSystemPrompt('juridico', 15);
     * // Retorna: "Portal prompt...\n\nVocê é assistente jurídico...\n\nVocê é advogado especializado..."
     */
    public static function getCompleteSystemPrompt(
        string $verticalSlug,
        int $canvasTemplateId
    ): string {
        $prompts = [];

        // NÍVEL 0: Portal (genérico, cross-vertical)
        $portalPrompt = Settings::getInstance()->get('portal_system_prompt', '');
        if (!empty($portalPrompt)) {
            $prompts[] = $portalPrompt;
        }

        // NÍVEL 1: Vertical (base - contexto geral)
        try {
            $verticalConfig = VerticalConfig::get($verticalSlug);
            $verticalPrompt = $verticalConfig['system_prompt'] ?? '';

            if (!empty($verticalPrompt)) {
                $prompts[] = $verticalPrompt;
            }
        } catch (\Exception $e) {
            error_log("CanvasHelper::getCompleteSystemPrompt() - Error loading vertical config: " . $e->getMessage());
        }

        // NÍVEL 2: Canvas Template (contexto específico do canvas)
        try {
            $db = \Sunyata\Core\Database::getInstance();
            $template = $db->fetchOne(
                "SELECT system_prompt, form_config FROM canvas_templates WHERE id = :id",
                [':id' => $canvasTemplateId]
            );

            if ($template) {
                // System prompt direto do canvas_template
                $canvasPrompt = $template['system_prompt'] ?? '';
                if (!empty($canvasPrompt)) {
                    $prompts[] = $canvasPrompt;
                }

                // NÍVEL 3: Form Config JSON (ajSystemPrompt - instruções específicas do formulário)
                $formConfig = json_decode($template['form_config'] ?? '{}', true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $formPrompt = $formConfig['ajSystemPrompt'] ?? '';
                    if (!empty($formPrompt)) {
                        $prompts[] = $formPrompt;
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("CanvasHelper::getCompleteSystemPrompt() - Error loading canvas template: " . $e->getMessage());
        }

        // Concatenar prompts (separados por linha dupla para clareza)
        return implode("\n\n", $prompts);
    }

    /**
     * Debug: Retorna breakdown dos 4 níveis de system prompts
     *
     * @param string $verticalSlug
     * @param int $canvasTemplateId
     * @return array ['nivel0' => '...', 'nivel1' => '...', 'nivel2' => '...', 'nivel3' => '...', 'final' => '...']
     */
    public static function debugSystemPromptHierarchy(
        string $verticalSlug,
        int $canvasTemplateId
    ): array {
        $breakdown = [
            'nivel0_portal' => '',
            'nivel1_vertical' => '',
            'nivel2_canvas_template' => '',
            'nivel3_form_ajSystemPrompt' => '',
            'final_concatenado' => ''
        ];

        // NÍVEL 0: Portal
        $breakdown['nivel0_portal'] = Settings::getInstance()->get('portal_system_prompt', '');

        // NÍVEL 1: Vertical
        try {
            $verticalConfig = VerticalConfig::get($verticalSlug);
            $breakdown['nivel1_vertical'] = $verticalConfig['system_prompt'] ?? '';
        } catch (\Exception $e) {
            $breakdown['nivel1_vertical'] = '[ERROR: ' . $e->getMessage() . ']';
        }

        // NÍVEL 2 e 3: Canvas Template + Form Config
        try {
            $db = \Sunyata\Core\Database::getInstance();
            $template = $db->fetchOne(
                "SELECT system_prompt, form_config FROM canvas_templates WHERE id = :id",
                [':id' => $canvasTemplateId]
            );

            if ($template) {
                $breakdown['nivel2_canvas_template'] = $template['system_prompt'] ?? '';

                $formConfig = json_decode($template['form_config'] ?? '{}', true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $breakdown['nivel3_form_ajSystemPrompt'] = $formConfig['ajSystemPrompt'] ?? '';
                }
            }
        } catch (\Exception $e) {
            $breakdown['nivel2_canvas_template'] = '[ERROR: ' . $e->getMessage() . ']';
        }

        // Final
        $breakdown['final_concatenado'] = self::getCompleteSystemPrompt($verticalSlug, $canvasTemplateId);

        return $breakdown;
    }
}
