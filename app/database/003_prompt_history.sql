-- Migration 003: Prompt History System
-- Armazena histórico de prompts gerados e respostas Claude
-- Criado: 2025-10-20

CREATE TABLE IF NOT EXISTS prompt_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    vertical VARCHAR(50) NOT NULL,
    tool_name VARCHAR(100) NOT NULL COMMENT 'Nome da ferramenta (canvas_juridico, canvas_docente, etc)',

    -- Dados de entrada (formulário preenchido pelo usuário)
    input_data JSON NOT NULL COMMENT 'Campos preenchidos pelo usuário',

    -- Prompt gerado (OCULTO DO USUÁRIO)
    generated_prompt TEXT NOT NULL COMMENT 'Prompt final enviado para Claude API',

    -- Resposta da API Claude
    claude_response LONGTEXT NULL COMMENT 'Resposta completa da API Claude',
    claude_model VARCHAR(100) NULL COMMENT 'Modelo usado (claude-3-5-sonnet, etc)',
    tokens_input INT NULL COMMENT 'Tokens usados no input',
    tokens_output INT NULL COMMENT 'Tokens usados no output',
    tokens_total INT NULL COMMENT 'Total de tokens',
    cost_usd DECIMAL(10,6) NULL COMMENT 'Custo em USD (calculado)',

    -- Metadata
    response_time_ms INT NULL COMMENT 'Tempo de resposta em ms',
    status ENUM('pending', 'success', 'error') DEFAULT 'pending',
    error_message TEXT NULL,

    -- Auditoria
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_vertical (vertical),
    INDEX idx_tool_name (tool_name),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar comentário na tabela
ALTER TABLE prompt_history COMMENT = 'Histórico de prompts gerados e respostas Claude API';
