/**
 * Migration: Add Draft System to Canvas Templates
 *
 * Adiciona sistema de draft/staging para permitir testar
 * templates no Survey Creator antes de publicar para produção.
 *
 * Data: 2025-10-28
 * Sprint: MVP Admin Canvas - Survey Creator Integration
 */

-- ============================================================================
-- 1. Adicionar coluna de status (draft/published/archived)
-- ============================================================================

ALTER TABLE canvas_templates
ADD COLUMN status ENUM('draft', 'published', 'archived')
DEFAULT 'published'
COMMENT 'Status do template: draft (teste), published (produção), archived (inativo)'
AFTER is_active;

-- ============================================================================
-- 2. Adicionar coluna de rastreamento de edição
-- ============================================================================

ALTER TABLE canvas_templates
ADD COLUMN last_edited_by INT NULL
COMMENT 'ID do usuário que fez a última edição'
AFTER updated_at;

-- Adicionar foreign key (ON DELETE SET NULL para manter histórico)
ALTER TABLE canvas_templates
ADD CONSTRAINT fk_canvas_last_editor
FOREIGN KEY (last_edited_by) REFERENCES users(id)
ON DELETE SET NULL;

-- ============================================================================
-- 3. Atualizar templates existentes para 'published'
-- ============================================================================

UPDATE canvas_templates
SET status = 'published'
WHERE status IS NULL OR status = '';

-- ============================================================================
-- 4. Adicionar índices para performance
-- ============================================================================

-- Índice para queries que filtram por status + vertical
CREATE INDEX idx_canvas_status_vertical
ON canvas_templates(status, vertical, is_active);

-- ============================================================================
-- 5. Criar template de teste para Survey Creator
-- ============================================================================

INSERT INTO canvas_templates (
    slug,
    name,
    vertical,
    max_questions,
    is_active,
    status,
    form_config,
    system_prompt,
    user_prompt_template,
    created_at,
    updated_at
) VALUES (
    'teste-survey-creator',
    '🧪 Template de Teste - Survey Creator',
    'juridico',
    5,
    0,  -- Não ativo para usuários finais
    'draft',  -- Status de rascunho
    '{
        "logoPosition": "right",
        "pages": [
            {
                "name": "page1",
                "elements": [
                    {
                        "type": "html",
                        "name": "intro",
                        "html": "<div class=\'alert alert-warning\'><strong>🧪 TEMPLATE DE TESTE</strong><br>Este é um template para testar o Survey Creator. Modifique à vontade!</div>"
                    },
                    {
                        "type": "text",
                        "name": "campo_teste",
                        "title": "Campo de Teste",
                        "isRequired": false,
                        "maxLength": 500,
                        "defaultValue": "Digite algo aqui para testar..."
                    },
                    {
                        "type": "comment",
                        "name": "comentario_teste",
                        "title": "Comentário de Teste",
                        "rows": 3,
                        "maxLength": 1000
                    }
                ]
            }
        ],
        "showQuestionNumbers": "off",
        "questionsOnPageMode": "singlePage",
        "completeText": "Testar Envio",
        "completedHtml": "<div class=\'alert alert-info\'>Este é um template de teste - o formulário não será processado.</div>"
    }',
    'Você é um assistente de IA especializado em [ÁREA].

**Contexto do Usuário:**
[PERGUNTA-1]

**Instruções:**
Este é um template de teste. Responda de forma educada informando que é apenas um teste.

[RESPOSTA-FINAL]',
    'Template de teste criado automaticamente.

Campo de teste: {{campo_teste}}
Comentário: {{comentario_teste}}',
    NOW(),
    NOW()
);

-- ============================================================================
-- 6. Criar view para facilitar queries de produção
-- ============================================================================

CREATE OR REPLACE VIEW canvas_templates_published AS
SELECT
    id,
    slug,
    name,
    vertical,
    form_config,
    system_prompt,
    user_prompt_template,
    max_questions,
    is_active,
    created_at,
    updated_at,
    last_edited_by
FROM canvas_templates
WHERE status = 'published' AND is_active = 1;

-- ============================================================================
-- 7. Documentação de uso
-- ============================================================================

/*
COMO USAR O SISTEMA DE DRAFT:

1. Criar novo template como DRAFT:
   INSERT INTO canvas_templates (..., status) VALUES (..., 'draft');

2. Editar template existente (criar draft):
   UPDATE canvas_templates SET status = 'draft' WHERE id = X;

3. Publicar draft (tornar disponível em produção):
   UPDATE canvas_templates SET status = 'published', updated_at = NOW() WHERE id = X;

4. Arquivar template (remover de produção mas manter histórico):
   UPDATE canvas_templates SET status = 'archived' WHERE id = X;

5. Listar apenas templates de produção:
   SELECT * FROM canvas_templates WHERE status = 'published' AND is_active = 1;

6. Listar drafts para admin testar:
   SELECT * FROM canvas_templates WHERE status = 'draft';

QUERIES DE SEGURANÇA:

-- Usuários finais (canvas-juridico-v2.php):
SELECT * FROM canvas_templates
WHERE slug = ?
  AND vertical = ?
  AND is_active = 1
  AND status = 'published';  -- ⚠️ NUNCA mostrar drafts!

-- Admin (canvas-templates.php):
SELECT *,
       CASE status
           WHEN 'draft' THEN '🔶 Rascunho'
           WHEN 'published' THEN '✅ Publicado'
           WHEN 'archived' THEN '📦 Arquivado'
       END as status_display
FROM canvas_templates
ORDER BY FIELD(status, 'published', 'draft', 'archived');

ROLLBACK PLAN:

Se algo der errado, reverter com:

-- Remover colunas adicionadas:
ALTER TABLE canvas_templates DROP FOREIGN KEY fk_canvas_last_editor;
ALTER TABLE canvas_templates DROP COLUMN last_edited_by;
ALTER TABLE canvas_templates DROP COLUMN status;

-- Remover índices:
DROP INDEX idx_canvas_status_vertical ON canvas_templates;

-- Remover view:
DROP VIEW IF EXISTS canvas_templates_published;

-- Remover template de teste:
DELETE FROM canvas_templates WHERE slug = 'teste-survey-creator';
*/
