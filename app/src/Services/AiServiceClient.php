<?php
/**
 * AI Service Client — HTTP client for PHP → FastAPI communication.
 *
 * Handles both synchronous generation and SSE stream URL creation.
 * Uses internal API key for authentication.
 *
 * @package Sunyata\Services
 */

namespace Sunyata\Services;

use Sunyata\Core\Settings;
use Exception;

class AiServiceClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            defined('AI_SERVICE_URL') ? AI_SERVICE_URL : 'http://127.0.0.1:8000',
            '/'
        );
        $this->apiKey = defined('AI_SERVICE_INTERNAL_KEY') ? AI_SERVICE_INTERNAL_KEY : '';
        $this->timeout = 300; // 5 minutes
    }

    /**
     * Send a synchronous generation request to FastAPI.
     *
     * @param array $params Keys: model, system, prompt, max_tokens, temperature, top_p, user_id, vertical, tool_name
     * @return array Same shape as ClaudeService::generate() response
     */
    public function generate(array $params): array
    {
        $response = $this->post('/api/ai/generate', $params);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'] ?? 'AI service error',
                'error_detail' => $response['error'] ?? '',
            ];
        }

        return [
            'success' => true,
            'response' => $response['response'] ?? '',
            'model' => $response['model'] ?? '',
            'tokens' => [
                'input' => $response['usage']['input_tokens'] ?? 0,
                'output' => $response['usage']['output_tokens'] ?? 0,
                'total' => $response['usage']['total_tokens'] ?? 0,
            ],
            'cost_usd' => $response['cost_usd'] ?? 0.0,
            'response_time_ms' => $response['response_time_ms'] ?? 0,
        ];
    }

    /**
     * Build a stream URL for the browser to connect to directly via EventSource.
     * PHP validates the request, then returns this URL for the frontend.
     *
     * @param array $params Keys: model, system, prompt, max_tokens, temperature, top_p
     * @return string The full SSE stream URL
     */
    public function getStreamUrl(array $params): string
    {
        // The stream endpoint is POST, so the browser needs a session-based approach.
        // We POST to create a stream session, FastAPI returns a stream ID.
        // For now, the browser POSTs directly to /api/ai/stream with X-Internal-Key.
        // In production, consider a session token approach.
        return $this->baseUrl . '/api/ai/stream';
    }

    /**
     * Get the internal API key for the browser to use in stream requests.
     * In production, replace with short-lived session tokens.
     */
    public function getInternalKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Process a document via FastAPI.
     *
     * @param array $params Keys: file_path, file_content_base64, filename, mime_type
     * @return array
     */
    public function processDocument(array $params): array
    {
        return $this->post('/api/ai/process-document', $params);
    }

    /**
     * Check FastAPI health.
     */
    public function health(): array
    {
        return $this->get('/api/ai/health');
    }

    private function post(string $path, array $data): array
    {
        return $this->request('POST', $path, $data);
    }

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function request(string $method, string $path, ?array $data = null): array
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Internal-Key: ' . $this->apiKey,
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => "AI service connection failed: {$curlError}",
            ];
        }

        $decoded = json_decode($response, true);
        if (!$decoded) {
            return [
                'success' => false,
                'error' => "AI service returned invalid JSON (HTTP {$httpCode})",
            ];
        }

        return $decoded;
    }
}
