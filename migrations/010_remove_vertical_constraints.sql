-- Migration: Remove CHECK Constraints de Verticais
-- Data: 2026-02-18
-- Fase: 3.5 - Admin Improvements
-- Motivo: Permitir criação dinâmica de verticais via admin UI
--
-- IMPORTANTE: Este é um passo crítico para permitir que admin crie
-- verticais SEM necessidade de migrations/deploy. A validação move
-- para runtime (PHP) ao invés de database constraints.
--
-- Rollback: Ver final do arquivo

-- ============================================================================
-- REMOVER CHECK CONSTRAINTS
-- ============================================================================

-- 1. Tabela users (selected_vertical)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name LIKE '%users_selected_vertical%'
        AND table_name = 'users'
    ) THEN
        ALTER TABLE users DROP CONSTRAINT IF EXISTS users_selected_vertical_check;
        RAISE NOTICE 'users: CHECK constraint removed';
    ELSE
        RAISE NOTICE 'users: CHECK constraint already removed or does not exist';
    END IF;
END$$;

-- 2. Tabela canvas_templates (vertical)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name LIKE '%canvas_templates_vertical%'
        AND table_name = 'canvas_templates'
    ) THEN
        ALTER TABLE canvas_templates DROP CONSTRAINT IF EXISTS canvas_templates_vertical_check;
        RAISE NOTICE 'canvas_templates: CHECK constraint removed';
    ELSE
        RAISE NOTICE 'canvas_templates: CHECK constraint already removed or does not exist';
    END IF;
END$$;

-- 3. Tabela contracts (vertical)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name LIKE '%contracts_vertical%'
        AND table_name = 'contracts'
    ) THEN
        ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_vertical_check;
        RAISE NOTICE 'contracts: CHECK constraint removed';
    ELSE
        RAISE NOTICE 'contracts: CHECK constraint already removed or does not exist';
    END IF;
END$$;

-- 4. Tabela prompt_dictionary (vertical)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name LIKE '%prompt_dictionary_vertical%'
        AND table_name = 'prompt_dictionary'
    ) THEN
        ALTER TABLE prompt_dictionary DROP CONSTRAINT IF EXISTS prompt_dictionary_vertical_check;
        RAISE NOTICE 'prompt_dictionary: CHECK constraint removed';
    ELSE
        RAISE NOTICE 'prompt_dictionary: CHECK constraint already removed or does not exist';
    END IF;
END$$;

-- 5. Tabela vertical_access_requests (vertical)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name LIKE '%vertical_access_requests_vertical%'
        AND table_name = 'vertical_access_requests'
    ) THEN
        ALTER TABLE vertical_access_requests DROP CONSTRAINT IF EXISTS vertical_access_requests_vertical_check;
        RAISE NOTICE 'vertical_access_requests: CHECK constraint removed';
    ELSE
        RAISE NOTICE 'vertical_access_requests: CHECK constraint already removed or does not exist';
    END IF;
END$$;

-- 6. Tabela tool_access_logs (vertical) - pode ser NULL
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name LIKE '%tool_access_logs_vertical%'
        AND table_name = 'tool_access_logs'
    ) THEN
        ALTER TABLE tool_access_logs DROP CONSTRAINT IF EXISTS tool_access_logs_vertical_check;
        RAISE NOTICE 'tool_access_logs: CHECK constraint removed';
    ELSE
        RAISE NOTICE 'tool_access_logs: CHECK constraint already removed or does not exist';
    END IF;
END$$;

-- ============================================================================
-- VALIDAÇÃO PÓS-MIGRATION
-- ============================================================================

-- Verificar que constraints foram removidos
SELECT
    table_name,
    constraint_name,
    constraint_type
FROM information_schema.table_constraints
WHERE constraint_name LIKE '%vertical%'
  AND constraint_type = 'CHECK'
ORDER BY table_name;

-- Se output acima estiver vazio = SUCCESS!

-- ============================================================================
-- OBSERVAÇÕES IMPORTANTES
-- ============================================================================

-- 1. Validação agora é feita em runtime via PHP (VerticalService)
-- 2. Verticais podem ser criadas dinamicamente via admin UI
-- 3. Config file (config/verticals.php) permanece como fallback
-- 4. Tabela `verticals` é source of truth (prioridade sobre config)

-- ============================================================================
-- ROLLBACK (caso necessário)
-- ============================================================================

-- ATENÇÃO: Rollback só funciona se ainda existem apenas as verticais
-- originais hardcoded. Se já criaram novas verticais via admin, o rollback
-- FALHARÁ (lista de verticais hardcoded não incluirá as novas).
--
-- Neste caso, use soft rollback: manter constraints removidos e continuar
-- usando validação runtime.

/*
-- Rollback: Recriar CHECK constraints (APENAS SE NECESSÁRIO)

-- 1. users.selected_vertical
ALTER TABLE users ADD CONSTRAINT users_selected_vertical_check
CHECK (selected_vertical IN (
    'docencia', 'pesquisa', 'ifrj_alunos', 'juridico', 'vendas',
    'marketing', 'licitacoes', 'rh', 'geral', 'iatr', 'prompt-builder',
    'nicolay-advogados', 'legal'
));

-- 2. canvas_templates.vertical
ALTER TABLE canvas_templates ADD CONSTRAINT canvas_templates_vertical_check
CHECK (vertical IN (
    'docencia', 'pesquisa', 'ifrj_alunos', 'juridico', 'vendas',
    'marketing', 'licitacoes', 'rh', 'geral', 'iatr', 'prompt-builder',
    'nicolay-advogados', 'legal'
));

-- 3. contracts.vertical
ALTER TABLE contracts ADD CONSTRAINT contracts_vertical_check
CHECK (vertical IN (
    'docencia', 'pesquisa', 'ifrj_alunos', 'juridico', 'vendas',
    'marketing', 'licitacoes', 'rh', 'geral', 'iatr', 'prompt-builder',
    'nicolay-advogados', 'legal'
));

-- 4. prompt_dictionary.vertical
ALTER TABLE prompt_dictionary ADD CONSTRAINT prompt_dictionary_vertical_check
CHECK (vertical IN (
    'docencia', 'pesquisa', 'ifrj_alunos', 'juridico', 'vendas',
    'marketing', 'licitacoes', 'rh', 'geral', 'iatr', 'prompt-builder',
    'nicolay-advogados', 'legal'
));

-- 5. vertical_access_requests.vertical
ALTER TABLE vertical_access_requests ADD CONSTRAINT vertical_access_requests_vertical_check
CHECK (vertical IN (
    'docencia', 'pesquisa', 'ifrj_alunos', 'juridico', 'vendas',
    'marketing', 'licitacoes', 'rh', 'geral', 'iatr', 'prompt-builder',
    'nicolay-advogados', 'legal'
));

-- 6. tool_access_logs.vertical (nullable)
ALTER TABLE tool_access_logs ADD CONSTRAINT tool_access_logs_vertical_check
CHECK (vertical IS NULL OR vertical IN (
    'docencia', 'pesquisa', 'ifrj_alunos', 'juridico', 'vendas',
    'marketing', 'licitacoes', 'rh', 'geral', 'iatr', 'prompt-builder',
    'nicolay-advogados', 'legal'
));
*/
