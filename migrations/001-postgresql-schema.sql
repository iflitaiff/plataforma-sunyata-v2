-- ============================================================================
-- Plataforma Sunyata v2 — PostgreSQL Schema
-- Migration 001: Complete schema (clean rewrite, not a conversion)
-- Target: PostgreSQL 16 + pgvector
-- Date: 2026-02-11
-- ============================================================================

BEGIN;

-- Extensions
CREATE EXTENSION IF NOT EXISTS "vector";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- ============================================================================
-- Helper: auto-update updated_at trigger
-- ============================================================================
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ============================================================================
-- 1. users
-- ============================================================================
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    google_id VARCHAR(255) UNIQUE,          -- NULLABLE (v2: email/password auth too)
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    picture VARCHAR(500),
    password_hash VARCHAR(255),             -- NEW: for email/password auth
    access_level TEXT NOT NULL DEFAULT 'guest'
        CHECK (access_level IN ('guest', 'student', 'client', 'admin')),
    selected_vertical TEXT
        CHECK (selected_vertical IN (
            'docencia', 'pesquisa', 'ifrj_alunos', 'juridico',
            'vendas', 'marketing', 'licitacoes', 'rh', 'geral',
            'iatr', 'prompt-builder', 'nicolay-advogados', 'legal'
        )),
    completed_onboarding BOOLEAN DEFAULT FALSE,
    is_demo BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    last_login TIMESTAMPTZ
);

CREATE INDEX idx_users_email ON users (email);
CREATE INDEX idx_users_google_id ON users (google_id);
CREATE INDEX idx_users_access_level ON users (access_level);
CREATE INDEX idx_users_selected_vertical ON users (selected_vertical);
CREATE INDEX idx_users_completed_onboarding ON users (completed_onboarding);

CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 2. contracts
-- ============================================================================
CREATE TABLE contracts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type TEXT NOT NULL
        CHECK (type IN ('course', 'consulting', 'subscription')),
    vertical TEXT NOT NULL
        CHECK (vertical IN (
            'docencia', 'pesquisa', 'ifrj_alunos', 'juridico',
            'vendas', 'marketing', 'licitacoes', 'rh', 'geral',
            'iatr', 'prompt-builder', 'nicolay-advogados', 'legal'
        )),
    status TEXT DEFAULT 'active'
        CHECK (status IN ('active', 'inactive', 'suspended', 'expired')),
    start_date DATE NOT NULL,
    end_date DATE,
    metadata JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_contracts_user_id ON contracts (user_id);
CREATE INDEX idx_contracts_status ON contracts (status);
CREATE INDEX idx_contracts_type ON contracts (type);
CREATE INDEX idx_contracts_vertical ON contracts (vertical);

CREATE TRIGGER trg_contracts_updated_at
    BEFORE UPDATE ON contracts
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 3. consents (LGPD)
-- ============================================================================
CREATE TABLE consents (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    consent_type TEXT NOT NULL
        CHECK (consent_type IN ('terms_of_use', 'privacy_policy', 'data_processing', 'marketing')),
    consent_given BOOLEAN NOT NULL DEFAULT FALSE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    consent_text TEXT,
    consent_version VARCHAR(50),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    revoked_at TIMESTAMPTZ
);

CREATE INDEX idx_consents_user_id ON consents (user_id);
CREATE INDEX idx_consents_consent_type ON consents (consent_type);
CREATE INDEX idx_consents_created_at ON consents (created_at);

-- ============================================================================
-- 4. audit_logs
-- ============================================================================
CREATE TABLE audit_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(255) NOT NULL,
    entity_type VARCHAR(100),
    entity_id INTEGER,
    ip_address VARCHAR(45),
    user_agent TEXT,
    details JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_audit_logs_user_id ON audit_logs (user_id);
