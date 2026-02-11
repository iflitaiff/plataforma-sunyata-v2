<?php
/**
 * API: Export Canvas Response to PDF (Server-Side)
 * Uses mpdf to generate robust PDFs from Claude responses
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Mpdf\Mpdf;

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Não autenticado');
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Método não permitido');
}

// Pegar dados JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['html'])) {
    http_response_code(400);
    die('HTML content is required');
}

$html = $input['html'];
$filename = $input['filename'] ?? 'analise-juridica-' . time() . '.pdf';

if (empty(trim($html))) {
    http_response_code(400);
    die('HTML content is required');
}

try {
    // Configurar mpdf
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 20,
        'margin_bottom' => 20,
        'margin_header' => 10,
        'margin_footer' => 10,
        'default_font' => 'dejavusans'
    ]);

    // Configurar propriedades do PDF
    $mpdf->SetTitle('Análise Jurídica - Sunyata');
    $mpdf->SetAuthor('Plataforma Sunyata');
    $mpdf->SetCreator('Plataforma Sunyata - Canvas Jurídico');

    // CSS para melhorar a aparência do PDF
    $css = '
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #2c3e50;
        }
        h1, h2, h3, h4 {
            color: #1a365d;
            font-weight: 600;
            margin-top: 1.5em;
            margin-bottom: 0.75em;
            page-break-after: avoid;
        }
        h1 {
            font-size: 18pt;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0.3em;
        }
        h2 {
            font-size: 16pt;
            border-bottom: 1px solid #f1f3f5;
            padding-bottom: 0.3em;
        }
        h3 {
            font-size: 14pt;
        }
        h4 {
            font-size: 12pt;
        }
        p {
            margin-bottom: 1em;
            text-align: justify;
        }
        ul, ol {
            margin-bottom: 1em;
            padding-left: 2em;
        }
        li {
            margin-bottom: 0.5em;
        }
        strong {
            font-weight: 700;
            color: #1a365d;
        }
        em {
            font-style: italic;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 10pt;
        }
        pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            border-left: 4px solid #1a365d;
            overflow-x: auto;
            margin-bottom: 1em;
        }
        pre code {
            background: none;
            padding: 0;
        }
        blockquote {
            border-left: 4px solid #1a365d;
            padding-left: 1em;
            margin-left: 0;
            margin-bottom: 1em;
            color: #6c757d;
            font-style: italic;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 1em;
        }
        th, td {
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
    ';

    // Header e Footer
    $mpdf->SetHeader('Análise Jurídica - Plataforma Sunyata|{DATE j/m/Y}|{PAGENO}');
    $mpdf->SetFooter('Gerado via Canvas Jurídico v2||Página {PAGENO} de {nbpg}');

    // Escrever CSS
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

    // Escrever conteúdo HTML
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

    // Output do PDF
    $pdfContent = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

    // Enviar headers
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdfContent;
    exit;

} catch (Exception $e) {
    error_log('PDF generation error: ' . $e->getMessage());
    http_response_code(500);
    die('Erro ao gerar PDF: ' . $e->getMessage());
}
