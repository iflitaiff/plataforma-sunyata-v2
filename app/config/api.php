<?php
/**
 * Configuração de APIs Externas
 *
 * Centraliza configurações para FastAPI microservice e outras APIs.
 *
 * @package Sunyata\Config
 * @author Claude Code
 * @version 1.0.0
 */

return [
    /**
     * FastAPI Microservice (Python)
     *
     * Microserviço para chamadas Claude via LiteLLM
     */
    'fastapi' => [
        // Base URL do serviço FastAPI
        'base_url' => getenv('FASTAPI_BASE_URL') ?: 'http://127.0.0.1:8000',

        // Feature flag: habilita uso do FastAPI em vez de chamada direta
        // Mudar para true após testes bem-sucedidos
        'enabled' => getenv('FASTAPI_ENABLED') === 'true' ? true : false,

        // Timeout para requisições HTTP (segundos)
        'timeout' => 300, // 5 minutos (alinhado com ClaudeService timeout)

        // Timeout de conexão (segundos)
        'connect_timeout' => 30,

        // Endpoints disponíveis
        'endpoints' => [
            'generate' => '/api/generate',
            'stream' => '/api/stream',
            'document' => '/api/document/process',
        ],

        // Retry strategy (opcional, futuro)
        'retry' => [
            'max_attempts' => 3,
            'delay_ms' => 1000, // 1 segundo entre tentativas
        ],
    ],

    /**
     * LiteLLM Config
     *
     * Usado para chamadas diretas ao LiteLLM se necessário
     */
    'litellm' => [
        'base_url' => getenv('LITELLM_BASE_URL') ?: 'http://192.168.100.13:4000',
        'api_key' => getenv('LITELLM_API_KEY') ?: '',
    ],
];
