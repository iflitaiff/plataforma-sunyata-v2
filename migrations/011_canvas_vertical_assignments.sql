-- Migration: Create canvas_vertical_assignments Junction Table
-- Data: 2026-02-19
-- Fase: 3.5 Part 2 - Many-to-Many Canvas-Vertical Relationship
-- Motivo: Permitir que um canvas seja atribuído a múltiplas verticais
--
-- IMPORTANTE: Este é um passo crítico para eliminar duplicação de canvas.
-- Um mesmo canvas poderá aparecer em múltiplas áreas (verticais) sem
-- necessidade de cópias ou workarounds.
--
-- Rollback: Ver final do arquivo

-- ============================================================================
-- CREATE JUNCTION TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS canvas_vertical_assignments (
    id SERIAL PRIMARY KEY,
    canvas_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    vertical_slug TEXT NOT NULL,
    display_order INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),

    -- Prevent duplicate assignments
    CONSTRAINT unique_canvas_vertical UNIQUE (canvas_id, vertical_slug)
);

-- ============================================================================
-- CREATE INDEXES FOR PERFORMANCE
-- ============================================================================

-- Index for queries like: "Get all canvas for vertical X"
CREATE INDEX IF NOT EXISTS idx_canvas_vertical_slug
ON canvas_vertical_assignments(vertical_slug);

-- Index for queries like: "Get all verticals for canvas Y"
CREATE INDEX IF NOT EXISTS idx_canvas_vertical_canvas_id
ON canvas_vertical_assignments(canvas_id);

-- Composite index for JOIN queries (most common pattern)
CREATE INDEX IF NOT EXISTS idx_canvas_vertical_composite
ON canvas_vertical_assignments(vertical_slug, canvas_id);

-- Index for ordering within vertical
CREATE INDEX IF NOT EXISTS idx_canvas_vertical_display_order
ON canvas_vertical_assignments(vertical_slug, display_order);

-- ============================================================================
-- ADD FOREIGN KEY TO VERTICALS TABLE (OPTIONAL - for referential integrity)
-- ============================================================================

-- NOTE: We're NOT adding FK to verticals.slug because:
-- 1. Verticals table is hybrid (DB + config file)
-- 2. Config-based verticals don't have DB records
-- 3. Runtime validation handles this instead
--
-- If we wanted strict DB integrity (future enhancement):
-- ALTER TABLE canvas_vertical_assignments
--   ADD CONSTRAINT fk_vertical_slug
--   FOREIGN KEY (vertical_slug) REFERENCES verticals(slug) ON DELETE RESTRICT;

-- ============================================================================
-- CREATE TRIGGER FOR updated_at
-- ============================================================================

CREATE OR REPLACE FUNCTION update_canvas_vertical_assignments_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_canvas_vertical_assignments_updated_at
    BEFORE UPDATE ON canvas_vertical_assignments
    FOR EACH ROW
    EXECUTE FUNCTION update_canvas_vertical_assignments_updated_at();

-- ============================================================================
-- VALIDATION
-- ============================================================================

-- Verify table exists
SELECT
    table_name,
    column_name,
    data_type,
    is_nullable
FROM information_schema.columns
WHERE table_name = 'canvas_vertical_assignments'
ORDER BY ordinal_position;

-- Verify indexes
SELECT
    indexname,
    indexdef
FROM pg_indexes
WHERE tablename = 'canvas_vertical_assignments'
ORDER BY indexname;

-- Verify constraints
SELECT
    conname AS constraint_name,
    contype AS constraint_type
FROM pg_constraint
WHERE conrelid = 'canvas_vertical_assignments'::regclass
ORDER BY conname;

-- If successful, should see:
-- - 6 columns (id, canvas_id, vertical_slug, display_order, created_at, updated_at)
-- - 4 indexes (primary key + 3 performance indexes)
-- - 2 constraints (primary key + unique constraint)
-- - 1 foreign key (canvas_id → canvas_templates)

-- ============================================================================
-- OBSERVAÇÕES IMPORTANTES
-- ============================================================================

-- 1. Migration 012 irá popular esta tabela com dados existentes
-- 2. Queries antigas usando canvas_templates.vertical continuam funcionando
--    até migration 012 dropar a coluna
-- 3. CanvasService precisa ser atualizado para usar JOIN
-- 4. Admin UI (canvas-edit.php) precisa checkboxes para múltiplas verticais

-- ============================================================================
-- ROLLBACK (caso necessário)
-- ============================================================================

/*
-- ATENÇÃO: Rollback só funciona se migration 012 NÃO rodou ainda.
-- Se a coluna canvas_templates.vertical foi dropada, o rollback é mais complexo.

-- Drop trigger
DROP TRIGGER IF EXISTS trigger_canvas_vertical_assignments_updated_at ON canvas_vertical_assignments;
DROP FUNCTION IF EXISTS update_canvas_vertical_assignments_updated_at();

-- Drop indexes (automatically dropped with table, but explicit for clarity)
DROP INDEX IF EXISTS idx_canvas_vertical_slug;
DROP INDEX IF EXISTS idx_canvas_vertical_canvas_id;
DROP INDEX IF EXISTS idx_canvas_vertical_composite;
DROP INDEX IF EXISTS idx_canvas_vertical_display_order;

-- Drop table (CASCADE will also drop foreign key constraints)
DROP TABLE IF EXISTS canvas_vertical_assignments CASCADE;
*/
