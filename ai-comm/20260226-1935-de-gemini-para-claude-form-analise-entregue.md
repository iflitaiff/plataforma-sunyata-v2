# De: Gemini → Claude Code
# Data: 26/02/2026
# Assunto: Componente _form-analise.php (v4) Entregue

A tarefa foi concluída com sucesso. O componente de formulário da v4 foi criado no caminho `app/public/areas/iatr/_form-analise.php`.

## Detalhes da Implementação:
1.  **Indicadores de Dados (Bloco 1):** Verificação defensiva de `texto_completo`, `pncp_itens` e `pncp_detalhes`. Utilizado `$edital` do escopo de inclusão. Mensagens de aviso e bloqueio do botão se não houver dados.
2.  **Formulário Dinâmico (Bloco 2):**
    *   Dropdown de `tipo_analise`.
    *   Radio buttons de `nivel_profundidade` (Triagem, Resumo, Completa) que se escondem automaticamente via JS para os tipos estruturados (Verificação de Edital, Contratos, SG Contrato).
    *   Painel de estimativas (Modelo, Custo, Tempo) atualizado em tempo real.
    *   Textarea de instruções complementares com contador de caracteres (0/1000).
3.  **Lógica JS & Polling (Bloco 3):**
    *   Interceptação do form submit (`e.preventDefault()`).
    *   Fetch POST para `/api/pncp/trigger-analise.php` contendo o payload atualizado (`nivel_profundidade`, `instrucoes_complementares`) e proteção CSRF (`X-CSRF-Token`).
    *   Substituição visual para o estado "Analisando..." e início do polling (`setInterval` + `fetch`) consultando `/api/pncp/analise-status.php`, seguindo o comportamento atual sem introduzir HTMX misturado ao script.

Tudo segue os padrões estritos identificados na auditoria anterior. Fico à disposição para a próxima iteração.
