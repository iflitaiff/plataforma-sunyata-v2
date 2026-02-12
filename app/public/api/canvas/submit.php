<?php
/**
 * API: Canvas Submit
 * Processa submissão de formulário SurveyJS e gera resposta via Claude
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;
use Sunyata\Helpers\ClaudeFacade;
use Sunyata\Services\DocumentProcessorService;
use Sunyata\Services\SubmissionService;

// Headers
header('Content-Type: application/json; charset=utf-8');

// Detectar modo debug
$debugMode = isset($_GET['debug']) && $_GET['debug'] == '1';

// Debug logger customizado (contorna log_errors => Off)
function debugLog($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../../../logs/canvas-debug.log';
    $entry = "[$timestamp] $message";
    if ($data !== null) {
        $entry .= "\n" . print_r($data, true);
    }
    $entry .= "\n---\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Validar CSRF token
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token inválido']);
    exit;
}

// Pegar dados JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido']);
    exit;
}

$canvasId = $input['canvas_id'] ?? null;
$formData = $input['form_data'] ?? null;
$plainData = $input['plain_data'] ?? null;  // Metadados para geração automática

debugLog("=== Canvas Submit START ===");
debugLog("Canvas ID: $canvasId");
debugLog("User ID: " . ($_SESSION['user_id'] ?? 'N/A'));
debugLog("Form Data Received:", $formData);
debugLog("Plain Data Received:", $plainData ? 'Yes (' . count($plainData) . ' items)' : 'No');

if (!$canvasId || !$formData) {
    debugLog("ERROR: Missing canvas_id or form_data");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'canvas_id e form_data são obrigatórios']);
    exit;
}

try {
    set_time_limit(360); // Match server's maxExecutionTime (Hostinger: 360s)

    $db = Database::getInstance();

    // Buscar canvas template (incluindo form_config para validação)
    $canvas = $db->fetchOne("
        SELECT id, slug, name, vertical, system_prompt, user_prompt_template, max_questions, form_config, api_params_override
        FROM canvas_templates
        WHERE id = :id AND is_active = TRUE
    ", ['id' => $canvasId]);

    if (!$canvas) {
        debugLog("ERROR: Canvas not found (ID: $canvasId)");
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Canvas não encontrado']);
        exit;
    }

    // Verificar se usuário tem acesso à vertical do canvas (segurança)
    $userVertical = $_SESSION['user']['selected_vertical'] ?? null;
    $isAdmin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
    $isDemo = $_SESSION['user']['is_demo'] ?? false;

    if (!$isAdmin && !$isDemo) {
        if (!$userVertical || $userVertical !== $canvas['vertical']) {
            debugLog("ERROR: Unauthorized canvas access", [
                'user_id' => $_SESSION['user_id'] ?? null,
                'user_vertical' => $userVertical,
                'canvas_vertical' => $canvas['vertical']
            ]);
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso não autorizado para este canvas']);
            exit;
        }
    }

    debugLog("Canvas Found:", [
        'id' => $canvas['id'],
        'slug' => $canvas['slug'],
        'name' => $canvas['name']
    ]);

    // ========== VALIDAÇÃO RIGOROSA DE SCHEMA (Sprint 3.5) ==========
    $formConfig = json_decode($canvas['form_config'], true);
    $jsonError = json_last_error_msg();
    debugLog("form_config JSON decode result:", [
        'success' => $formConfig !== null,
        'json_error' => $jsonError,
        'has_pages' => isset($formConfig['pages'])
    ]);

    if (!$formConfig || !isset($formConfig['pages'])) {
        debugLog("ERROR: Invalid form_config JSON: $jsonError");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Configuração do canvas inválida']);
        exit;
    }

    // Função: Extrair todos os nomes de campos do form_config
    function extractFieldNames($formConfig) {
        $fields = [];
        foreach ($formConfig['pages'] as $page) {
            if (isset($page['elements'])) {
                foreach ($page['elements'] as $element) {
                    // Apenas elementos com 'name' e que não sejam HTML
                    if (isset($element['name']) && ($element['type'] ?? '') !== 'html') {
                        $fields[] = $element['name'];

                        // Se o campo tem hasComment: true, também permite o campo -Comment
                        // (SurveyJS gera automaticamente {fieldname}-Comment para campos com hasComment)
                        if (isset($element['hasComment']) && $element['hasComment'] === true) {
                            $fields[] = $element['name'] . '-Comment';
                        }
                    }
                }
            }
        }
        return $fields;
    }

    // Extrair campos permitidos do form_config
    $allowedFields = extractFieldNames($formConfig);

    debugLog("Schema Validation:", [
        'allowed_fields_count' => count($allowedFields),
        'allowed_fields' => $allowedFields,
        'received_fields_count' => count(array_keys($formData)),
        'received_fields' => array_keys($formData)
    ]);

    // Detectar campos extras (não definidos no schema)
    $extraFields = array_diff(array_keys($formData), $allowedFields);
    if (!empty($extraFields)) {
        debugLog("⚠️ WARNING: Campos extras detectados e removidos:", $extraFields);
    }

    // Detectar campos obrigatórios faltantes
    $requiredFields = [];
    foreach ($formConfig['pages'] as $page) {
        if (isset($page['elements'])) {
            foreach ($page['elements'] as $element) {
                if (isset($element['name']) &&
                    isset($element['isRequired']) &&
                    $element['isRequired'] === true) {
                    $requiredFields[] = $element['name'];
                }
            }
        }
    }

    $missingRequired = array_diff($requiredFields, array_keys($formData));
    foreach ($missingRequired as $field) {
        // Verificar se campo está vazio (SurveyJS pode enviar string vazia)
        if (!isset($formData[$field]) || trim($formData[$field]) === '') {
            debugLog("❌ ERROR: Campo obrigatório faltando: $field");
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => "Campo obrigatório não preenchido: $field"
            ]);
            exit;
        }
    }

    // Filtrar form_data para conter apenas campos permitidos
    $validatedFormData = [];
    foreach ($formData as $key => $value) {
        if (in_array($key, $allowedFields)) {
            // Limite de tamanho por campo (anti-DoS)
            if (is_string($value)) {
                $validatedFormData[$key] = substr($value, 0, 10000); // Max 10k chars por campo
            } elseif (is_array($value)) {
                $validatedFormData[$key] = array_slice($value, 0, 100); // Max 100 items em arrays
            } else {
                $validatedFormData[$key] = $value;
            }
        }
    }

    debugLog("✅ Schema Validation PASSED:", [
        'extra_fields_removed' => count($extraFields),
        'validated_fields_count' => count($validatedFormData)
    ]);

    // Usar dados validados daqui pra frente
    $formData = $validatedFormData;

    // ========== LOGS DE DEBUG (Sugestão Manus AI) ==========
    debugLog("=== CANVAS SUBMIT DEBUG ===");
    debugLog("Mock Mode (sessão): " . (isset($_SESSION['canvas_mock_mode']) && $_SESSION['canvas_mock_mode'] ? 'SIM' : 'NÃO'));
    debugLog("Mock Mode (config): " . (defined('CLAUDE_MOCK_MODE') && CLAUDE_MOCK_MODE ? 'SIM' : 'NÃO'));
    debugLog("Form Data Keys: " . json_encode(array_keys($formData)));

    debugLog("Field Validation:", [
        'allowed_fields' => $allowedFields,
        'received_keys' => array_keys($input['form_data'] ?? []),
        'validated_keys' => array_keys($validatedFormData),
        'filtered_out' => array_diff(
            array_keys($input['form_data'] ?? []),
            array_keys($validatedFormData)
        )
    ]);

    // ========== FUNÇÃO AUXILIAR (deve estar antes do uso) ==========
    /**
     * Formata arrays para inclusão no prompt.
     * Trata paneldynamic (array de objetos) e checkbox (array simples).
     */
    if (!function_exists('formatArrayForPrompt')) {
        function formatArrayForPrompt($value) {
            // Array de objetos (paneldynamic)
            if (!empty($value) && is_array($value[0] ?? null)) {
                $lines = [];
                foreach ($value as $i => $obj) {
                    // Pular objetos de upload de arquivo
                    if (isset($obj['content']) && isset($obj['name']) && isset($obj['type'])) {
                        continue;
                    }
                    $parts = [];
                    foreach ($obj as $k => $v) {
                        if (is_string($v) || is_numeric($v)) {
                            $parts[] = ucfirst(str_replace('_', ' ', $k)) . ": " . $v;
                        }
                    }
                    if (!empty($parts)) {
                        $lines[] = "  " . ($i + 1) . ". " . implode(' | ', $parts);
                    }
                }
                return implode("\n", $lines);
            }
            // Array simples (checkbox)
            return implode(', ', array_map('strval', $value));
        }
    }

    // Construir prompt do usuário substituindo placeholders
    $userPrompt = $canvas['user_prompt_template'];

    debugLog("User Prompt Template (first 200 chars):", substr($userPrompt, 0, 200));

    // Processar blocos condicionais Handlebars {{#if campo}}...{{/if}}
    $userPrompt = preg_replace_callback(
        '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s',
        function($matches) use ($formData) {
            $fieldName = $matches[1];
            $content = $matches[2];
            // Se o campo existe e não está vazio, incluir o conteúdo
            if (isset($formData[$fieldName]) && !empty($formData[$fieldName])) {
                return $content;
            }
            return '';
        },
        $userPrompt
    );

    // Substituir cada campo do form_data no template
    foreach ($formData as $key => $value) {
        // Campos de arquivo são tratados separadamente via processFileUploads()
        if (is_array($value) && !empty($value) && isset($value[0]['content'])) {
            continue;
        }

        $placeholder = '{{' . $key . '}}';
        if (is_array($value)) {
            $value = formatArrayForPrompt($value);
        }
        $userPrompt = str_replace($placeholder, $value, $userPrompt);
    }

    debugLog("User Prompt After Substitution (first 300 chars):", substr($userPrompt, 0, 300));

    // ========== VALIDAÇÃO CRÍTICA: Placeholders Não Substituídos ==========
    if (preg_match_all('/\{\{([^}]+)\}\}/', $userPrompt, $matches)) {
        $unsubstituted = array_unique($matches[1]);

        debugLog("❌ ERRO CRÍTICO: Placeholders não substituídos detectados:", $unsubstituted);
        debugLog("Template ID: " . $canvas['slug']);
        debugLog("Form Data Keys:", array_keys($formData));

        // Determinar se são placeholders de condicionais ou simples
        $conditionalPlaceholders = array_filter($unsubstituted, function($p) {
            return strpos($p, '#if') === 0 || strpos($p, '/if') === 0;
        });

        $simplePlaceholders = array_diff($unsubstituted, $conditionalPlaceholders);

        if (!empty($simplePlaceholders)) {
            // Placeholders simples não substituídos = ERRO CRÍTICO
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro de configuração do template: Os seguintes campos não foram encontrados no formulário: ' .
                           implode(', ', $simplePlaceholders) .
                           '. Por favor, contate o administrador do sistema.'
            ]);
            exit;
        }

        // Se apenas placeholders condicionais, apenas avisar (pode ser normal)
        if (!empty($conditionalPlaceholders)) {
            debugLog("⚠️ AVISO: Placeholders condicionais não processados:", $conditionalPlaceholders);
        }
    }

    debugLog("✅ Placeholder Validation PASSED: Todos os placeholders foram substituídos");

    // ========== FUNÇÕES AUXILIARES ==========
    // Nota: formatArrayForPrompt() foi movida para linha ~213 (deve estar antes do uso)

    /**
     * Processa campos de upload de arquivo do SurveyJS.
     * Extrai texto dos documentos via DocumentProcessorService ou diretamente de base64.
     * Suporta dois formatos:
     * - content = ID numérico (arquivo salvo via upload-file.php)
     * - content = "data:application/pdf;base64,..." (SurveyJS fallback)
     */
    function processFileUploads($formData, $userId) {
        $fileTexts = [];

        foreach ($formData as $key => $value) {
            if (!is_array($value)) continue;

            foreach ($value as $item) {
                if (!is_array($item) || !isset($item['content'])) continue;

                $content = $item['content'];
                $fileName = $item['name'] ?? 'documento';

                try {
                    // Caso 1: Base64 Data URL (SurveyJS fallback quando onUploadFiles não funciona)
                    if (is_string($content) && strpos($content, 'data:') === 0) {
                        debugLog("📎 Detectado arquivo base64: {$fileName}");

                        // Extrair tipo MIME e dados base64
                        if (preg_match('/^data:([^;]+);base64,(.+)$/s', $content, $matches)) {
                            $mimeType = $matches[1];
                            $base64Data = $matches[2];
                            $binaryData = base64_decode($base64Data);

                            if ($binaryData === false) {
                                debugLog("⚠️ Falha ao decodificar base64 de: {$fileName}");
                                continue;
                            }

                            debugLog("📄 Base64 decodificado: {$fileName} ({$mimeType}, " . strlen($binaryData) . " bytes)");

                            // Extrair texto baseado no tipo
                            $extractedText = '';

                            if ($mimeType === 'application/pdf') {
                                // Usar smalot/pdfparser para extrair texto
                                $parser = new \Smalot\PdfParser\Parser();
                                $pdf = $parser->parseContent($binaryData);
                                $extractedText = $pdf->getText();
                                debugLog("✅ PDF parseado: " . strlen($extractedText) . " chars extraídos");
                            } elseif (in_array($mimeType, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'])) {
                                // DOCX - salvar temporariamente e usar PhpWord
                                $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
                                file_put_contents($tempFile, $binaryData);

                                $phpWord = \PhpOffice\PhpWord\IOFactory::load($tempFile);
                                foreach ($phpWord->getSections() as $section) {
                                    foreach ($section->getElements() as $element) {
                                        if (method_exists($element, 'getText')) {
                                            $extractedText .= $element->getText() . "\n";
                                        } elseif (method_exists($element, 'getElements')) {
                                            foreach ($element->getElements() as $childElement) {
                                                if (method_exists($childElement, 'getText')) {
                                                    $extractedText .= $childElement->getText() . "\n";
                                                }
                                            }
                                        }
                                    }
                                }
                                unlink($tempFile);
                                debugLog("✅ DOCX parseado: " . strlen($extractedText) . " chars extraídos");
                            } else {
                                debugLog("⚠️ Tipo MIME não suportado para extração de texto: {$mimeType}");
                                continue;
                            }

                            if (!empty(trim($extractedText))) {
                                $fileTexts[] = [
                                    'field' => $key,
                                    'filename' => $fileName,
                                    'text' => $extractedText
                                ];
                                debugLog("📄 Texto extraído de base64: {$fileName} (" . strlen($extractedText) . " chars)");
                            } else {
                                debugLog("⚠️ Nenhum texto extraído de: {$fileName}");
                            }
                        } else {
                            debugLog("⚠️ Formato base64 inválido para: {$fileName}");
                        }
                        continue;
                    }

                    // Caso 2: ID numérico (arquivo salvo no servidor)
                    $fileId = (int)$content;
                    if ($fileId <= 0) continue;

                    $processor = DocumentProcessorService::getInstance();
                    $result = $processor->extractText($fileId, $userId);

                    if ($result['success'] && !empty($result['text'])) {
                        $fileTexts[] = [
                            'field' => $key,
                            'filename' => $fileName,
                            'text' => $result['text']
                        ];
                        debugLog("📄 Texto extraído de arquivo ID {$fileId}: {$fileName} (" . strlen($result['text']) . " chars)");
                    } else {
                        debugLog("⚠️ Falha ao extrair texto do arquivo ID {$fileId}: " . ($result['message'] ?? 'desconhecido'));
                    }
                } catch (\Exception $e) {
                    debugLog("❌ Erro ao processar arquivo {$fileName}: " . $e->getMessage());
                }
            }

            // Incluir comentário do hasComment se existir
            $commentKey = $key . '-Comment';
            if (isset($formData[$commentKey]) && !empty($formData[$commentKey])) {
                $fileTexts[] = [
                    'field' => $key,
                    'filename' => '_comment',
                    'text' => $formData[$commentKey]
                ];
            }
        }

        return $fileTexts;
    }

    /**
     * Filtra plainData para conter apenas campos definidos no schema.
     * Defesa em profundidade contra injeção de campos via manipulação do request.
     */
    function filterPlainData($plainData, $allowedFields) {
        if (!is_array($plainData)) {
            return [];
        }
        return array_values(array_filter($plainData, function($item) use ($allowedFields) {
            return isset($item['name']) && in_array($item['name'], $allowedFields);
        }));
    }

    /**
     * Resolve a instrução do prompt, usando o mapa dinâmico se disponível.
     * Permite instruções diferentes para cada valor de dropdown.
     *
     * @param array $customProps Propriedades customizadas do campo (do form_config)
     * @param mixed $value O valor selecionado pelo usuário
     * @return string|null A instrução final do prompt
     */
    function resolvePromptInstruction($customProps, $value) {
        if (!$customProps) {
            return null;
        }

        // Verifica se existe mapa de instruções dinâmicas
        if (isset($customProps['promptInstructionMap'])
            && is_array($customProps['promptInstructionMap'])) {

            // Normalizar valor para string (para usar como chave do mapa)
            $valueKey = is_array($value) ? ($value[0] ?? '') : (string)$value;

            // Se a chave existe no mapa, usar instrução específica
            if (isset($customProps['promptInstructionMap'][$valueKey])) {
                return $customProps['promptInstructionMap'][$valueKey];
            }
        }

        // Fallback: instrução padrão
        return $customProps['promptInstruction'] ?? null;
    }

    // ========== GERAÇÃO AUTOMÁTICA DE PROMPT (Sprint 3.5) ==========
    // Função: Gerar prompt automaticamente de plainData + custom properties (Manus AI)
    // Versão 2.0: IDs únicos compostos + fallback de ordem
    function generatePromptFromPlainData($plainData, $formConfig = null, $canvasSlug = 'canvas') {
        $sections = [];

        // Construir mapa de custom properties por campo + índice de posição
        $customPropertiesMap = [];
        $fieldPositionIndex = 0;

        if ($formConfig && isset($formConfig['pages'])) {
            foreach ($formConfig['pages'] as $page) {
                if (isset($page['elements'])) {
                    foreach ($page['elements'] as $element) {
                        $fieldName = $element['name'] ?? null;
                        if ($fieldName) {
                            $customPropertiesMap[$fieldName] = [
                                'promptLabel' => $element['promptLabel'] ?? null,
                                'promptSection' => $element['promptSection'] ?? null,
                                'promptInstruction' => $element['promptInstruction'] ?? null,
                                'promptInstructionMap' => $element['promptInstructionMap'] ?? null, // Instruções dinâmicas por valor
                                'promptOrder' => $element['promptOrder'] ?? null, // null = usar posição
                                'title' => $element['title'] ?? null,
                                'arrayPosition' => $fieldPositionIndex // fallback order
                            ];
                            $fieldPositionIndex++;
                        }
                    }
                }
            }
        }

        foreach ($plainData as $item) {
            // Pular elementos HTML e campos vazios
            if (($item['type'] ?? '') === 'html' || empty($item['value'])) {
                continue;
            }

            $fieldName = $item['name'] ?? null;
            $value = $item['value'];

            // Pular campos de upload de arquivo (tratados separadamente)
            if (is_array($value) && !empty($value) && isset($value[0]['content'])) {
                continue;
            }

            // Preferir displayValue para campos com choices (dropdown, checkbox, radiogroup)
            if (isset($item['displayValue']) && !empty($item['displayValue'])) {
                $value = $item['displayValue'];
            }

            // Se for array, formatar adequadamente
            if (is_array($value)) {
                $value = formatArrayForPrompt($value);
            }

            // Buscar custom properties deste campo
            $customProps = $customPropertiesMap[$fieldName] ?? null;

            // Determinar nome da seção: promptSection (se houver) > title > name
            $sectionName = null;
            if ($customProps && !empty($customProps['promptSection'])) {
                $sectionName = $customProps['promptSection'];
            } elseif ($customProps && !empty($customProps['title'])) {
                $sectionName = $customProps['title'];
            } elseif (!empty($item['title'])) {
                $sectionName = $item['title'];
            } else {
                $sectionName = $fieldName ?? 'Campo';
            }

            // Resolver instrução (suporta promptInstructionMap para instruções dinâmicas)
            // Usa o valor ORIGINAL (não displayValue) como chave do mapa
            $originalValue = $item['value'];
            $instruction = resolvePromptInstruction($customProps, $originalValue);

            // CORREÇÃO 1: Ordem com fallback (promptOrder > arrayPosition > 999)
            $order = 999; // default final
            if ($customProps) {
                if (isset($customProps['promptOrder']) && $customProps['promptOrder'] !== null) {
                    $order = $customProps['promptOrder'];
                } elseif (isset($customProps['arrayPosition'])) {
                    $order = $customProps['arrayPosition'];
                }
            }

            // CORREÇÃO 2: ID composto canvas_slug:field_name
            $sectionId = $canvasSlug . ':' . $fieldName;

            // Construir seção no formato XML-like com ID único
            $section = "<PromptSection id=\"{$sectionId}\" name=\"{$sectionName}\">\n";

            if ($instruction) {
                $section .= "  <Instrução>{$instruction}</Instrução>\n";
            }

            $section .= "  <UserInput>\n";
            $section .= trim($value) . "\n";
            $section .= "  </UserInput>\n";

            // CORREÇÃO 3: Tag de fechamento com ID único
            $section .= "</PromptSection:{$sectionId}>";

            $sections[] = [
                'order' => $order,
                'content' => $section,
                'sectionId' => $sectionId // para logging futuro
            ];
        }

        // Ordenar por promptOrder/arrayPosition
        usort($sections, function($a, $b) {
            return $a['order'] - $b['order'];
        });

        // Extrair apenas o conteúdo
        $orderedContent = array_map(function($s) { return $s['content']; }, $sections);

        return implode("\n\n", $orderedContent);
    }

    // Se plainData disponível E template está vazio/nulo, gerar automaticamente
    if ($plainData && (empty($canvas['user_prompt_template']) || trim($canvas['user_prompt_template']) === '')) {
        // Filtrar plainData para conter apenas campos do schema (defesa em profundidade)
        $validatedPlainData = filterPlainData($plainData, $allowedFields);

        $filteredCount = count($plainData) - count($validatedPlainData);
        if ($filteredCount > 0) {
            debugLog("⚠️ plainData filtrado: {$filteredCount} campo(s) não autorizado(s) removido(s)");
        }

        debugLog("🔄 Gerando prompt AUTOMATICAMENTE de plainData + custom properties (Manus v2.0)");
        $userPrompt = generatePromptFromPlainData($validatedPlainData, $formConfig, $canvas['slug']);
        debugLog("✅ Prompt auto-gerado com IDs únicos + ordem corrigida (length: " . strlen($userPrompt) . ")");
    } else {
        debugLog("📝 Usando template Handlebars (método legado)");
    }
    // ========== FIM GERAÇÃO AUTOMÁTICA ==========

    // ========== PROCESSAMENTO DE ARQUIVOS ANEXADOS ==========
    $fileTexts = processFileUploads($formData, $_SESSION['user_id']);
    if (!empty($fileTexts)) {
        $canvasSlugForFiles = $canvas['slug'] ?? 'canvas';
        $docSection = "<PromptSection id=\"{$canvasSlugForFiles}:documentos_anexados\" name=\"Documentos Anexados\">\n";
        $docSection .= "  <Instrução>Analise os documentos anexados pelo usuário como parte do contexto.</Instrução>\n";

        foreach ($fileTexts as $ft) {
            if ($ft['filename'] === '_comment') {
                $docSection .= "  <ComentarioUsuario>" . htmlspecialchars($ft['text']) . "</ComentarioUsuario>\n";
            } else {
                $docSection .= "  <Documento nome=\"" . htmlspecialchars($ft['filename']) . "\">\n";
                $docSection .= trim($ft['text']) . "\n";
                $docSection .= "  </Documento>\n";
            }
        }

        $docSection .= "</PromptSection:{$canvasSlugForFiles}:documentos_anexados>";
        $userPrompt .= "\n\n" . $docSection;

        debugLog("📎 Seção de documentos anexados adicionada ao prompt (" . count($fileTexts) . " itens, " . strlen($docSection) . " chars)");
    }
    // ========== FIM PROCESSAMENTO DE ARQUIVOS ==========

    // ========== USAR HIERARQUIA DE 4 NÍVEIS (ClaudeFacade) ==========
    // ClaudeFacade::generateForCanvas() monta automaticamente:
    // - Nível 1a+1b: Vertical (arquivo + BD overrides)
    // - Nível 2: canvas_templates.system_prompt
    // - Nível 3: form_config.ajSystemPrompt

    debugLog("🎯 Usando ClaudeFacade::generateForCanvas (hierarquia 4 níveis)", [
        'vertical' => $canvas['vertical'],
        'canvas_id' => $canvasId,
        'canvas_slug' => $canvas['slug']
    ]);

    // Capturar tempo de início (para debug)
    $startTime = microtime(true);

    // Aplicar overrides de API params do canvas (se configurados)
    $canvasOverrides = [];
    if (!empty($canvas['api_params_override'])) {
        $canvasOverrides = json_decode($canvas['api_params_override'], true) ?? [];
        debugLog("🔧 Canvas API overrides aplicados", $canvasOverrides);
    }

    // ========== USER SUBMISSIONS (workspace) ==========
    // Create a pending submission in user_submissions for the user's workspace
    $submissionService = new SubmissionService();
    $submissionId = null;
    try {
        $submissionId = $submissionService->createSubmission(
            (int)$_SESSION['user_id'],
            (int)$canvasId,
            $canvas['vertical'],
            $formData
        );
        debugLog("📋 Submission created (pending): ID {$submissionId}");
    } catch (\Exception $e) {
        debugLog("⚠️ Failed to create submission: " . $e->getMessage());
        // Non-fatal: continue without submission tracking
    }

    // ========== STREAM MODE ==========
    // If mode=stream AND microservice is enabled, DO NOT expose internal key.
    // Fallback to sync response (CanvasStream.js handles no stream_url).
    $streamMode = ($_GET['mode'] ?? '') === 'stream';
    $aiServiceMode = \Sunyata\Core\Settings::getInstance()->get('ai_service_mode', 'direct');

    if ($streamMode && $aiServiceMode === 'microservice') {
        debugLog("🔄 Stream mode requested; falling back to sync to avoid exposing internal key");
        $result = ClaudeFacade::generateViaService(
            verticalSlug: $canvas['vertical'],
            canvasTemplateId: $canvasId,
            prompt: $userPrompt,
            userId: $_SESSION['user_id'],
            toolName: $canvas['slug'],
            inputData: $formData,
            overrideOptions: $canvasOverrides
        );

        echo json_encode(array_merge($result, [
            'canvas_id' => $canvasId,
            'submission_id' => $submissionId,
        ]));
        exit;
    }

    // ========== SYNC MODE (default) ==========
    // Use microservice if enabled, otherwise direct ClaudeService
    if ($aiServiceMode === 'microservice') {
        $result = ClaudeFacade::generateViaService(
            verticalSlug: $canvas['vertical'],
            canvasTemplateId: $canvasId,
            prompt: $userPrompt,
            userId: $_SESSION['user_id'],
            toolName: $canvas['slug'],
            inputData: $formData,
            overrideOptions: $canvasOverrides
        );
    } else {
        $result = ClaudeFacade::generateForCanvas(
            verticalSlug: $canvas['vertical'], // ← Usar vertical do CANVAS, não da sessão!
            canvasTemplateId: $canvasId,
            prompt: $userPrompt,
            userId: $_SESSION['user_id'],
            toolName: $canvas['slug'],
            inputData: $formData,
            overrideOptions: $canvasOverrides
        );
    }

    debugLog("Claude API Result:", [
        'success' => $result['success'],
        'has_response' => isset($result['response']),
        'has_error' => isset($result['error']),
        'error' => $result['error'] ?? null,
        'error_detail' => $result['error_detail'] ?? null,
        'result_keys' => array_keys($result)
    ]);

    // Verificar se Mock Mode foi usado (modelo retornado é 'claude-mock-v1')
    if (isset($result['model']) && $result['model'] === 'claude-mock-v1') {
        debugLog("✅ Mock Mode ATIVO - Resposta simulada retornada");
    } else {
        debugLog("🌐 API Real - Modelo: " . ($result['model'] ?? 'desconhecido'));
    }

    if ($result['success']) {
        // Calcular tempo de execução
        $executionTime = round(microtime(true) - $startTime, 2);

        // Complete the user submission with the result
        if ($submissionId) {
            try {
                $submissionService->completeSubmission(
                    $submissionId,
                    $result['response'],
                    $result['history_id'] ?? null,
                    [
                        'model' => $result['model'] ?? null,
                        'tokens_input' => $result['tokens']['input'] ?? 0,
                        'tokens_output' => $result['tokens']['output'] ?? 0,
                        'tokens_total' => ($result['tokens']['input'] ?? 0) + ($result['tokens']['output'] ?? 0),
                        'cost_usd' => $result['cost_usd'] ?? 0,
                        'execution_time' => $executionTime,
                    ]
                );
                debugLog("📋 Submission completed: ID {$submissionId}");
            } catch (\Exception $e) {
                debugLog("⚠️ Failed to complete submission: " . $e->getMessage());
            }
        }

        // Montar resposta base
        $response = [
            'success' => true,
            'response' => $result['response'],
            'history_id' => $result['history_id'],
            'canvas_id' => $canvasId, // Para modal de feedback
            'submission_id' => $submissionId,
            'tokens' => $result['tokens'],
            'cost_usd' => $result['cost_usd']
        ];

        // Se modo debug ativo, adicionar informações de debug
        if ($debugMode) {
            $response['debug_info'] = [
                'system_prompt' => $systemPrompt,
                'user_prompt' => $userPrompt,
                'metadata' => [
                    'model' => $result['model'] ?? 'claude-3-5-sonnet-20241022',
                    'input_tokens' => $result['tokens']['input'] ?? 0,
                    'output_tokens' => $result['tokens']['output'] ?? 0,
                    'execution_time' => $executionTime . 's',
                    'cost' => '$' . number_format($result['cost_usd'], 4)
                ]
            ];
        }

        echo json_encode($response);
    } else {
        // Mark submission as error
        if ($submissionId) {
            try {
                $submissionService->failSubmission($submissionId, $result['error'] ?? 'Unknown error');
                debugLog("📋 Submission marked as error: ID {$submissionId}");
            } catch (\Exception $e) {
                debugLog("⚠️ Failed to mark submission error: " . $e->getMessage());
            }
        }

        // Log de debug temporário
        error_log('Canvas Claude API Error: ' . ($result['error'] ?? 'Unknown error'));
        error_log('Canvas ID: ' . $canvasId . ', User ID: ' . $_SESSION['user_id']);

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Erro ao gerar conteúdo',
            'debug' => $debugMode ? [
                'canvas_id' => $canvasId,
                'result_keys' => array_keys($result)
            ] : null
        ]);
    }

} catch (Exception $e) {
    error_log('Canvas submit error: ' . $e->getMessage());

    // Mark submission as error if it was created
    if (isset($submissionId) && $submissionId && isset($submissionService)) {
        try {
            $submissionService->failSubmission($submissionId, $e->getMessage());
        } catch (\Exception $ignore) {
            // non-fatal
        }
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor'
    ]);
}
