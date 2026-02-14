-- Migration 009: Form Artifacts & Chaining System
-- Fase 3: Form Chaining MVP
-- Data: 2026-02-13

-- ==============================================================================
-- Tabela: form_artifacts
-- Armazena outputs de formulários que podem ser reutilizados como contexto
-- ==============================================================================

CREATE TABLE IF NOT EXISTS form_artifacts (
    id SERIAL PRIMARY KEY,

    -- Relacionamentos
    user_id INT NOT NULL,
    canvas_template_id INT NOT NULL,
    submission_id INT UNIQUE, -- Pode ser NULL se artifact criado manualmente

    -- Conteúdo do artifact
    title VARCHAR(255) NOT NULL,
    artifact_type VARCHAR(50) NOT NULL, -- 'text', 'json', 'markdown'
    content TEXT NOT NULL,
    metadata JSONB DEFAULT '{}',

    -- Timestamps
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),

    -- Foreign keys
    CONSTRAINT fk_artifact_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_artifact_template
        FOREIGN KEY (canvas_template_id)
        REFERENCES canvas_templates(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_artifact_submission
        FOREIGN KEY (submission_id)
        REFERENCES user_submissions(id)
        ON DELETE SET NULL,

    -- Constraints
    CONSTRAINT chk_artifact_type
        CHECK (artifact_type IN ('text', 'json', 'markdown'))
);

-- Indexes para performance
CREATE INDEX idx_artifacts_user ON form_artifacts(user_id);
CREATE INDEX idx_artifacts_template ON form_artifacts(canvas_template_id);
CREATE INDEX idx_artifacts_created ON form_artifacts(created_at DESC);
CREATE INDEX idx_artifacts_user_template ON form_artifacts(user_id, canvas_template_id);

-- Comentários
COMMENT ON TABLE form_artifacts IS 'Artifacts gerados por formulários para reutilização como contexto';
COMMENT ON COLUMN form_artifacts.artifact_type IS 'Tipo de conteúdo: text, json, markdown';
COMMENT ON COLUMN form_artifacts.metadata IS 'Dados adicionais: tags, origem, versão, etc';

-- ==============================================================================
-- Tabela: artifact_relations
-- Define relações entre templates (quais artifacts podem ser usados em qual form)
-- ==============================================================================

CREATE TABLE IF NOT EXISTS artifact_relations (
    id SERIAL PRIMARY KEY,

    -- Template origem (que gera o artifact) e destino (que pode usar)
    from_template_id INT NOT NULL,
    to_template_id INT NOT NULL,

    -- Tipo de relação
    relation_type VARCHAR(50) DEFAULT 'suggested', -- 'suggested', 'required', 'optional'

    -- Metadados
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW(),

    -- Foreign keys
    CONSTRAINT fk_relation_from_template
        FOREIGN KEY (from_template_id)
        REFERENCES canvas_templates(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_relation_to_template
        FOREIGN KEY (to_template_id)
        REFERENCES canvas_templates(id)
        ON DELETE CASCADE,

    -- Constraints
    CONSTRAINT chk_relation_type
        CHECK (relation_type IN ('suggested', 'required', 'optional')),

    -- Não permitir relação duplicada
    CONSTRAINT unique_template_relation
        UNIQUE (from_template_id, to_template_id)
);

-- Indexes para performance
CREATE INDEX idx_relations_from ON artifact_relations(from_template_id);
CREATE INDEX idx_relations_to ON artifact_relations(to_template_id);

-- Comentários
COMMENT ON TABLE artifact_relations IS 'Define quais templates podem usar artifacts de outros templates';
COMMENT ON COLUMN artifact_relations.relation_type IS 'Tipo de relação: suggested (padrão), required, optional';
COMMENT ON COLUMN artifact_relations.metadata IS 'Config adicional: mapeamento de campos, transformações, etc';

-- ==============================================================================
-- Trigger: Atualizar updated_at automaticamente
-- ==============================================================================

CREATE OR REPLACE FUNCTION update_artifact_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_artifact_updated_at
    BEFORE UPDATE ON form_artifacts
    FOR EACH ROW
    EXECUTE FUNCTION update_artifact_timestamp();

-- ==============================================================================
-- Dados de exemplo: Relações entre templates IATR
-- ==============================================================================

-- Exemplo: Parecer Preliminar → Parecer Final
-- (Assumindo que os templates existem - ajustar IDs conforme necessário)
-- INSERT INTO artifact_relations (from_template_id, to_template_id, relation_type, metadata)
-- VALUES
--     (1, 2, 'suggested', '{"description": "Análise preliminar pode ser usada no parecer final"}');

-- ==============================================================================
-- Validação
-- ==============================================================================

-- Verificar tabelas criadas
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'form_artifacts') THEN
        RAISE NOTICE '✓ Tabela form_artifacts criada com sucesso';
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'artifact_relations') THEN
        RAISE NOTICE '✓ Tabela artifact_relations criada com sucesso';
    END IF;
END $$;
