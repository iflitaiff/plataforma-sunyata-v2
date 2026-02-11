-- ============================================================================
-- Plataforma Sunyata v2 — Migration 004: user_submissions
-- "Meu Trabalho" — user workspace for form submissions and results
-- ============================================================================

BEGIN;

-- ============================================================================
-- user_submissions — workspace for all canvas form submissions
-- ============================================================================
CREATE TABLE user_submissions (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    canvas_template_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    prompt_history_id BIGINT REFERENCES prompt_history(id) ON DELETE SET NULL,
    vertical_slug TEXT NOT NULL,
    title VARCHAR(500),
    form_data JSONB NOT NULL DEFAULT '{}',
    result_markdown TEXT,
    result_metadata JSONB,            -- tokens, cost, model, response_time
    status TEXT DEFAULT 'pending'
        CHECK (status IN ('pending', 'processing', 'completed', 'error', 'draft', 'archived')),
    parent_id BIGINT REFERENCES user_submissions(id) ON DELETE SET NULL,  -- for resubmissions (versioning)
    is_favorite BOOLEAN DEFAULT FALSE,
    tags JSONB DEFAULT '[]',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes
CREATE INDEX idx_us_user_id ON user_submissions (user_id);
CREATE INDEX idx_us_canvas_template_id ON user_submissions (canvas_template_id);
CREATE INDEX idx_us_vertical_slug ON user_submissions (vertical_slug);
CREATE INDEX idx_us_status ON user_submissions (status);
CREATE INDEX idx_us_created_at ON user_submissions (created_at DESC);
CREATE INDEX idx_us_parent_id ON user_submissions (parent_id);
CREATE INDEX idx_us_is_favorite ON user_submissions (user_id, is_favorite) WHERE is_favorite = TRUE;
CREATE INDEX idx_us_form_data ON user_submissions USING GIN (form_data);
CREATE INDEX idx_us_tags ON user_submissions USING GIN (tags);

-- Full-text search on title + result
ALTER TABLE user_submissions ADD COLUMN search_vector tsvector;

CREATE OR REPLACE FUNCTION user_submissions_search_update()
RETURNS TRIGGER AS $$
BEGIN
    NEW.search_vector := to_tsvector('portuguese',
        COALESCE(NEW.title, '') || ' ' ||
        COALESCE(NEW.result_markdown, '')
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_user_submissions_search
    BEFORE INSERT OR UPDATE ON user_submissions
    FOR EACH ROW EXECUTE FUNCTION user_submissions_search_update();

CREATE INDEX idx_us_search ON user_submissions USING GIN (search_vector);

-- Auto-update updated_at
CREATE TRIGGER trg_user_submissions_updated_at
    BEFORE UPDATE ON user_submissions
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- User + canvas composite index for "recent sessions by canvas"
CREATE INDEX idx_us_user_canvas ON user_submissions (user_id, canvas_template_id, created_at DESC);

COMMIT;
