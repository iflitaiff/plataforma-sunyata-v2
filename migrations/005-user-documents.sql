-- ============================================================================
-- Plataforma Sunyata v2 — Migration 005: user_documents + submission_documents
-- Permanent document library for cross-form reuse.
-- ============================================================================

BEGIN;

-- ============================================================================
-- user_documents — permanent document library
-- ============================================================================
CREATE TABLE user_documents (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    storage_path VARCHAR(500) NOT NULL,
    extracted_text TEXT,
    extraction_status TEXT DEFAULT 'pending'
        CHECK (extraction_status IN ('pending', 'processing', 'completed', 'failed')),
    metadata JSONB DEFAULT '{}',
    tags JSONB DEFAULT '[]',
    is_archived BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes
CREATE INDEX idx_ud_user_id ON user_documents (user_id);
CREATE INDEX idx_ud_mime_type ON user_documents (mime_type);
CREATE INDEX idx_ud_extraction_status ON user_documents (extraction_status);
CREATE INDEX idx_ud_is_archived ON user_documents (user_id, is_archived) WHERE is_archived = FALSE;
CREATE INDEX idx_ud_tags ON user_documents USING GIN (tags);
CREATE INDEX idx_ud_created_at ON user_documents (created_at DESC);

-- Full-text search on filename + extracted_text
ALTER TABLE user_documents ADD COLUMN search_vector tsvector;

CREATE OR REPLACE FUNCTION user_documents_search_update()
RETURNS TRIGGER AS $$
BEGIN
    NEW.search_vector := to_tsvector('portuguese',
        COALESCE(NEW.filename, '') || ' ' ||
        COALESCE(NEW.extracted_text, '')
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_user_documents_search
    BEFORE INSERT OR UPDATE ON user_documents
    FOR EACH ROW EXECUTE FUNCTION user_documents_search_update();

CREATE INDEX idx_ud_search ON user_documents USING GIN (search_vector);

-- Auto-update updated_at
CREATE TRIGGER trg_user_documents_updated_at
    BEFORE UPDATE ON user_documents
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- submission_documents — junction table linking submissions to documents
-- ============================================================================
CREATE TABLE submission_documents (
    submission_id BIGINT NOT NULL REFERENCES user_submissions(id) ON DELETE CASCADE,
    document_id BIGINT NOT NULL REFERENCES user_documents(id) ON DELETE CASCADE,
    PRIMARY KEY (submission_id, document_id)
);

CREATE INDEX idx_sd_document_id ON submission_documents (document_id);

COMMIT;
