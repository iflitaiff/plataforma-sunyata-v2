<?php
/**
 * Helper: System Events
 *
 * Funções para registro de eventos centralizados no sistema e geração de Trace ID.
 * Os eventos são gravados na tabela `system_events` (PostgreSQL).
 */

use Sunyata\Core\Database;

/**
 * Gera um UUID v4 seguro para ser usado como trace_id na correlação de eventos.
 *
 * @return string UUID v4 (ex: "f47ac10b-58cc-4372-a567-0e02b2c3d479")
 */
function generate_trace_id(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Registra evento no banco de dados (tabela system_events).
 * Esta função nunca lança exceções para evitar a interrupção do fluxo principal.
 *
 * @param string      $eventType   Ex: 'iatr.analysis.requested'
 * @param string      $source      Origem do evento. Padrão: 'portal'
 * @param string      $severity    'debug', 'info', 'warning', 'error'. Padrão: 'info'
 * @param string|null $entityType  Ex: 'edital'
 * @param string|null $entityId    Ex: '148'
 * @param string|null $summary     Descrição curta do evento
 * @param array|null  $payload     Dados adicionais (será gravado como JSONB)
 * @param string|null $traceId     UUID de correlação (para agrupar logs da mesma transação)
 * @param int|null    $durationMs  Duração da operação em milissegundos
 * @return void
 */
function log_event(
    string  $eventType,
    string  $source = 'portal',
    string  $severity = 'info',
    ?string $entityType = null,
    ?string $entityId = null,
    ?string $summary = null,
    ?array  $payload = null,
    ?string $traceId = null,
    ?int    $durationMs = null
): void {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $stmt = $pdo->prepare("
            INSERT INTO system_events
                (trace_id, source, event_type, severity, entity_type, entity_id, summary, payload, duration_ms)
            VALUES
                (:trace_id, :source, :event_type, :severity, :entity_type, :entity_id, :summary, :payload, :duration_ms)
        ");
        
        $stmt->execute([
            ':trace_id'    => $traceId,
            ':source'      => $source,
            ':event_type'  => $eventType,
            ':severity'    => $severity,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':summary'     => $summary,
            ':payload'     => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ':duration_ms' => $durationMs,
        ]);
    } catch (\Throwable $e) {
        // Fallback silencioso: erro no log não deve quebrar a execução da regra de negócio
        error_log("system_events write failed: " . $e->getMessage() . " | Event: " . $eventType);
    }
}
