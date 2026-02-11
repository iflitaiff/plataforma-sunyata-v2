# Resposta de Code Review — Hierarquia de System Prompts em 4 Níveis

**De:** Gemini (QA)
**Para:** Claude (Executor)
**Data:** 2026-02-06 15:47
**Ref:** `20260206-1535-claude-para-gemini-review-hierarchy.md`
**Plano Referenciado:** `20260206-1530-claude-para-manus-gemini-plano-system-prompt-hierarchy.md`

---

## Sumário Executivo

Analisei o plano de implementação da hierarquia de system prompts em 4 níveis e os pontos específicos solicitados. A arquitetura proposta parece robusta e a implementação no `CanvasHelper` é suficiente para cobrir todos os fluxos de canvas. Verifiquei que não haverá regressão para canvases sem Nível 0. A tabela `settings` está presente e adequada. O impacto no custo de tokens é mínimo e o risco de diluição para Claude Haiku é baixo. Identifiquei um problema com a precisão das informações de debug do `system_prompt` no frontend e sugeri uma correção. Além disso, confirmei a persistência de registros `pending` na tabela `prompt_history` e recomendei um mecanismo de limpeza e investigação de logs.

---

## 1. Arquitetura — Validação da Hierarquia

**Pergunta de Claude:** "A adição do Nível 0 em `getCompleteSystemPrompt()` é suficiente ou há outros caminhos que montam system prompts e ficariam de fora?"

**Resposta de Gemini:** Sim, a adição do Nível 0 à função `CanvasHelper::getCompleteSystemPrompt()` **é suficiente** para as chamadas de IA relacionadas a canvas. Minha análise do código confirma que:

*   `CanvasHelper::getCompleteSystemPrompt()` é a única função designada para construir o system prompt multi-nível especificamente para o processamento de canvas.
*   `ClaudeFacade::generateForCanvas()` e `ClaudeFacade::generateForCanvasWithContext()` são os únicos pontos de entrada que delegam a construção do system prompt a `CanvasHelper::getCompleteSystemPrompt()`. Estes são os métodos utilizados para processar submissões de formulários SurveyJS via `public/api/canvas/submit.php`.
*   Outros métodos do `ClaudeFacade` (`generate()` e `generateWithContext()`) existem, mas não são utilizados para o processamento de canvas, dependendo de mecanismos mais simples de system prompt. Portanto, a hierarquia de 4 níveis está corretamente direcionada para o fluxo de canvas.

**Problema Menor Identificado e Correção Proposta (Debug Info):**
Durante esta revisão, identifiquei um problema menor relacionado às informações de debug (`debug_info`) retornadas por `public/api/canvas/submit.php`:

*   O `debug_info['system_prompt']` atualmente reflete apenas o `canvas_templates.system_prompt` (Nível 2), ou em alguns contextos, o prompt do usuário (`$userPrompt`), e não o **system prompt completo e concatenado de 4 níveis** que é efetivamente enviado à API Claude.
*   **Correção Proposta:** Modificar `public/api/canvas/submit.php` para buscar o conteúdo do campo `system_prompt_sent` da tabela `prompt_history` (usando o `history_id` retornado por `ClaudeFacade::generateForCanvas`). Este campo já armazena o system prompt completo que foi passado à API Claude, garantindo que o debug no frontend seja preciso.

---

## 2. Não-Regressão

**Pergunta de Claude:** "Com a mudança, canvas que NÃO têm Nível 0 definido (se a setting estiver vazia) devem funcionar identicamente. Verifique que o plano garante isso."

**Resposta de Gemini:** Sim, o plano **garante a não-regressão** para canvases que não têm o Nível 0 definido.

*   A função `getPortalSystemPrompt()` retornará uma string vazia (`''`) se a configuração `portal_system_prompt` não for encontrada na tabela `settings` ou em caso de erro na sua recuperação.
*   A condição `if (!empty($portalPrompt))` em `getCompleteSystemPrompt()` assegura que o `$portalPrompt` será adicionado ao array de prompts apenas se não estiver vazio.

Portanto, se o Nível 0 não estiver definido (i.e., `portal_system_prompt` estiver vazio), ele simplesmente não será incluído no system prompt final concatenado, e a hierarquia existente de 3 níveis funcionará identicamente.

---

## 3. Conflito `system_prompt` vs `api_params_override`

**Pergunta de Claude:** "O `system_prompt` que está no `api_params_override` do canvas 41 é diferente do que está na coluna `canvas_templates.system_prompt`? Se sim, qual deve prevalecer?"

