<?php

namespace Sunyata\Core;

/**
 * MarkdownLogger - Sistema de logging em Markdown
 *
 * Gera logs formatados em Markdown para visualização no Docsify
 */
class MarkdownLogger {
    private static $instance = null;
    private $logDir;
    private $enabled = true;

    private function __construct() {
        // Diretório de logs (relativo ao projeto)
        $this->logDir = __DIR__ . '/../../public/comm/logs';
        $this->ensureDirectoriesExist();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function ensureDirectoriesExist(): void {
        $dirs = [
            $this->logDir,
            $this->logDir . '/api',
            $this->logDir . '/errors',
            $this->logDir . '/debug'
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    public function log(string $level, string $message, string $category = 'general', array $context = []): void {
        if (!$this->enabled) {
            return;
        }

        try {
            $timestamp = date('Y-m-d H:i:s');
            $date = date('Y-m-d');

            // Arquivo principal
            $mainFile = $this->logDir . '/' . $date . '.md';

            // Arquivo por categoria
            $categoryFile = null;
            if ($category !== 'general' && in_array($category, ['api', 'errors', 'debug'])) {
                $categoryFile = $this->logDir . '/' . $category . '/' . $date . '.md';
            }

            // Formatar entrada
            $entry = $this->formatLogEntry($timestamp, $level, $message, $context);

            // Escrever
            $this->appendToFile($mainFile, $entry, $date);
            if ($categoryFile) {
                $this->appendToFile($categoryFile, $entry, $date);
            }

            // Backup no error_log
            error_log("[$level] $message");

        } catch (\Exception $e) {
            error_log("MarkdownLogger error: " . $e->getMessage());
        }
    }

    private function formatLogEntry(string $timestamp, string $level, string $message, array $context): string {
        $icons = [
            'ERROR' => '🔴',
            'WARNING' => '🟡',
            'INFO' => '🔵',
            'DEBUG' => '⚪'
        ];
        $icon = $icons[$level] ?? '⚫';

        $entry = "\n### {$icon} {$level} - {$timestamp}\n\n";
        $entry .= "**Mensagem:** {$message}\n\n";

        if (!empty($context)) {
            $context = $this->sanitizeContext($context);
            $entry .= "**Contexto:**\n```json\n";
            $entry .= json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $entry .= "\n```\n";
        }

        $entry .= "\n---\n";
        return $entry;
    }

    private function sanitizeContext(array $context): array {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'csrf_token'];

        foreach ($context as $key => $value) {
            if (in_array(strtolower($key), $sensitive)) {
                $context[$key] = '[REDACTED]';
            }
        }

        return $context;
    }

    private function appendToFile(string $filepath, string $entry, string $date): void {
        if (!file_exists($filepath)) {
            $category = basename(dirname($filepath));
            $categoryName = $category === 'logs' ? 'Geral' : ucfirst($category);

            $header = "# 📊 Logs - {$categoryName} - {$date}\n\n";
            $header .= "**Gerado automaticamente**\n\n---\n";

            @file_put_contents($filepath, $header);
        }

        @file_put_contents($filepath, $entry, FILE_APPEND);
    }

    // Helper methods
    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, 'errors', $context);
    }

    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, 'general', $context);
    }

    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, 'general', $context);
    }

    public function debug(string $message, array $context = []): void {
        $this->log('DEBUG', $message, 'debug', $context);
    }

    public function api(string $endpoint, string $method, int $statusCode, float $duration, array $context = []): void {
        $message = "{$method} {$endpoint} → {$statusCode} ({$duration}ms)";

        $context['duration_ms'] = $duration;
        $context['status_code'] = $statusCode;
        $context['method'] = $method;

        $this->log('INFO', $message, 'api', $context);
    }

    /**
     * Log de chamada à API Claude (com custo)
     */
    public function claudeApiCall(
        int $userId,
        string $canvas,
        int $inputTokens,
        int $outputTokens,
        float $costUsd,
        float $responseTime,
        string $status = 'success',
        array $extraContext = []
    ): void {
        $totalTokens = $inputTokens + $outputTokens;
        $message = "Claude API: {$canvas} | User:{$userId} | Tokens:{$totalTokens} | Cost:\${$costUsd} | {$responseTime}s | {$status}";

        $context = array_merge([
            'user_id' => $userId,
            'canvas' => $canvas,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $costUsd,
            'response_time_s' => $responseTime,
            'status' => $status
        ], $extraContext);

        $this->log('INFO', $message, 'api', $context);

        // Também gravar em formato tabela para fácil visualização
        $this->appendToTableLog('claude-api-calls', [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'canvas' => $canvas,
            'in_tokens' => $inputTokens,
            'out_tokens' => $outputTokens,
            'cost_usd' => number_format($costUsd, 4),
            'time_s' => number_format($responseTime, 2),
            'status' => $status
        ]);
    }

    /**
     * Log de acesso (login, uploads, etc)
     */
    public function access(
        int $userId,
        string $action,
        string $resource = '-',
        string $status = 'success',
        ?string $ip = null,
        array $extraContext = []
    ): void {
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $message = "ACCESS: {$action} | User:{$userId} | Resource:{$resource} | {$status} | IP:{$ip}";

        $context = array_merge([
            'user_id' => $userId,
            'action' => $action,
            'resource' => $resource,
            'status' => $status,
            'ip_address' => $ip
        ], $extraContext);

        $this->log('INFO', $message, 'access', $context);

        // Tabela de acesso
        $this->appendToTableLog('access', [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'action' => $action,
            'resource' => $resource,
            'status' => $status,
            'ip' => $ip
        ]);
    }

    /**
     * Log de erro estruturado
     */
    public function errorWithContext(
        string $errorMessage,
        string $file,
        int $line,
        ?string $stackTrace = null,
        array $context = []
    ): void {
        $message = "{$errorMessage} | {$file}:{$line}";

        $context['file'] = $file;
        $context['line'] = $line;
        if ($stackTrace) {
            $context['stack_trace'] = $stackTrace;
        }

        $this->log('ERROR', $message, 'errors', $context);
    }

    /**
     * Helper para criar diretório de logs adicionais
     */
    private function ensureLogCategory(string $category): void {
        $dir = $this->logDir . '/' . $category;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Adicionar entrada em formato tabela (para logs tipo access, api-calls)
     */
    private function appendToTableLog(string $category, array $row): void {
        try {
            $this->ensureLogCategory($category);

            $date = date('Y-m-d');
            $filepath = $this->logDir . '/' . $category . '/' . $date . '.md';

            // Se arquivo não existe, criar com cabeçalho da tabela
            if (!file_exists($filepath)) {
                $categoryName = ucwords(str_replace('-', ' ', $category));
                $header = "# 📊 {$categoryName} - {$date}\n\n";
                $header .= "**Gerado automaticamente**\n\n";

                // Cabeçalho da tabela baseado nas keys do primeiro row
                $columns = array_keys($row);
                $header .= "| " . implode(" | ", array_map('ucfirst', $columns)) . " |\n";
                $header .= "|" . str_repeat("---|", count($columns)) . "\n";

                @file_put_contents($filepath, $header);
            }

            // Adicionar linha da tabela
            $values = array_values($row);
            $line = "| " . implode(" | ", $values) . " |\n";

            @file_put_contents($filepath, $line, FILE_APPEND);

        } catch (\Exception $e) {
            error_log("MarkdownLogger::appendToTableLog error: " . $e->getMessage());
        }
    }

    public function setEnabled(bool $enabled): void {
        $this->enabled = $enabled;
    }
}
