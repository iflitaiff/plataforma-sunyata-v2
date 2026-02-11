<?php
/**
 * API Endpoint: Geração de Conteúdo Jurídico via Claude
 *
 * Recebe dados do Canvas Jurídico, gera prompt, chama Claude API,
 * guarda histórico (transparente ao usuário) e retorna resposta.
 *
 * @package Sunyata\API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\AI\ClaudeService;

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Usuário não autenticado'
    ]);
    exit;
}

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido'
    ]);
    exit;
}

// Receber dados JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Dados inválidos'
    ]);
    exit;
}

// Validação básica
if (empty($input['tarefa']) || empty($input['contexto'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Campos obrigatórios não preenchidos'
    ]);
    exit;
}

try {
    // Construir prompt melhorado (Sprint 3.5 - Chain-of-thought + Examples + Formatação)
    $prompt = "Você é um advogado sênior especializado em grandes escritórios com vasta experiência em advocacia empresarial e conhecimento profundo da prática jurídica brasileira.\n\n";

    $prompt .= "**TAREFA/OBJETIVO JURÍDICO:**\n" . $input['tarefa'] . "\n\n";
    $prompt .= "**CONTEXTO & CLIENTE:**\n" . $input['contexto'] . "\n\n";

    if (!empty($input['entradas'])) {
        $prompt .= "**MATERIAIS DISPONÍVEIS:**\n" . $input['entradas'] . "\n\n";
    }

    if (!empty($input['restricoes'])) {
        $prompt .= "**RESTRIÇÕES & MARCO LEGAL:**\n" . $input['restricoes'] . "\n\n";
    }

    if (!empty($input['saida'])) {
        $prompt .= "**FORMATO DA ENTREGA:**\n" . $input['saida'] . "\n\n";
    }

    if (!empty($input['criterios'])) {
        $prompt .= "**CRITÉRIOS DE QUALIDADE:**\n" . $input['criterios'] . "\n\n";
    }

    // Chain-of-thought simplificado
    $prompt .= "**METODOLOGIA DE ANÁLISE:**\n";
    $prompt .= "Antes de formular suas perguntas, considere internamente:\n";
    $prompt .= "1. Qual a complexidade jurídica envolvida?\n";
    $prompt .= "2. Quais informações são críticas vs. complementares?\n";
    $prompt .= "3. Quais riscos jurídicos precisam ser mapeados?\n";
    $prompt .= "4. Qual o nível de profundidade técnica adequado ao cliente?\n\n";

    // Examples (2 apenas - um simples + um complexo)
    $prompt .= "**EXEMPLOS DE BOA INTERAÇÃO:**\n\n";

    $prompt .= "Exemplo 1 (Caso Simples):\n";
    $prompt .= "Tarefa: \"Revisar cláusula de confidencialidade\"\n";
    $prompt .= "Perguntas adequadas:\n";
    $prompt .= "1. O contrato é nacional ou internacional?\n";
    $prompt .= "2. Há transferência de dados pessoais (LGPD aplicável)?\n";
    $prompt .= "3. Qual o prazo de vigência da confidencialidade desejado?\n\n";

    $prompt .= "Exemplo 2 (Caso Complexo):\n";
    $prompt .= "Tarefa: \"Estruturar fusão entre empresas\"\n";
    $prompt .= "Perguntas adequadas:\n";
    $prompt .= "1. Há necessidade de aprovação CADE (faturamento >R$750MM)?\n";
    $prompt .= "2. As empresas têm passivos trabalhistas ou tributários relevantes?\n";
    $prompt .= "3. A estrutura será incorporação, aquisição ou joint venture?\n";
    $prompt .= "4. Há sócios minoritários que precisam ser consultados?\n\n";

    // Formatação estruturada
    $prompt .= "**FORMATO DE RESPOSTA ESPERADO:**\n";
    $prompt .= "Estruture suas perguntas de forma:\n";
    $prompt .= "- Numeradas sequencialmente (1, 2, 3...)\n";
    $prompt .= "- Objetivas e diretas\n";
    $prompt .= "- Priorizadas por criticidade (perguntas essenciais primeiro)\n";
    $prompt .= "- Contextualizadas (explique brevemente POR QUE precisa da informação)\n";
    $prompt .= "- Limitadas a 3-5 perguntas por rodada (evite sobrecarregar o usuário)\n\n";

    $prompt .= "**INSTRUÇÕES IMPORTANTES:**\n";
    $prompt .= "- Mantenha rigor técnico-jurídico e aderência às melhores práticas de grandes escritórios\n";
    $prompt .= "- Considere sempre aspectos práticos de implementação e viabilidade econômica\n";
    $prompt .= "- Base suas sugestões na legislação brasileira vigente e jurisprudência consolidada\n";
    $prompt .= "- Se alguma informação essencial estiver ausente, questione antes de prosseguir\n\n";

    $prompt .= "Agora, faça suas perguntas seguindo a metodologia e o formato acima.";

    // Chamar Claude API via ClaudeService
    $claudeService = new ClaudeService();

    $result = $claudeService->generate(
        $prompt,
        $_SESSION['user_id'],
        $_SESSION['user']['selected_vertical'] ?? 'juridico',
        'canvas_juridico',
        $input,  // Dados do formulário (guardados no histórico)
        [
            'max_tokens' => 4096,
            'temperature' => 1.0
        ]
    );

    // Retornar resposta
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'response' => $result['response'],
            'prompt' => $prompt,  // Enviamos o prompt também
            'tokens' => $result['tokens'] ?? null,
            'history_id' => $result['history_id']
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Erro ao gerar conteúdo',
            'prompt' => $prompt  // Mesmo em erro, mostramos o prompt gerado
        ]);
    }

} catch (Exception $e) {
    error_log('API generate-juridico error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor. Por favor, tente novamente.'
    ]);
}
