-- Migration: Migrate canvas_templates.vertical to Junction Table
-- Data: 2026-02-19
-- Fase: 3.5 Part 2 - Many-to-Many Data Migration
-- Motivo: Migrar dados existentes de coluna vertical para tabela junction
--
-- IMPORTANTE: Esta migration é DESTRUTIVA. Faz backup dos dados antes
-- de dropar a coluna vertical. Rollback requer restore manual.
--
-- Pré-requisito: Migration 011 deve ter rodado com sucesso
--
-- Rollback: Ver final do arquivo

-- ============================================================================
-- PRÉ-VALIDAÇÃO
-- ============================================================================

DO $$
DECLARE
    junction_exists BOOLEAN;
    vertical_column_exists BOOLEAN;
BEGIN
    -- Check if junction table exists
    SELECT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_name = 'canvas_vertical_assignments'
    ) INTO junction_exists;

    IF NOT junction_exists THEN
        RAISE EXCEPTION 'Junction table canvas_vertical_assignments does not exist. Run migration 011 first.';
    END IF;

    -- Check if vertical column still exists
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'canvas_templates' AND column_name = 'vertical'
    ) INTO vertical_column_exists;

    IF NOT vertical_column_exists THEN
        RAISE NOTICE 'Column canvas_templates.vertical already dropped. Migration may have run before.';
    END IF;

    RAISE NOTICE 'Pre-validation passed. Proceeding with migration...';
END$$;

-- ============================================================================
-- BACKUP TABLE (for rollback safety)
-- ============================================================================

-- Create backup table with timestamp
DO $$
BEGIN
    EXECUTE format(
        'CREATE TABLE canvas_templates_backup_%s AS SELECT * FROM canvas_templates',
        to_char(NOW(), 'YYYYMMDD_HH24MISS')
    );
    RAISE NOTICE 'Backup table created: canvas_templates_backup_%',
        to_char(NOW(), 'YYYYMMDD_HH24MISS');
END$$;

-- ============================================================================
-- MIGRATE DATA TO JUNCTION TABLE
-- ============================================================================

BEGIN;

-- Count existing canvas templates
DO $$
DECLARE
    total_canvas INTEGER;
    null_verticals INTEGER;
BEGIN
    SELECT COUNT(*) INTO total_canvas FROM canvas_templates;
    SELECT COUNT(*) INTO null_verticals FROM canvas_templates WHERE vertical IS NULL OR vertical = '';

    RAISE NOTICE '===========================================';
    RAISE NOTICE 'MIGRATION STATISTICS (BEFORE)';
    RAISE NOTICE '===========================================';
    RAISE NOTICE 'Total canvas templates: %', total_canvas;
    RAISE NOTICE 'Canvas with NULL/empty vertical: %', null_verticals;
    RAISE NOTICE 'Canvas with valid vertical: %', (total_canvas - null_verticals);
END$$;

-- Insert existing assignments into junction table
INSERT INTO canvas_vertical_assignments (canvas_id, vertical_slug, display_order, created_at)
SELECT
    id AS canvas_id,
    vertical AS vertical_slug,
    0 AS display_order,  -- Default order, can be customized later via admin UI
    created_at
FROM canvas_templates
WHERE vertical IS NOT NULL
  AND vertical != ''
ON CONFLICT (canvas_id, vertical_slug) DO NOTHING;  -- Prevent duplicates if migration runs twice

-- Verify migration
DO $$
DECLARE
    assignments_created INTEGER;
    expected_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO assignments_created FROM canvas_vertical_assignments;
    SELECT COUNT(*) INTO expected_count FROM canvas_templates
        WHERE vertical IS NOT NULL AND vertical != '';

    RAISE NOTICE '===========================================';
    RAISE NOTICE 'MIGRATION STATISTICS (AFTER)';
    RAISE NOTICE '===========================================';
    RAISE NOTICE 'Assignments created: %', assignments_created;
    RAISE NOTICE 'Expected count: %', expected_count;

    IF assignments_created != expected_count THEN
        RAISE WARNING 'Mismatch! Expected %, got %. Check for conflicts or data issues.',
            expected_count, assignments_created;
    ELSE
        RAISE NOTICE 'SUCCESS: All canvas migrated correctly.';
    END IF;
