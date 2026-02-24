<?php
/**
 * PNCP Analysis PDF Export
 *
 * GET /api/pncp/export-pdf.php?id=N           — inline (for iframe preview)
 * GET /api/pncp/export-pdf.php?id=N&download=1 — attachment (for download)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    die('Não autenticado');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    die('Parâmetro "id" inválido');
}

use Sunyata\Core\Database;

$db = Database::getInstance();
$edital = $db->fetchOne("SELECT * FROM pncp_editais WHERE id = ?", [$id]);

if (!$edital || $edital['status_analise'] !== 'concluida') {
    http_response_code(404);
    die('Análise não encontrada ou não concluída');
}

$resultado = json_decode($edital['analise_resultado'], true);
$markdown = $resultado['resumo_executivo'] ?? $resultado['texto'] ?? $resultado['markdown'] ?? '';

if (!$markdown) {
    http_response_code(404);
    die('Resultado da análise vazio');
}

$parsedown = new Parsedown();
$parsedown->setSafeMode(true);
$htmlContent = $parsedown->text($markdown);

$orgao = htmlspecialchars($edital['orgao'] ?? 'N/I');
$uf = htmlspecialchars($edital['uf'] ?? '');
$municipio = htmlspecialchars($edital['municipio'] ?? '');
$numero = htmlspecialchars($edital['numero'] ?? $edital['pncp_id'] ?? 'N/I');
$modelo = htmlspecialchars($edital['analise_modelo'] ?? 'N/I');
$tokens = $edital['analise_tokens'] ? number_format((int)$edital['analise_tokens'], 0, ',', '.') : 'N/I';
$dataConcluida = $edital['analise_concluida_em']
    ? (new DateTime($edital['analise_concluida_em']))->format('d/m/Y H:i')
    : 'N/I';

$pdfHtml = <<<HTML
<html>
<head>
<style>
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #333; line-height: 1.6; }
    .header { background: #1a2a3a; color: #fff; padding: 20px; margin: -20px -20px 20px -20px; }
    .header h1 { margin: 0; font-size: 18px; }
    .header p { margin: 5px 0 0; opacity: 0.8; font-size: 10px; }
    .meta-box { background: #f4f5f7; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px 15px; margin-bottom: 20px; font-size: 10px; }
    .meta-box table { width: 100%; }
    .meta-box td { padding: 2px 8px; }
    .meta-box .label { font-weight: bold; color: #666; width: 120px; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { border: 1px solid #dee2e6; padding: 6px 10px; text-align: left; font-size: 10px; }
    th { background: #f1f3f5; font-weight: 600; }
    h1 { font-size: 16px; color: #1a2a3a; border-bottom: 2px solid #3498db; padding-bottom: 5px; }
    h2 { font-size: 13px; color: #2c3e50; }
    h3 { font-size: 11px; color: #34495e; }
    hr { border: none; border-top: 1px solid #dee2e6; margin: 15px 0; }
    blockquote { border-left: 3px solid #3498db; padding-left: 10px; color: #666; margin: 10px 0; }
    .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #dee2e6; font-size: 8px; color: #999; text-align: center; }
</style>
</head>
<body>
    <div class="header">
        <h1>Resumo Executivo — Edital {$numero}</h1>
        <p>{$orgao} — {$municipio}/{$uf}</p>
    </div>
    <div class="meta-box">
        <table><tr>
            <td class="label">Modelo IA:</td><td>{$modelo}</td>
            <td class="label">Tokens:</td><td>{$tokens}</td>
            <td class="label">Data:</td><td>{$dataConcluida}</td>
        </tr></table>
    </div>
    {$htmlContent}
    <div class="footer">
        Gerado por Sunyata Consulting — IATR Monitoramento PNCP<br>
        Análise gerada por inteligência artificial. Verificar dados antes de decisões.
    </div>
</body>
</html>
HTML;

$mpdf = new \Mpdf\Mpdf([
    'margin_top' => 25,
    'margin_bottom' => 20,
    'margin_left' => 15,
    'margin_right' => 15,
    'default_font' => 'dejavusans',
]);

$mpdf->SetTitle("Resumo Executivo - Edital {$numero}");
$mpdf->SetAuthor('Sunyata Consulting');
$mpdf->WriteHTML($pdfHtml);

$filename = "resumo-executivo-edital-{$id}.pdf";
$dest = isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE;
$mpdf->Output($filename, $dest);