CREATE INDEX idx_audit_logs_action ON audit_logs (action);
CREATE INDEX idx_audit_logs_created_at ON audit_logs (created_at);
CREATE INDEX idx_audit_logs_entity ON audit_logs (entity_type, entity_id);
CREATE INDEX idx_audit_logs_details ON audit_logs USING GIN (details);

-- ============================================================================
-- 5. data_requests (LGPD Article 18)
-- ============================================================================
CREATE TABLE data_requests (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    request_type TEXT NOT NULL
        CHECK (request_type IN ('access', 'deletion', 'portability', 'correction')),
    status TEXT DEFAULT 'pending'
        CHECK (status IN ('pending', 'processing', 'completed', 'rejected')),
    requested_at TIMESTAMPTZ DEFAULT NOW(),
    processed_at TIMESTAMPTZ,
    processed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    notes TEXT
);

CREATE INDEX idx_data_requests_user_id ON data_requests (user_id);
CREATE INDEX idx_data_requests_status ON data_requests (status);
CREATE INDEX idx_data_requests_requested_at ON data_requests (requested_at);

-- ============================================================================
-- 6. sessions
-- ============================================================================
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_sessions_user_id ON sessions (user_id);
CREATE INDEX idx_sessions_last_activity ON sessions (last_activity);

