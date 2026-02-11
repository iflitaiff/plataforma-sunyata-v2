CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    google_id VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    picture VARCHAR(500),
    access_level ENUM('guest', 'student', 'client', 'admin') DEFAULT 'guest',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_google_id (google_id),
    INDEX idx_access_level (access_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contracts table
CREATE TABLE contracts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('course', 'consulting', 'subscription') NOT NULL,
    vertical ENUM('sales', 'marketing', 'customer_service', 'hr', 'general') NOT NULL,
    status ENUM('active', 'inactive', 'suspended', 'expired') DEFAULT 'active',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_type (type),
    INDEX idx_vertical (vertical)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LGPD Consents table
CREATE TABLE consents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    consent_type ENUM('terms_of_use', 'privacy_policy', 'data_processing', 'marketing') NOT NULL,
    consent_given BOOLEAN NOT NULL DEFAULT FALSE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    consent_text TEXT,
    consent_version VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_consent_type (consent_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit logs for LGPD compliance
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(255) NOT NULL,
    entity_type VARCHAR(100),
    entity_id INT UNSIGNED,
    ip_address VARCHAR(45),
    user_agent TEXT,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data retention requests (LGPD Article 18)
CREATE TABLE data_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    request_type ENUM('access', 'deletion', 'portability', 'correction') NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'rejected') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    processed_by INT UNSIGNED NULL,
    notes TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table for security
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prompt Dictionary (MVP feature)
CREATE TABLE prompt_dictionary (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vertical ENUM('sales', 'marketing', 'customer_service', 'hr', 'general') NOT NULL,
    category VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    prompt_text TEXT NOT NULL,
    description TEXT,
    tags JSON,
    use_cases TEXT,
    access_level ENUM('free', 'student', 'client', 'premium') DEFAULT 'free',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_vertical (vertical),
    INDEX idx_category (category),
    INDEX idx_access_level (access_level),
    FULLTEXT idx_search (title, description, prompt_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial admin user (update with actual Google ID after first login)
-- INSERT INTO users (google_id, email, name, access_level)
-- VALUES ('YOUR_GOOGLE_ID', 'admin@sunyataconsulting.com', 'Admin', 'admin');

-- Sample prompt entries
INSERT INTO prompt_dictionary (vertical, category, title, prompt_text, description, access_level) VALUES
('sales', 'prospecting', 'Email de Prospecção B2B', 'Escreva um email de prospecção profissional para [CARGO] na empresa [EMPRESA] sobre [PRODUTO/SERVIÇO]. Tom: [formal/casual]. Inclua: proposta de valor clara, call-to-action e personalização.', 'Template para primeiro contato com prospects B2B', 'free'),
('marketing', 'content', 'Post LinkedIn Engajamento', 'Crie um post para LinkedIn sobre [TEMA] que gere engajamento. Público-alvo: [PERSONA]. Formato: storytelling com gancho inicial, desenvolvimento e CTA. Máximo 1300 caracteres.', 'Estrutura para posts que convertem no LinkedIn', 'free'),
('customer_service', 'resolution', 'Resposta a Reclamação', 'Elabore resposta empática para cliente insatisfeito com [PROBLEMA]. Inclua: reconhecimento da frustração, explicação clara, solução proposta e compensação se aplicável. Tom profissional e humanizado.', 'Framework para transformar reclamações em oportunidades', 'student'),
('hr', 'recruitment', 'Job Description Atrativa', 'Crie descrição de vaga para [CARGO] que atraia talentos de alta performance. Inclua: desafios do cargo, impacto no negócio, perfil ideal (competências técnicas e comportamentais), benefícios e cultura.', 'Template para anúncios de vagas que se destacam', 'student'),
('general', 'productivity', 'Resumo Executivo', 'Resuma o seguinte conteúdo em formato executivo: [TEXTO]. Estrutura: síntese em 3 bullet points, principais insights, ações recomendadas. Máximo 200 palavras.', 'Condensar informações longas em resumos acionáveis', 'free');