END$$;

-- ============================================================================
-- DROP VERTICAL COLUMN FROM canvas_templates
-- ============================================================================

-- CRITICAL: This step is DESTRUCTIVE and cannot be automatically rolled back
-- without restoring from backup

ALTER TABLE canvas_templates DROP COLUMN IF EXISTS vertical;

RAISE NOTICE '===========================================';
RAISE NOTICE 'Column canvas_templates.vertical DROPPED';
RAISE NOTICE '===========================================';

COMMIT;

-- ============================================================================
-- POST-MIGRATION VALIDATION
-- ============================================================================

-- Verify column is gone
DO $$
DECLARE
    column_exists BOOLEAN;
BEGIN
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'canvas_templates' AND column_name = 'vertical'
    ) INTO column_exists;

    IF column_exists THEN
        RAISE EXCEPTION 'Column canvas_templates.vertical still exists! Migration failed.';
    ELSE
        RAISE NOTICE 'Verification passed: column successfully dropped.';
    END IF;
END$$;

-- Show sample assignments
SELECT
    ct.id,
    ct.name AS canvas_name,
    cva.vertical_slug,
    cva.display_order,
    cva.created_at
FROM canvas_templates ct
INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
ORDER BY cva.vertical_slug, cva.display_order, ct.name
LIMIT 20;

-- Count assignments per vertical
SELECT
    vertical_slug,
    COUNT(*) AS canvas_count
FROM canvas_vertical_assignments
GROUP BY vertical_slug
ORDER BY canvas_count DESC, vertical_slug;

-- ============================================================================
-- CLEANUP OLD BACKUP TABLES (optional - run manually if needed)
-- ============================================================================

-- List all backup tables
SELECT tablename
FROM pg_tables
WHERE tablename LIKE 'canvas_templates_backup_%'
ORDER BY tablename;

-- To remove old backups (MANUAL - uncomment only after verification):
/*
DROP TABLE IF EXISTS canvas_templates_backup_20260219_123456;
*/

-- ============================================================================
-- OBSERVAÇÕES IMPORTANTES
-- ============================================================================

-- 1. Queries antigas usando canvas_templates.vertical QUEBRARÃO após esta migration
-- 2. DEVE atualizar CanvasService, canvas-edit.php, e areas/*/index.php ANTES de rodar
-- 3. Backup table permanece para rollback de emergência
-- 4. Canvas sem vertical (NULL/empty) não geram assignment (intencional)
-- 5. Para atribuir canvas a múltiplas verticais, use INSERT manual ou admin UI

-- ============================================================================
-- ROLLBACK (MANUAL - requer intervenção)
-- ============================================================================

/*
-- ATENÇÃO: Rollback desta migration é MANUAL e envolve:
-- 1. Identificar a backup table correta (canvas_templates_backup_YYYYMMDD_HHMMSS)
-- 2. Restaurar a coluna vertical
-- 3. Deletar dados da junction table (opcional, pode manter para testes)

-- Step 1: Restaurar coluna vertical
ALTER TABLE canvas_templates ADD COLUMN vertical TEXT;

-- Step 2: Copiar dados do backup (substitua TIMESTAMP pelo correto)
UPDATE canvas_templates ct
SET vertical = bkp.vertical
FROM canvas_templates_backup_20260219_123456 bkp
WHERE ct.id = bkp.id;

-- Step 3: (Opcional) Deletar dados da junction table
DELETE FROM canvas_vertical_assignments;

-- Step 4: Verificar restauração
SELECT id, name, vertical FROM canvas_templates LIMIT 10;

-- Step 5: (Opcional) Recriar constraints se necessário
-- ALTER TABLE canvas_templates ADD CONSTRAINT ... (veja migration 010 rollback)
*/
