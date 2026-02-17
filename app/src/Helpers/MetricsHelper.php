<?php
/**
 * MetricsHelper - Agregação de métricas para dashboard de monitoramento
 *
 * Fornece dados de prompt_history para visualização de:
 * - Volume de requisições
 * - Performance (response times)
 * - Custos e tokens
 * - Taxa de sucesso/erro
 * - Distribuição por vertical e modelo
 *
 * @package Sunyata\Helpers
 */

namespace Sunyata\Helpers;

use Sunyata\Core\Database;

class MetricsHelper
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Overview geral do sistema (últimas 24h vs total)
     */
    public function getOverview(): array
    {
        // Last 24h
        $last24h = $this->db->fetchOne("
            SELECT
                COUNT(*) as total_requests,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful,
                COUNT(CASE WHEN status = 'error' OR error_message IS NOT NULL THEN 1 END) as failed,
                AVG(response_time_ms) as avg_response_ms,
                SUM(tokens_total) as total_tokens,
                SUM(cost_usd) as total_cost
            FROM prompt_history
            WHERE created_at > NOW() - INTERVAL '24 hours'
        ");

        // All time
        $allTime = $this->db->fetchOne("
            SELECT
                COUNT(*) as total_requests,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful,
                MIN(created_at) as first_request
            FROM prompt_history
        ");

        return [
            'last_24h' => [
                'requests' => (int)($last24h['total_requests'] ?? 0),
                'successful' => (int)($last24h['successful'] ?? 0),
                'failed' => (int)($last24h['failed'] ?? 0),
                'success_rate' => $last24h['total_requests'] > 0
                    ? round(($last24h['successful'] / $last24h['total_requests']) * 100, 1)
                    : 0,
                'avg_response_ms' => round($last24h['avg_response_ms'] ?? 0),
                'total_tokens' => (int)($last24h['total_tokens'] ?? 0),
                'total_cost_usd' => round($last24h['total_cost'] ?? 0, 4),
            ],
            'all_time' => [
                'requests' => (int)($allTime['total_requests'] ?? 0),
                'successful' => (int)($allTime['successful'] ?? 0),
                'first_request' => $allTime['first_request'] ?? null,
            ],
        ];
    }

    /**
     * Série temporal de requisições (últimos N dias)
     */
    public function getRequestTimeSeries(int $days = 7): array
    {
        // Input validation - prevent negative or unreasonable values
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 365) {
            $days = 365; // Cap at 1 year
        }

        $data = $this->db->fetchAll("
            SELECT
                DATE(created_at) as date,
                COUNT(*) as requests,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful,
                COUNT(CASE WHEN status = 'error' OR error_message IS NOT NULL THEN 1 END) as failed,
                AVG(response_time_ms) as avg_response_ms,
                SUM(tokens_total) as total_tokens
            FROM prompt_history
            WHERE created_at > NOW() - INTERVAL :interval
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", ['interval' => $days . ' days']);

        return array_map(function ($row) {
            return [
                'date' => $row['date'],
                'requests' => (int)$row['requests'],
                'successful' => (int)$row['successful'],
                'failed' => (int)$row['failed'],
                'avg_response_ms' => round($row['avg_response_ms'] ?? 0),
                'total_tokens' => (int)($row['total_tokens'] ?? 0),
            ];
        }, $data);
    }

    /**
     * Distribuição por vertical (últimas 24h)
     */
    public function getByVertical(): array
    {
        $data = $this->db->fetchAll("
            SELECT
                vertical,
                COUNT(*) as requests,
                AVG(tokens_total) as avg_tokens,
                SUM(tokens_total) as total_tokens,
                SUM(cost_usd) as total_cost,
                AVG(response_time_ms) as avg_response_ms
            FROM prompt_history
            WHERE created_at > NOW() - INTERVAL '24 hours'
            GROUP BY vertical
            ORDER BY requests DESC
        ");

        return array_map(function ($row) {
            return [
                'vertical' => $row['vertical'],
                'requests' => (int)$row['requests'],
                'avg_tokens' => round($row['avg_tokens'] ?? 0),
                'total_tokens' => (int)($row['total_tokens'] ?? 0),
                'total_cost_usd' => round($row['total_cost'] ?? 0, 4),
                'avg_response_ms' => round($row['avg_response_ms'] ?? 0),
            ];
        }, $data);
    }

    /**
     * Distribuição por modelo (últimas 24h)
     */
    public function getByModel(): array
    {
        $data = $this->db->fetchAll("
            SELECT
                COALESCE(claude_model, 'unknown') as model,
                COUNT(*) as requests,
                AVG(tokens_total) as avg_tokens,
                SUM(tokens_total) as total_tokens,
                SUM(cost_usd) as total_cost
            FROM prompt_history
            WHERE created_at > NOW() - INTERVAL '24 hours'
            GROUP BY claude_model
            ORDER BY requests DESC
        ");

        return array_map(function ($row) {
            return [
                'model' => $row['model'],
                'requests' => (int)$row['requests'],
                'avg_tokens' => round($row['avg_tokens'] ?? 0),
                'total_tokens' => (int)($row['total_tokens'] ?? 0),
                'total_cost_usd' => round($row['total_cost'] ?? 0, 4),
            ];
        }, $data);
    }

    /**
     * Percentis de response time (P50, P95, P99)
     */
    public function getResponseTimePercentiles(): array
    {
        $result = $this->db->fetchOne("
            SELECT
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY response_time_ms) as p50,
                PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms) as p95,
                PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY response_time_ms) as p99,
                MIN(response_time_ms) as min,
                MAX(response_time_ms) as max,
                AVG(response_time_ms) as avg
            FROM prompt_history
            WHERE created_at > NOW() - INTERVAL '24 hours'
            AND response_time_ms IS NOT NULL
        ");

        return [
            'p50' => round($result['p50'] ?? 0),
            'p95' => round($result['p95'] ?? 0),
            'p99' => round($result['p99'] ?? 0),
            'min' => round($result['min'] ?? 0),
            'max' => round($result['max'] ?? 0),
            'avg' => round($result['avg'] ?? 0),
        ];
    }

    /**
     * Top 10 erros recentes
     */
    public function getRecentErrors(int $limit = 10): array
    {
        // Input validation - prevent negative or unreasonable values
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100; // Cap at 100
        }

        $data = $this->db->fetchAll("
            SELECT
                vertical,
                tool_name,
                error_message,
                created_at
            FROM prompt_history
            WHERE error_message IS NOT NULL
            ORDER BY created_at DESC
            LIMIT :limit
        ", ['limit' => $limit]);

        return array_map(function ($row) {
            return [
                'vertical' => $row['vertical'],
                'tool_name' => $row['tool_name'],
                'error' => substr($row['error_message'], 0, 200), // Truncate
                'timestamp' => $row['created_at'],
            ];
        }, $data);
    }

    /**
     * Custo acumulado por dia (últimos N dias)
     */
    public function getCostTimeSeries(int $days = 7): array
    {
        // Input validation - prevent negative or unreasonable values
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 365) {
            $days = 365; // Cap at 1 year
        }

        $data = $this->db->fetchAll("
            SELECT
                DATE(created_at) as date,
                SUM(cost_usd) as daily_cost,
                SUM(tokens_total) as daily_tokens
            FROM prompt_history
            WHERE created_at > NOW() - INTERVAL :interval
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", ['interval' => $days . ' days']);

        return array_map(function ($row) {
            return [
                'date' => $row['date'],
                'cost_usd' => round($row['daily_cost'] ?? 0, 4),
                'tokens' => (int)($row['daily_tokens'] ?? 0),
            ];
        }, $data);
    }
}
