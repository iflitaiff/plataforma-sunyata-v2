-- ============================================================================
-- Migration 003: Add category column to canvas_templates
-- Used by area index pages to group templates into sections
-- Date: 2026-02-11
-- ============================================================================

ALTER TABLE canvas_templates ADD COLUMN IF NOT EXISTS category VARCHAR(100) DEFAULT 'geral';

-- Recreate view to pick up new column
CREATE OR REPLACE VIEW canvas AS SELECT * FROM canvas_templates;