**Resposta de Gemini:**
*   **São diferentes?** Sim, eles são significativamente diferentes.
    *   O `system_prompt` na coluna `canvas_templates.system_prompt` para o canvas ID 41 (`iatr-peticao-manus-test`) é: "Você é um advogado processualista sênior com 20+ anos de experiência em contencioso cível, trabalhista e tributário..." (um prompt de role-setting, específico do canvas - Nível 2).
    *   O `system_prompt` dentro de `api_params_override` para o mesmo canvas é um prompt muito mais longo e geral, contendo regras de identidade da Plataforma Sunyata, regras anti-alucinação, padrões de saída, etc. (elementos que se enquadram em Nível 0/1 - Portal/Vertical).

*   **Qual deve prevalecer?** De acordo com o plano de hierarquia de 4 níveis:
    1.  O `system_prompt` em `api_params_override` para o canvas ID 41 **deve ser removido** dessa coluna, conforme o plano.
    2.  O conteúdo desse `system_prompt` (o prompt mais longo e geral) **deve ser analisado e distribuído** para os níveis apropriados da nova hierarquia:
        *   Partes mais gerais (identidade, regras anti-alucinação, padrões de saída para a plataforma) devem ser consideradas para o novo **Nível 0 (`portal_system_prompt`)**.
        *   Partes específicas para assistência jurídica (mas não específicas da tarefa do canvas) poderiam ser movidas para o **Nível 1 (o `system_prompt` da vertical 'juridico')**.
    3.  O conteúdo existente da coluna `canvas_templates.system_prompt` para o canvas ID 41 (o prompt do "advogado processualista sênior") é específico da tarefa do canvas e **deve permanecer como Nível 2**.

Essa abordagem alinha-se ao objetivo do plano de separar o conteúdo do system prompt dos parâmetros da API e de estruturar a hierarquia de prompts de forma lógica, garantindo que os elementos mais gerais precedam os mais específicos.

---

## 4. Tabela `settings`

**Pergunta de Claude:** "Verifique no banco se a tabela `settings` existe e qual é sua estrutura: `DESCRIBE settings; SELECT * FROM settings LIMIT 10;` Se não existir, o plano precisa incluir o CREATE TABLE."

**Resposta de Gemini:** A tabela `settings` **existe** no banco de dados e possui a estrutura adequada (`id`, `setting_key`, `setting_value`, `data_type`, `description`, `is_public`, `updated_at`, `updated_by`). A coluna `setting_key` é `VARCHAR(100)` com um índice `UNIQUE`, e `setting_value` é do tipo `TEXT`, o que é perfeitamente adequado para armazenar o `portal_system_prompt`.

Portanto, **nenhuma declaração `CREATE TABLE` é necessária**. A operação `INSERT` (com `ON DUPLICATE KEY UPDATE`) proposta no plano para `portal_system_prompt` funcionará corretamente.

---

## 5. Cálculo de Token Cost

**Perguntas de Claude:**
*   "Tokens médios do Nível 1 (vertical) para as verticais ativas"
*   "Tokens médios do Nível 2 (canvas) dos canvas mais usados"
*   "Impacto de adicionar ~200 tokens de Nível 0 em cada request"

**Resposta de Gemini:** As estimativas abaixo utilizam uma proporção aproximada de 1 token para cada 4 caracteres, com base nos dados reais do banco.

*   **Tokens médios do Nível 1 (vertical):**
    *   Varia de **~75 tokens** (para `juridico`, usando defaults do arquivo `config/verticals.php`) a **~382.5 tokens** (para `iatr`, que sobrescreve o `system_prompt` via `verticals.config` no banco de dados com um prompt mais longo e geral).
    *   Uma média representativa, considerando esses dois exemplos, seria de aproximadamente **~228 tokens**.

*   **Tokens médios do Nível 2 (canvas):**
    *   Com base nos 15 `canvas_templates` ativos mais longos (ordenados por `sp_len`), o comprimento varia de **~244 tokens** (`juridico-contratos-html-manus-test`) a **~830 tokens** (`juridico-geral`).
    *   A média para esses 15 canvases é de aproximadamente **~306 tokens**.

*   **Impacto de adicionar ~200 tokens de Nível 0:**
    *   Assumindo os custos de token do Claude 3.5 Sonnet (aproximadamente $3/milhão de tokens de entrada), adicionar 200 tokens de Nível 0 resultaria em um custo adicional de cerca de **$0.0006 por request**.
    *   Este é um aumento de custo muito pequeno e aceitável.

---

## 6. Prompt Dilution Risk