-- ============================================================================
-- 7. prompt_dictionary
-- ============================================================================
CREATE TABLE prompt_dictionary (
    id SERIAL PRIMARY KEY,
    vertical TEXT NOT NULL
        CHECK (vertical IN (
            'docencia', 'pesquisa', 'ifrj_alunos', 'juridico',
            'vendas', 'marketing', 'licitacoes', 'rh', 'geral',
            'geral_todos', 'iatr', 'prompt-builder', 'nicolay-advogados', 'legal'
        )),
    category VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    prompt_text TEXT NOT NULL,
    description TEXT,
    tags JSONB,
    use_cases TEXT,
    access_level TEXT DEFAULT 'free'
        CHECK (access_level IN ('free', 'student', 'client', 'premium')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_prompt_dictionary_vertical ON prompt_dictionary (vertical);
CREATE INDEX idx_prompt_dictionary_category ON prompt_dictionary (category);
CREATE INDEX idx_prompt_dictionary_access_level ON prompt_dictionary (access_level);
CREATE INDEX idx_prompt_dictionary_tags ON prompt_dictionary USING GIN (tags);

-- Full-text search using tsvector + GIN (replaces MySQL FULLTEXT)
ALTER TABLE prompt_dictionary ADD COLUMN search_vector tsvector;

CREATE OR REPLACE FUNCTION prompt_dictionary_search_update()
RETURNS TRIGGER AS $$
BEGIN
    NEW.search_vector := to_tsvector('portuguese',
        COALESCE(NEW.title, '') || ' ' ||
        COALESCE(NEW.description, '') || ' ' ||
        COALESCE(NEW.prompt_text, '')
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prompt_dictionary_search
    BEFORE INSERT OR UPDATE ON prompt_dictionary
    FOR EACH ROW EXECUTE FUNCTION prompt_dictionary_search_update();

CREATE INDEX idx_prompt_dictionary_search ON prompt_dictionary USING GIN (search_vector);

CREATE TRIGGER trg_prompt_dictionary_updated_at
    BEFORE UPDATE ON prompt_dictionary
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 8. user_profiles (onboarding data)
-- ============================================================================
CREATE TABLE user_profiles (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    phone VARCHAR(20),
    position VARCHAR(255),
    organization VARCHAR(255),
    organization_size TEXT
        CHECK (organization_size IN ('pequena', 'media', 'grande')),
    area VARCHAR(255),
    ifrj_level TEXT
        CHECK (ifrj_level IN ('ensino_medio', 'superior')),
    ifrj_course VARCHAR(255),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_user_profiles_user_id ON user_profiles (user_id);
CREATE INDEX idx_user_profiles_organization ON user_profiles (organization);

CREATE TRIGGER trg_user_profiles_updated_at
    BEFORE UPDATE ON user_profiles
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 9. vertical_access_requests
-- ============================================================================
CREATE TABLE vertical_access_requests (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    vertical TEXT NOT NULL
        CHECK (vertical IN (
            'docencia', 'pesquisa', 'ifrj_alunos', 'juridico',
            'vendas', 'marketing', 'licitacoes', 'rh', 'geral',
            'iatr', 'prompt-builder', 'nicolay-advogados', 'legal'
        )),
    status TEXT DEFAULT 'pending'
        CHECK (status IN ('pending', 'approved', 'rejected')),
    request_data JSONB,
    requested_at TIMESTAMPTZ DEFAULT NOW(),
    processed_at TIMESTAMPTZ,
    processed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    notes TEXT
);

CREATE INDEX idx_var_user_id ON vertical_access_requests (user_id);
CREATE INDEX idx_var_vertical ON vertical_access_requests (vertical);
CREATE INDEX idx_var_status ON vertical_access_requests (status);
CREATE INDEX idx_var_requested_at ON vertical_access_requests (requested_at);

-- ============================================================================
-- 10. tool_access_logs (analytics)
-- ============================================================================
CREATE TABLE tool_access_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tool_name VARCHAR(255) NOT NULL,
    tool_slug VARCHAR(255),
    tool_path VARCHAR(500),
    vertical TEXT
        CHECK (vertical IS NULL OR vertical IN (
            'docencia', 'pesquisa', 'ifrj_alunos', 'juridico',
            'vendas', 'marketing', 'licitacoes', 'rh', 'geral',
            'iatr', 'prompt-builder', 'nicolay-advogados', 'legal'
        )),
    ip_address VARCHAR(45),
    user_agent TEXT,
    session_duration INTEGER,
    accessed_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_tal_user_id ON tool_access_logs (user_id);
CREATE INDEX idx_tal_tool_name ON tool_access_logs (tool_name);
CREATE INDEX idx_tal_tool_slug ON tool_access_logs (tool_slug);
CREATE INDEX idx_tal_vertical ON tool_access_logs (vertical);
CREATE INDEX idx_tal_accessed_at ON tool_access_logs (accessed_at);
CREATE INDEX idx_tal_user_tool ON tool_access_logs (user_id, tool_name);

-- ============================================================================
-- 11. tool_versions
-- ============================================================================
CREATE TABLE tool_versions (
    id SERIAL PRIMARY KEY,
    tool_name VARCHAR(255) NOT NULL,
    version VARCHAR(50) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    is_active BOOLEAN DEFAULT FALSE,
    uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    uploaded_at TIMESTAMPTZ DEFAULT NOW(),
    notes TEXT,
    UNIQUE (tool_name, version)
);

CREATE INDEX idx_tv_tool_name ON tool_versions (tool_name);
CREATE INDEX idx_tv_is_active ON tool_versions (is_active);

-- ============================================================================
-- 12. settings
-- ============================================================================
CREATE TABLE settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    data_type TEXT DEFAULT 'string'
        CHECK (data_type IN ('string', 'integer', 'boolean', 'json')),
    description VARCHAR(255),
    is_public BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_settings_setting_key ON settings (setting_key);
CREATE INDEX idx_settings_is_public ON settings (is_public);

CREATE TRIGGER trg_settings_updated_at
    BEFORE UPDATE ON settings
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 13. prompt_history (high-volume)
-- ============================================================================
CREATE TABLE prompt_history (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    vertical VARCHAR(50) NOT NULL,
    tool_name VARCHAR(100) NOT NULL,
    input_data JSONB NOT NULL,
    generated_prompt TEXT NOT NULL,
    claude_response TEXT,
    claude_model VARCHAR(100),
    temperature NUMERIC(3,2),
    max_tokens INTEGER,
    top_p NUMERIC(3,2),
    system_prompt_sent TEXT,
    tokens_input INTEGER,
    tokens_output INTEGER,
    tokens_total INTEGER,
    cost_usd NUMERIC(10,6),
    response_time_ms INTEGER,
    status TEXT DEFAULT 'pending'
        CHECK (status IN ('pending', 'success', 'error')),
    error_message TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_ph_user_id ON prompt_history (user_id);
CREATE INDEX idx_ph_vertical ON prompt_history (vertical);
CREATE INDEX idx_ph_tool_name ON prompt_history (tool_name);
CREATE INDEX idx_ph_created_at ON prompt_history (created_at);
CREATE INDEX idx_ph_status ON prompt_history (status);
CREATE INDEX idx_ph_input_data ON prompt_history USING GIN (input_data);

-- ============================================================================
-- 14. canvas_templates
-- ============================================================================
CREATE TABLE canvas_templates (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    vertical TEXT NOT NULL,
    form_config JSONB,
    system_prompt TEXT,
    user_prompt_template TEXT,
    max_questions INTEGER DEFAULT 5,
    is_active BOOLEAN DEFAULT TRUE,
    status TEXT DEFAULT 'published'
        CHECK (status IN ('draft', 'published', 'archived')),
    draft_form_config JSONB,
    current_version INTEGER DEFAULT 1,
    last_published_at TIMESTAMPTZ,
    has_unpublished_changes BOOLEAN DEFAULT FALSE,
    last_edited_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    api_params_override JSONB,
    config JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_ct_slug ON canvas_templates (slug);
CREATE INDEX idx_ct_vertical ON canvas_templates (vertical);
CREATE INDEX idx_ct_status_vertical ON canvas_templates (status, vertical, is_active);
CREATE INDEX idx_ct_form_config ON canvas_templates USING GIN (form_config);

CREATE TRIGGER trg_canvas_templates_updated_at
    BEFORE UPDATE ON canvas_templates
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 15. canvas_template_versions
-- ============================================================================
CREATE TABLE canvas_template_versions (
    id SERIAL PRIMARY KEY,
    template_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    version INTEGER NOT NULL,
    form_config JSONB NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    comment VARCHAR(500),
    UNIQUE (template_id, version)
);

CREATE INDEX idx_ctv_template_created ON canvas_template_versions (template_id, created_at DESC);

-- ============================================================================
-- 16. conversations
-- ============================================================================
CREATE TABLE conversations (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    canvas_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    title VARCHAR(255),
    status TEXT DEFAULT 'active'
        CHECK (status IN ('active', 'completed', 'archived')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_conv_user_id ON conversations (user_id);
CREATE INDEX idx_conv_canvas_id ON conversations (canvas_id);
CREATE INDEX idx_conv_status ON conversations (status);
CREATE INDEX idx_conv_updated_at ON conversations (updated_at);

CREATE TRIGGER trg_conversations_updated_at
    BEFORE UPDATE ON conversations
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 17. conversation_messages
-- ============================================================================
CREATE TABLE conversation_messages (
    id BIGSERIAL PRIMARY KEY,
    conversation_id BIGINT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role TEXT NOT NULL
        CHECK (role IN ('user', 'assistant')),
    content TEXT,
    message_type TEXT
        CHECK (message_type IS NULL OR message_type IN ('question', 'answer', 'final_response', 'form_submission', 'context')),
    question_number INTEGER,
    tokens_input INTEGER,
    tokens_output INTEGER,
    cost_usd NUMERIC(10,6),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_cm_conversation_id ON conversation_messages (conversation_id);
CREATE INDEX idx_cm_created_at ON conversation_messages (created_at);

-- ============================================================================
-- 18. user_files
-- ============================================================================
CREATE TABLE user_files (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(500),
    filepath VARCHAR(500),
    mime_type VARCHAR(100),
    size_bytes BIGINT,
    md5_hash CHAR(32) NOT NULL,
    extracted_text TEXT,
    extraction_error TEXT,
    uploaded_at TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_uf_user_id ON user_files (user_id);
CREATE INDEX idx_uf_md5_hash ON user_files (md5_hash);
CREATE INDEX idx_uf_uploaded_at ON user_files (uploaded_at);

-- ============================================================================
-- 19. conversation_files (junction)
-- ============================================================================
CREATE TABLE conversation_files (
    conversation_id BIGINT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    file_id BIGINT NOT NULL REFERENCES user_files(id) ON DELETE CASCADE,
    PRIMARY KEY (conversation_id, file_id)
);

-- ============================================================================
-- 20. verticals (metadata, optional — may or may not be used)
-- ============================================================================
CREATE TABLE verticals (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    config JSONB,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TRIGGER trg_verticals_updated_at
    BEFORE UPDATE ON verticals
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 21. claude_models_cache (optional: could also be in settings)
-- ============================================================================
-- Stored as settings rows instead of a separate table.

-- ============================================================================
-- 22. formulario_feedback
-- ============================================================================
CREATE TABLE formulario_feedback (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    page_url VARCHAR(500),
    rating INTEGER CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_ff_user_id ON formulario_feedback (user_id);
CREATE INDEX idx_ff_created_at ON formulario_feedback (created_at);

-- ============================================================================
-- Views
-- ============================================================================

-- Published canvas templates only (for user-facing queries)
CREATE OR REPLACE VIEW canvas_templates_published AS
SELECT
    id, slug, name, vertical, form_config, system_prompt,
    user_prompt_template, max_questions, is_active,
    created_at, updated_at, last_edited_by
FROM canvas_templates
WHERE status = 'published' AND is_active = TRUE;

-- ============================================================================
-- SEED DATA
-- ============================================================================

-- Default settings
INSERT INTO settings (setting_key, setting_value, data_type, description, is_public) VALUES
    ('juridico_requires_approval', '1', 'boolean', 'Se verdadeiro, vertical Jurídico requer aprovação admin.', FALSE),
    ('platform_name', 'Plataforma Sunyata', 'string', 'Nome da plataforma exibido no site.', TRUE),
    ('max_users_per_vertical', '1000', 'integer', 'Limite de usuários por vertical (0 = ilimitado).', FALSE),
    ('claude_models_cache', '[]', 'json', 'Lista cacheada de modelos Claude disponíveis via API Anthropic.', FALSE),
    ('claude_models_cache_updated_at', '', 'string', 'Timestamp ISO 8601 do último refresh do cache de modelos.', FALSE)
ON CONFLICT (setting_key) DO NOTHING;

-- Admin user (email/password — password: "admin123" bcrypt hash)
-- CHANGE THIS PASSWORD IMMEDIATELY after first login
INSERT INTO users (email, name, access_level, password_hash, completed_onboarding)
VALUES (
    'admin@sunyataconsulting.com',
    'Administrador',
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: "password"
    TRUE
)
ON CONFLICT (email) DO NOTHING;

-- Sample prompt entries
INSERT INTO prompt_dictionary (vertical, category, title, prompt_text, description, access_level) VALUES
    ('geral_todos', 'produtividade', 'Resumo Executivo', 'Resuma o seguinte conteúdo em formato executivo: [TEXTO]. Estrutura: síntese em 3 bullet points, principais insights, ações recomendadas. Máximo 200 palavras.', 'Condensar informações longas em resumos acionáveis', 'free')
ON CONFLICT DO NOTHING;

-- Tool versions
INSERT INTO tool_versions (tool_name, version, file_path, is_active, notes) VALUES
    ('canvas-docente', 'v1', 'public/ferramentas/canvas-docente.html', TRUE, 'Versão inicial'),
    ('canvas-juridico', 'v1', 'public/ferramentas/canvas-juridico.html', TRUE, 'Versão inicial'),
    ('canvas-pesquisa', 'v1', 'public/ferramentas/canvas-pesquisa.html', TRUE, 'Versão inicial')
ON CONFLICT DO NOTHING;

COMMIT;
