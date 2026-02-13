-- Migration 007: Form Drafts (server-side)
-- Substitui localStorage auto-save por drafts persistentes no servidor
-- Limite: 10 drafts por user+template, TTL 90 dias

BEGIN;

CREATE TABLE form_drafts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    canvas_template_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    label VARCHAR(255) DEFAULT 'Rascunho sem titulo',
    form_data JSONB NOT NULL DEFAULT '{}',
    page_no INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    expires_at TIMESTAMPTZ DEFAULT (NOW() + INTERVAL '90 days')
);

CREATE INDEX idx_drafts_user_template ON form_drafts(user_id, canvas_template_id);
CREATE INDEX idx_drafts_expires ON form_drafts(expires_at);

-- Reutiliza update_updated_at_column() da migration 001
CREATE TRIGGER trg_form_drafts_updated_at
    BEFORE UPDATE ON form_drafts
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

COMMIT;
