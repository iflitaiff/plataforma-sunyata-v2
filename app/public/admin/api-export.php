<?php
/**
 * Admin API Export - CSV Export for API Usage Stats
 * Exports prompt_history data for analysis
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    http_response_code(403);
    die('Acesso negado. Área restrita a administradores.');
}

$db = Database::getInstance();

// Parâmetros
$month = $_GET['month'] ?? 'current'; // 'current', 'last', ou 'YYYY-MM'

// Determinar range de datas
if ($month === 'current') {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-t');
    $monthLabel = date('Y-m');
} elseif ($month === 'last') {
    $startDate = date('Y-m-01', strtotime('first day of last month'));
    $endDate = date('Y-m-t', strtotime('last day of last month'));
    $monthLabel = date('Y-m', strtotime('first day of last month'));
} else {
    // Formato YYYY-MM
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
    $monthLabel = $month;
}

// Buscar dados
try {
    $data = $db->fetchAll("
        SELECT
            ph.id,
            ph.created_at,
            ph.created_at::date as date,
            ph.created_at::time as time,
            u.name as user_name,
            u.email as user_email,
            ph.vertical,
            ph.tool_name,
            ph.claude_model,
            ph.tokens_input,
            ph.tokens_output,
            ph.tokens_total,
            ph.cost_usd,
            ph.response_time_ms,
            ph.status,
            ph.error_message
        FROM prompt_history ph
        LEFT JOIN users u ON ph.user_id = u.id
        WHERE ph.created_at::date >= :start_date
        AND ph.created_at::date <= :end_date
        ORDER BY ph.created_at DESC
    ", [
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
} catch (Exception $e) {
    http_response_code(500);
    die('Erro ao buscar dados: ' . $e->getMessage());
}

// Configurar headers para download CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="api-usage-' . $monthLabel . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Abrir output
$output = fopen('php://output', 'w');

// BOM para UTF-8 (para Excel reconhecer)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers do CSV
fputcsv($output, [
    'ID',
    'Data',
    'Hora',
    'Usuario',
    'Email',
    'Vertical',
    'Ferramenta',
    'Modelo Claude',
    'Tokens Input',
    'Tokens Output',
    'Tokens Total',
    'Custo USD',
    'Tempo Resposta (ms)',
    'Status',
    'Erro'
]);

// Dados
foreach ($data as $row) {
    fputcsv($output, [
        $row['id'],
        $row['date'],
        $row['time'],
        $row['user_name'] ?? 'Desconhecido',
        $row['user_email'] ?? '',
        $row['vertical'],
        $row['tool_name'],
        $row['claude_model'] ?? 'Unknown',
        $row['tokens_input'] ?? 0,
        $row['tokens_output'] ?? 0,
        $row['tokens_total'] ?? 0,
        $row['cost_usd'] ?? 0,
        $row['response_time_ms'] ?? 0,
        $row['status'],
        $row['error_message'] ?? ''
    ]);
}

// Adicionar sumário no final
fputcsv($output, []); // Linha em branco
fputcsv($output, ['SUMÁRIO DO PERÍODO']);
fputcsv($output, ['Período:', $startDate . ' a ' . $endDate]);
fputcsv($output, ['Total de Registros:', count($data)]);

// Calcular totais
$totalCost = array_sum(array_column($data, 'cost_usd'));
$totalTokens = array_sum(array_column($data, 'tokens_total'));
$totalTokensInput = array_sum(array_column($data, 'tokens_input'));
$totalTokensOutput = array_sum(array_column($data, 'tokens_output'));
$successCount = count(array_filter($data, fn($r) => $r['status'] === 'success'));
$errorCount = count(array_filter($data, fn($r) => $r['status'] === 'error'));

fputcsv($output, ['Total Custo USD:', number_format($totalCost, 6)]);
fputcsv($output, ['Total Tokens:', $totalTokens]);
fputcsv($output, ['Tokens Input:', $totalTokensInput]);
fputcsv($output, ['Tokens Output:', $totalTokensOutput]);
fputcsv($output, ['Prompts com Sucesso:', $successCount]);
fputcsv($output, ['Prompts com Erro:', $errorCount]);
fputcsv($output, ['Taxa de Erro (%):', count($data) > 0 ? number_format(($errorCount / count($data)) * 100, 2) : 0]);

fclose($output);
exit;
