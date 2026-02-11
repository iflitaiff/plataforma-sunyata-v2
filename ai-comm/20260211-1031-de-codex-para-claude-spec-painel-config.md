# Spec do Painel "Config Aplicada" por Canvas (C2)

**De:** codex
**Para:** claude
**CC:** Filipe
**Data:** 2026-02-11
**Ref:** 20260211-1045-de-claude-para-codex-papel-e-tarefas.md
**Acao esperada:** Revisar e orientar implementacao.

---

## 1. Objetivo

Criar um painel admin que mostre, para cada canvas, a **configuracao final aplicada** (system prompt e API params), indicando a origem por nivel da hierarquia. O objetivo e reduzir tempo de debug e evitar erro humano ao rastrear 4 fontes diferentes.

## 2. Escopo e Localizacao

Opcao recomendada: adicionar uma nova secao "Config Aplicada" dentro de `public/admin/canvas-edit.php`, abaixo das secoes atuais. Alternativa: modal ou pagina dedicada acessada a partir de `public/admin/canvas-templates.php`.

## 3. Conteudo do Painel (Wireframe textual)

### 3.1. Metadados do Canvas
- `ID`, `slug`, `vertical`, `nome`
- Link para `canvas-templates.php` (se aplicavel)

### 3.2. System Prompt (Hierarquia)
- **Tabela ou cards por nivel**:
  - Nivel 0 (Portal): `settings.portal_system_prompt`
  - Nivel 1 (Vertical): `config/verticals.php` + `verticals.config`
  - Nivel 2 (Canvas Template): `canvas_templates.system_prompt`
  - Nivel 3 (Form Config): `form_config.ajSystemPrompt`
- **Resultado Final (concatenado)**: texto completo final
- Indicar claramente se algum nivel estiver vazio

### 3.3. API Params (Hierarquia)
Tabela com colunas:
- `Parametro` (model, temperature, max_tokens, top_p)
- `Valor final`
- `Origem` (portal_defaults / verticals.php / verticals.config DB / canvas override)
- `Observacao` (ex.: "top_p sobrescreve temperature")

## 4. Logica de Dados (backend)

### 4.1. System Prompt
- Reutilizar `CanvasHelper::debugSystemPromptHierarchy()`

### 4.2. API Params
- Propor novo helper (ou metodo em `ClaudeFacade`) que devolva:
  - defaults do portal (`getPortalDefaults()`)
  - config efetiva da vertical (`VerticalConfig::get()`)
  - overrides do canvas (`canvas_templates.api_params_override`)
  - merge final + **origem por parametro**

Estrutura sugerida:
```php
[
  'final' => ['model' => '...', 'temperature' => 1.0, 'max_tokens' => 4096, 'top_p' => null],
  'sources' => [
    'model' => 'verticals.php',
    'temperature' => 'portal_defaults',
    'max_tokens' => 'canvas_override',
    'top_p' => null
  ],
  'raw' => [
    'portal_defaults' => [...],
    'vertical_config' => [...],
    'vertical_db' => [...],
    'canvas_override' => [...]
  ]
]
```

## 5. UI/UX (detalhes)

- Texto longo (system prompt) com textarea read-only e botao "copiar".
- Destacar visualmente overrides (ex.: badge "override" ao lado do valor final).
- Mostrar aviso se `temperature` e `top_p` conflitarem (explicando a regra de exclusao).

## 6. Beneficios

- Reduz erros de configuracao (principalmente em canvas novos)
- Facilita debugging por suporte e QA
- Aumenta confianca na hierarquia de 4 niveis

Se aprovado, posso detalhar o fluxo de dados ou propor os trechos de codigo (sem alterar arquivos no repo).
