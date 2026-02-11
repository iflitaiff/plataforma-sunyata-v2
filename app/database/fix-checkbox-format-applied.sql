-- Sprint 3.5: Correção de formatação de checkboxes em PDFs
-- Data: 2025-10-27
-- Problema: Checkboxes apareciam separados do texto em linhas diferentes

UPDATE canvas_templates
SET system_prompt = CONCAT(
    system_prompt,
    '\n\n**FORMATAÇÃO OBRIGATÓRIA DE CHECKLISTS:**\n',
    '- Use checkboxes INLINE: `1. ☐ Item do checklist`\n',
    '- NUNCA separe checkboxes em linhas diferentes\n',
    '- Para observações após itens, use NOVA LINHA com indentação:\n',
    '  Exemplo: `5. ☐ Item\n',
    '     ⚠ Criticidade Alta: detalhe`'
)
WHERE slug = 'juridico-due-diligence';

-- Aplicado em produção: 2025-10-27
-- Resultado: Checkboxes agora aparecem inline com o texto no PDF
