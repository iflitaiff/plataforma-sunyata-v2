<?php
/**
 * PNCP Analysis Email Sender
 *
 * POST /api/pncp/email-analise.php
 * Body: {"edital_id": N, "to": "email@example.com"}
 *
 * Generates PDF server-side, sends to N8N webhook for email delivery.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (!$csrfHeader || $csrfHeader !== $csrfSession) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$editalId = filter_var($input['edital_id'] ?? null, FILTER_VALIDATE_INT);
$emailTo = filter_var($input['to'] ?? null, FILTER_VALIDATE_EMAIL);

if (!$editalId || !$emailTo) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos']);
    exit;
}

use Sunyata\Core\Database;

$db = Database::getInstance();
$edital = $db->fetchOne("SELECT * FROM pncp_editais WHERE id = ?", [$editalId]);

if (!$edital || $edital['status_analise'] !== 'concluida') {
    http_response_code(404);
    echo json_encode(['error' => 'Análise não encontrada ou não concluída']);
    exit;
}

// Generate PDF in memory
$resultado = json_decode($edital['analise_resultado'], true);
$markdown = $resultado['resumo_executivo'] ?? $resultado['texto'] ?? '';
$parsedown = new Parsedown();
$parsedown->setSafeMode(true);
$htmlContent = $parsedown->text($markdown);

$orgao = htmlspecialchars($edital['orgao'] ?? 'N/I');
$numero = htmlspecialchars($edital['numero'] ?? $edital['pncp_id'] ?? 'N/I');
$uf = htmlspecialchars($edital['uf'] ?? '');
$municipio = htmlspecialchars($edital['municipio'] ?? '');

$pdfHtml = "<html><head><style>"
    . "body{font-family:'DejaVu Sans',Arial,sans-serif;font-size:11px;color:#333;line-height:1.6}"
    . ".header{background:#1a2a3a;color:#fff;padding:20px;margin:-20px -20px 20px -20px}"
    . ".header h1{margin:0;font-size:18px}.header p{margin:5px 0 0;opacity:.8;font-size:10px}"
    . "table{width:100%;border-collapse:collapse;margin:10px 0}"
    . "th,td{border:1px solid #dee2e6;padding:6px 10px;text-align:left;font-size:10px}"
    . "th{background:#f1f3f5;font-weight:600}"
    . "h1{font-size:16px;color:#1a2a3a;border-bottom:2px solid #3498db;padding-bottom:5px}"
    . "h2{font-size:13px;color:#2c3e50}h3{font-size:11px;color:#34495e}"
    . "hr{border:none;border-top:1px solid #dee2e6;margin:15px 0}"
    . ".footer{margin-top:30px;padding-top:10px;border-top:1px solid #dee2e6;font-size:8px;color:#999;text-align:center}"
    . "</style></head><body>"
    . "<div class='header'><h1>Resumo Executivo — Edital {$numero}</h1>"
    . "<p>{$orgao} — {$municipio}/{$uf}</p></div>"
    . $htmlContent
    . "<div class='footer'>Gerado por Sunyata Consulting — IATR Monitoramento PNCP<br>"
    . "Análise gerada por IA. Verificar dados antes de decisões.</div>"
    . "</body></html>";

$mpdf = new \Mpdf\Mpdf([
    'margin_top' => 25, 'margin_bottom' => 20,
    'margin_left' => 15, 'margin_right' => 15,
    'default_font' => 'dejavusans',
]);
$mpdf->WriteHTML($pdfHtml);
$pdfContent = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
$pdfBase64 = base64_encode($pdfContent);
$attachmentName = "resumo-executivo-edital-{$editalId}.pdf";

$emailHtml = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>"
    . "<h2 style='color:#1a2a3a'>Resumo Executivo — Edital {$numero}</h2>"
    . "<p><strong>Órgão:</strong> {$orgao} — {$municipio}/{$uf}</p>"
    . "<p>Segue em anexo o resumo executivo gerado por IA para o edital acima.</p>"
    . "<p style='font-size:12px;color:#999;margin-top:30px'>Gerado por Sunyata Consulting — IATR Monitoramento PNCP</p>"
    . "</div>";

// Send to N8N webhook (internal network)
$webhookBase = defined('N8N_WEBHOOK_INTERNAL_URL')
    ? N8N_WEBHOOK_INTERNAL_URL
    : 'http://192.168.100.14:5678';
$webhookToken = defined('N8N_WEBHOOK_AUTH_TOKEN') ? N8N_WEBHOOK_AUTH_TOKEN : '';
$webhookUrl = rtrim($webhookBase, '/') . '/webhook/portal/send-email';

$payload = json_encode([
    'to' => $emailTo,
    'subject' => "Resumo Executivo — Edital {$numero} — " . ($edital['orgao'] ?? ''),
    'html' => $emailHtml,
    'attachment_name' => $attachmentName,
    'attachment_base64' => $pdfBase64,
]);

$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Auth-Token: ' . $webhookToken,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Erro ao contactar serviço de email: ' . $curlError]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'Serviço de email retornou HTTP ' . $httpCode]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Email enviado para ' . $emailTo]);