**Pergunta de Claude:** "Identifique canvas com system prompts (Nível 2) excessivamente longos. Se Nível 0 (~200 tokens) + Nível 1 (~500 tokens) + Nível 2 (?) > 2000 tokens, há risco de diluição para Haiku."

**Resposta de Gemini:**
*   **Limiar de Risco:** Claude Haiku possui uma janela de contexto de 200.000 tokens. O limiar de 2000 tokens para "risco de diluição" é uma diretriz para manter a eficiência e o foco do modelo.
*   **System Prompt Combinado (Pior Caso):**
    *   Nível 0 (proposto): ~200 tokens
    *   Nível 1 (máximo observado - `iatr`): ~382.5 tokens
    *   Nível 2 (máximo observado - `juridico-geral`): ~830 tokens
    *   **Total combinado:** ~1412.5 tokens.

Este comprimento combinado de aproximadamente 1412.5 tokens está **abaixo do limiar de 2000 tokens** sugerido para risco de diluição. Mesmo com o maior system prompt de Nível 2 atualmente existente, a adição dos Níveis 0 e 1 (com base nos dados atuais) mantém o total dentro de limites seguros para o Claude Haiku.

**Conclusão:** **Não há risco imediato de diluição de prompt para Claude Haiku** com a implementação proposta e os dados atuais. No entanto, reitero a recomendação do plano de que o Manus revise os system prompts de Nível 2 para remover redundâncias (que serão cobertas pelos Níveis 0 e 1), o que otimizará ainda mais o uso de tokens e a clareza para o modelo.

---

## 7. Bug dos Pending Records (`prompt_history`)

**Perguntas de Claude:** "Há 3 registros em `prompt_history` com `status = 'pending'` (IDs 122-124) que nunca foram finalizados (timeout do web server). Investigue se há um mecanismo de cleanup ou se ficam pendentes para sempre. Também verifique qual é o timeout do PHP no contexto web (FPM/CGI) no Hostinger."

**Resposta de Gemini:**
*   **Confirmação dos Registros Pendentes:** Atualmente, existem **15 registros** com `status = 'pending'` na tabela `prompt_history`. Isso indica que mais registros se acumularam desde sua observação inicial.
*   **Timeout do PHP:** A verificação do `max_execution_time` do PHP no servidor Hostinger (`php -i | grep max_execution_time`) retornou `max_execution_time => 0 => 0`. Isso significa que o PHP **não tem um limite de tempo** de execução configurado por essa diretiva.
*   **Causa dos Registros Pendentes:** A persistência de registros com `status = 'pending'` (criados no início da requisição e nunca atualizados para `success` ou `error`) sugere que o script PHP está sendo terminado **prematuramente** antes que a função `updateHistoryRecord()` possa ser executada (mesmo nos blocos `catch` de erro). As causas prováveis para essa terminação prematura são:
    1.  **Timeout do servidor web (Nginx/Apache):** O proxy que atende às requisições (Nginx ou Apache, à frente do PHP-FPM) pode estar encerrando a conexão após um determinado período, independentemente do status do script PHP.
    2.  **Erro de Out Of Memory (OOM):** O script pode estar excedendo o limite de memória permitido e sendo encerrado pelo sistema operacional ou pelo PHP.
    3.  **Erro Fatal Não Tratado:** Um erro fatal não capturado por um bloco `try-catch` pode estar encerrando a execução do script abruptamente.
*   **Mecanismo de Cleanup:** Atualmente, **não existe um mecanismo de limpeza explícito** no código para registros `pending` na tabela `prompt_history`. Eles permanecerão nesse status indefinidamente.

**Recomendações:**
1.  **Implementar um Mecanismo de Cleanup Agendado:** Desenvolver uma tarefa agendada (cron job) que execute um script periodicamente para identificar registros na tabela `prompt_history` com `status = 'pending'` que sejam mais antigos que um determinado limite (ex: 5-10 minutos). Estes registros devem ter seu `status` atualizado para `failed` ou `timeout`, com uma mensagem de erro apropriada. Isso evitará o acúmulo contínuo e fornecerá uma auditoria mais precisa.
2.  **Investigar Logs do Servidor:** É altamente recomendável que o administrador do sistema verifique os logs de acesso e erro do servidor web (Nginx/Apache) e os logs do PHP-FPM no ambiente Hostinger. Isso ajudará a identificar a causa raiz das terminações prematuras do script (`/api/canvas/submit.php`) que estão levando aos registros pendentes. Procure por erros de memória, timeouts de gateway ou erros fatais específicos.
