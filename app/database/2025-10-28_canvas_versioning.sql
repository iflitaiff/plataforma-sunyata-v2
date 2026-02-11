-- ============================================
-- Migration: Canvas Template Versioning System
-- Data: 2025-10-28
-- Descrição: Adiciona sistema de versionamento e draft para templates
-- ============================================

-- 1. Criar tabela de versões
CREATE TABLE IF NOT EXISTS canvas_template_versions (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    template_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    form_config LONGTEXT NOT NULL CHECK (json_valid(form_config)),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    comment VARCHAR(500) DEFAULT NULL COMMENT 'Nota opcional sobre a alteração',

    FOREIGN KEY (template_id) REFERENCES canvas_templates(id) ON DELETE CASCADE,
    UNIQUE KEY unique_template_version (template_id, version),
    INDEX idx_template_created (template_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Adicionar colunas de draft e metadados em canvas_templates
-- (Usamos procedure para ignorar erros se colunas já existem)
DELIMITER $$

CREATE PROCEDURE add_canvas_versioning_columns()
BEGIN
    -- Verificar e adicionar draft_form_config
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'canvas_templates'
                   AND COLUMN_NAME = 'draft_form_config') THEN
        ALTER TABLE canvas_templates
        ADD COLUMN draft_form_config LONGTEXT DEFAULT NULL COMMENT 'Versão em edição (não publicada)';
    END IF;

    -- Verificar e adicionar current_version
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'canvas_templates'
                   AND COLUMN_NAME = 'current_version') THEN
        ALTER TABLE canvas_templates
        ADD COLUMN current_version INT UNSIGNED DEFAULT 1
        COMMENT 'Versão publicada atualmente';
    END IF;

    -- Verificar e adicionar last_published_at
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'canvas_templates'
                   AND COLUMN_NAME = 'last_published_at') THEN
        ALTER TABLE canvas_templates
        ADD COLUMN last_published_at TIMESTAMP NULL
        COMMENT 'Data da última publicação';
    END IF;

    -- Verificar e adicionar has_unpublished_changes
    IF NOT EXISTS (SELECT * FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'canvas_templates'
                   AND COLUMN_NAME = 'has_unpublished_changes') THEN
        ALTER TABLE canvas_templates
        ADD COLUMN has_unpublished_changes BOOLEAN DEFAULT FALSE
        COMMENT 'Flag: há draft pendente?';
    END IF;
END$$

DELIMITER ;

CALL add_canvas_versioning_columns();
DROP PROCEDURE add_canvas_versioning_columns;

-- 3. Criar versão inicial para templates existentes
-- (Salva o form_config atual como versão 1 no histórico)
INSERT INTO canvas_template_versions (template_id, version, form_config, comment)
SELECT id, 1, form_config, 'Versão inicial (migração)'
FROM canvas_templates
WHERE id NOT IN (SELECT DISTINCT template_id FROM canvas_template_versions);

-- 4. Atualizar metadados dos templates existentes
UPDATE canvas_templates
SET current_version = 1,
    last_published_at = NOW(),
    has_unpublished_changes = FALSE
WHERE current_version IS NULL OR current_version = 0;

-- ============================================
-- Queries úteis para verificação:
-- ============================================

-- Ver templates com draft pendente:
-- SELECT id, name, slug, has_unpublished_changes FROM canvas_templates WHERE has_unpublished_changes = TRUE;

-- Ver histórico de versões de um template:
-- SELECT * FROM canvas_template_versions WHERE template_id = 1 ORDER BY version DESC;

-- Ver última versão de cada template:
-- SELECT t.id, t.name, t.current_version, t.last_published_at, COUNT(v.id) as total_versions
-- FROM canvas_templates t
-- LEFT JOIN canvas_template_versions v ON t.id = v.template_id
-- GROUP BY t.id;
