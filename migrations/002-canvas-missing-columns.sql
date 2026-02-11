-- ============================================================================
-- Migration 002: Add missing columns to canvas_templates
-- These columns existed in v1 canvas table and are used by area pages
-- Date: 2026-02-11
-- ============================================================================

ALTER TABLE canvas_templates ADD COLUMN IF NOT EXISTS type TEXT DEFAULT 'forms';
ALTER TABLE canvas_templates ADD COLUMN IF NOT EXISTS description TEXT;
ALTER TABLE canvas_templates ADD COLUMN IF NOT EXISTS icon VARCHAR(100);
ALTER TABLE canvas_templates ADD COLUMN IF NOT EXISTS color VARCHAR(50);
ALTER TABLE canvas_templates ADD COLUMN IF NOT EXISTS page_url TEXT;
ALTER TABLE canvas_templates ADD COLUMN IF NOT EXISTS external_url TEXT;
ALTER TABLE canvas_templates ADD COLUMN IF NOT EXISTS display_order INTEGER DEFAULT 0;

-- Add CHECK constraint for type (ignore if already exists)
DO $$
BEGIN
    ALTER TABLE canvas_templates ADD CONSTRAINT chk_canvas_type
        CHECK (type IN ('forms', 'page', 'external'));
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

-- Recreate view to pick up new columns
CREATE OR REPLACE VIEW canvas AS SELECT * FROM canvas_templates;
