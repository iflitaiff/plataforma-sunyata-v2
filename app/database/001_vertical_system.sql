-- =====================================================
-- MIGRATION 001: Sistema de Verticais
-- Data: 2025-10-09
-- Descrição: Implementa sistema de verticais, onboarding
--            e controle de acesso por vertical
-- =====================================================

-- 1. Adicionar campo selected_vertical na tabela users
-- =====================================================
ALTER TABLE users
ADD COLUMN selected_vertical ENUM(
    'docencia',
    'pesquisa',
    'ifrj_alunos',
    'juridico',
    'vendas',
    'marketing',
    'licitacoes',
    'rh',
    'geral'
) NULL COMMENT 'Vertical escolhida pelo usuário',
ADD COLUMN completed_onboarding BOOLEAN DEFAULT FALSE COMMENT 'Se completou o onboarding',
ADD COLUMN is_demo BOOLEAN DEFAULT FALSE COMMENT 'Usuário demo (acessa todas verticais)',
ADD INDEX idx_selected_vertical (selected_vertical),
ADD INDEX idx_completed_onboarding (completed_onboarding);

-- 2. Criar tabela user_profiles (dados do onboarding)
-- =====================================================
CREATE TABLE IF NOT EXISTS user_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    phone VARCHAR(20) NULL COMMENT 'Telefone de contato',
    position VARCHAR(255) NULL COMMENT 'Cargo/Função',
    organization VARCHAR(255) NULL COMMENT 'Nome da organização/empresa',
    organization_size ENUM('pequena', 'media', 'grande') NULL COMMENT 'Tamanho da organização',
    area VARCHAR(255) NULL COMMENT 'Área de atuação',
    ifrj_level ENUM('ensino_medio', 'superior') NULL COMMENT 'Nível de ensino (apenas IFRJ)',
    ifrj_course VARCHAR(255) NULL COMMENT 'Nome do curso (apenas IFRJ)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_organization (organization)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Perfis estendidos dos usuários (dados de onboarding)';

-- 3. Criar tabela vertical_access_requests (solicitações de acesso)
-- =====================================================
CREATE TABLE IF NOT EXISTS vertical_access_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    vertical ENUM(
        'docencia',
        'pesquisa',
        'ifrj_alunos',
        'juridico',
        'vendas',
        'marketing',
        'licitacoes',
        'rh',
        'geral'
    ) NOT NULL COMMENT 'Vertical solicitada',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT 'Status da solicitação',
    request_data JSON NULL COMMENT 'Dados específicos da solicitação (OAB, escritório, etc)',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    processed_by INT UNSIGNED NULL COMMENT 'Admin que processou',
    notes TEXT NULL COMMENT 'Observações do admin',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_vertical (vertical),
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Solicitações de acesso a verticais (ex: Jurídico)';

-- 4. Criar tabela tool_access_logs (analytics de ferramentas)
-- =====================================================
CREATE TABLE IF NOT EXISTS tool_access_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tool_name VARCHAR(255) NOT NULL COMMENT 'Nome da ferramenta acessada',
    tool_path VARCHAR(500) NULL COMMENT 'Caminho completo do arquivo',
    vertical ENUM(
        'docencia',
        'pesquisa',
        'ifrj_alunos',
        'juridico',
        'vendas',
        'marketing',
        'licitacoes',
        'rh',
        'geral'
    ) NULL COMMENT 'Vertical do usuário no momento do acesso',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    session_duration INT UNSIGNED NULL COMMENT 'Duração da sessão em segundos (se rastreável)',
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_tool_name (tool_name),
    INDEX idx_vertical (vertical),
    INDEX idx_accessed_at (accessed_at),
    INDEX idx_user_tool (user_id, tool_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Log de acesso às ferramentas (analytics)';

-- 5. Criar tabela tool_versions (controle de versões de ferramentas)
-- =====================================================
CREATE TABLE IF NOT EXISTS tool_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tool_name VARCHAR(255) NOT NULL COMMENT 'Nome base da ferramenta (sem versão)',
    version VARCHAR(50) NOT NULL COMMENT 'Versão (v1, v2, etc)',
    file_path VARCHAR(500) NOT NULL COMMENT 'Caminho do arquivo HTML',
    is_active BOOLEAN DEFAULT FALSE COMMENT 'Se é a versão ativa',
    uploaded_by INT UNSIGNED NULL COMMENT 'Quem fez upload',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL COMMENT 'Notas sobre esta versão',
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_tool_version (tool_name, version),
    INDEX idx_tool_name (tool_name),
    INDEX idx_is_active (is_active),
    INDEX idx_uploaded_at (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Controle de versões das ferramentas HTML';

-- 6. Atualizar ENUM de contracts.vertical para novas verticais
-- =====================================================
ALTER TABLE contracts
MODIFY COLUMN vertical ENUM(
    'docencia',
    'pesquisa',
    'ifrj_alunos',
    'juridico',
    'vendas',
    'marketing',
    'licitacoes',
    'rh',
    'geral'
) NOT NULL COMMENT 'Vertical do contrato';

-- 7. Atualizar ENUM de prompt_dictionary.vertical para novas verticais
-- =====================================================
ALTER TABLE prompt_dictionary
MODIFY COLUMN vertical ENUM(
    'docencia',
    'pesquisa',
    'ifrj_alunos',
    'juridico',
    'vendas',
    'marketing',
    'licitacoes',
    'rh',
    'geral',
    'geral_todos'
) NOT NULL COMMENT 'Vertical do prompt (geral_todos = visível para todos)';

-- 8. Popular tool_versions com ferramentas existentes
-- =====================================================
INSERT INTO tool_versions (tool_name, version, file_path, is_active, notes) VALUES
('canvas-docente', 'v1', 'public/ferramentas/canvas-docente.html', TRUE, 'Versão inicial'),
('canvas-juridico', 'v1', 'public/ferramentas/canvas-juridico.html', TRUE, 'Versão inicial'),
('canvas-pesquisa', 'v1', 'public/ferramentas/canvas-pesquisa.html', TRUE, 'Versão inicial'),
('biblioteca-prompts-jogos', 'v1', 'public/ferramentas/biblioteca-prompts-jogos.html', TRUE, 'Versão inicial'),
('guia-prompts-jogos', 'v1', 'public/ferramentas/guia-prompts-jogos.html', TRUE, 'Versão inicial'),
('guia-prompts-juridico', 'v1', 'public/ferramentas/guia-prompts-juridico.html', TRUE, 'Versão inicial'),
('padroes-avancados-juridico', 'v1', 'public/ferramentas/padroes-avancados-juridico.html', TRUE, 'Versão inicial');

-- 9. Criar view para facilitar consultas de analytics
-- =====================================================
CREATE OR REPLACE VIEW v_tool_access_stats AS
SELECT
    t.tool_name,
    t.vertical,
    COUNT(*) as total_accesses,
    COUNT(DISTINCT t.user_id) as unique_users,
    DATE(t.accessed_at) as access_date,
    u.name as user_name,
    u.email as user_email
FROM tool_access_logs t
INNER JOIN users u ON t.user_id = u.id
GROUP BY t.tool_name, t.vertical, DATE(t.accessed_at), u.id;

-- 10. Comentários finais e validação
-- =====================================================
-- Verificar se todas as tabelas foram criadas
SELECT
    'Migration 001 completed!' as status,
    COUNT(*) as tables_count
FROM information_schema.tables
WHERE table_schema = DATABASE()
AND table_name IN (
    'user_profiles',
    'vertical_access_requests',
    'tool_access_logs',
    'tool_versions'
);
