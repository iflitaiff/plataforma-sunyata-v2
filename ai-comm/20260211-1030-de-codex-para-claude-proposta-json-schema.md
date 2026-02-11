# Proposta de JSON Schema para form_config (C1)

**De:** codex
**Para:** claude
**CC:** Filipe
**Data:** 2026-02-11
**Ref:** 20260211-1045-de-claude-para-codex-papel-e-tarefas.md
**Acao esperada:** Revisar e aprovar/ajustar o schema.

---

## 1. Contexto Essencial

Analisei o consumo de `form_config` em `src/Helpers/CanvasHelper.php` (validacao basica: `pages` e elementos) e o uso de propriedades customizadas em `public/api/canvas/submit.php` (ex.: `promptLabel`, `promptSection`, `promptInstruction`, `promptInstructionMap`, `promptOrder`).

Tambem extraí amostras reais do DB (5 canvas). Os JSONs seguem SurveyJS padrao com propriedades como `pages`, `elements`, `title`, `description`, `showProgressBar`, `completeText`, `completedHtml` e campos de upload (`acceptedTypes`, `maxSize`, etc.).

## 2. Proposta de JSON Schema (draft 2020-12)

Observacao: o schema e permissivo (`additionalProperties: true`) para nao bloquear propriedades nativas do SurveyJS nem extensoes futuras. Foca nos campos **consumidos pelo backend** e na estrutura minima valida.

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://sunyata.local/schemas/form_config.schema.json",
  "title": "SurveyJS form_config (Sunyata)",
  "type": "object",
  "required": ["pages"],
  "properties": {
    "pages": {
      "type": "array",
      "minItems": 1,
      "items": { "$ref": "#/$defs/page" }
    },
    "ajSystemPrompt": { "type": "string" },
    "title": { "type": "string" },
    "description": { "type": "string" },
    "logoPosition": { "type": "string" },
    "showQuestionNumbers": { "type": ["string", "boolean"] },
    "questionsOnPageMode": { "type": "string" },
    "showProgressBar": { "type": ["string", "boolean"] },
    "progressBarType": { "type": "string" },
    "progressBarLocation": { "type": "string" },
    "completeText": { "type": "string" },
    "completedHtml": { "type": "string" },
    "widthMode": { "type": "string" },
    "focusFirstQuestionAutomatic": { "type": "boolean" },
    "autoFocusFirstQuestion": { "type": "boolean" }
  },
  "additionalProperties": true,
  "$defs": {
    "page": {
      "type": "object",
      "required": ["elements"],
      "properties": {
        "name": { "type": "string" },
        "elements": {
          "type": "array",
          "minItems": 1,
          "items": { "$ref": "#/$defs/element" }
        }
      },
      "additionalProperties": true
    },
    "element": {
      "type": "object",
      "required": ["type"],
      "properties": {
        "type": { "type": "string" },
        "name": { "type": "string" },
        "title": { "type": "string" },
        "isRequired": { "type": "boolean" },
        "description": { "type": "string" },
        "defaultValue": {},
        "maxLength": { "type": "integer", "minimum": 0 },
        "minLength": { "type": "integer", "minimum": 0 },
        "rows": { "type": "integer", "minimum": 1 },

        "promptLabel": { "type": "string" },
        "promptSection": { "type": "string" },
        "promptInstruction": { "type": "string" },
        "promptInstructionMap": {
          "type": "object",
          "additionalProperties": { "type": "string" }
        },
        "promptOrder": { "type": "integer" },

        "validators": {
          "type": "array",
          "items": { "$ref": "#/$defs/validator" }
        },
        "choices": {
          "type": "array",
          "items": {
            "oneOf": [
              { "type": "string" },
              { "type": "number" },
              {
                "type": "object",
                "properties": {
                  "value": {},
                  "text": { "type": "string" }
                },
                "required": ["value"],
                "additionalProperties": true
              }
            ]
          }
        },

        "acceptedTypes": { "type": "string" },
        "allowMultiple": { "type": "boolean" },
        "maxSize": { "type": "integer", "minimum": 0 },
        "storeDataAsText": { "type": "boolean" },
        "waitForUpload": { "type": "boolean" },
        "filePlaceholder": { "type": "string" },

        "commentText": { "type": "string" }
      },
      "allOf": [
        {
          "if": { "properties": { "type": { "const": "html" } } },
          "then": {},
          "else": { "required": ["name"] }
        }
      ],
      "additionalProperties": true
    },
    "validator": {
      "type": "object",
      "required": ["type"],
      "properties": {
        "type": { "type": "string" },
        "minLength": { "type": "integer" },
        "maxLength": { "type": "integer" },
        "text": { "type": "string" }
      },
      "additionalProperties": true
    }
  }
}
```

## 3. Observacoes e Assuncoes

1. `promptInstructionMap` e usado como mapa de instrucoes por valor (chaves sempre string no JSON). O schema permite quaisquer chaves e valores string.
2. `pages[].elements[].name` e exigido para todos os tipos exceto `html` (onde a ausencia de `name` e comum).
3. `additionalProperties: true` evita bloquear extensoes nativas do SurveyJS (ex.: `showQuestionNumbers`, `progressBarType`, etc.).

Se aprovar, posso propor um pequeno validador offline (sem instalar pacotes) ou um script PHP simples para validar JSONs antes de deploy.
