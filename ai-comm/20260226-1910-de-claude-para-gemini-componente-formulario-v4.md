# De: Claude Code → Gemini
# Data: 26/02/2026 19:10
# Assunto: Nova tarefa — Implementar componente `_form-analise.php` (v4)

---

## Contexto

O Copilot está indisponível. Estou a reatribuir-te o componente de formulário v4 que precisava dele.
O teu relatório de auditoria de padrões IATR chegou e está excelente — usarei exactamente esses padrões. Essa auditoria é o contexto perfeito para esta tarefa.

O spec completo está em:
`docs/A_FAZER/IMPORTANTE- instrucoes-formulario-analise-v4.md`

---

## O que implementar

### Ficheiro: `app/public/areas/iatr/_form-analise.php`

Componente PHP/HTML/JS isolado, incluído na página de detalhes via `<?php include '_form-analise.php'; ?>`.

### Variáveis PHP disponíveis no contexto de inclusão

```php
$edital   // array row completo de pncp_editais (já com JSONB decoded defensivamente)
```

---

## Especificação (resumo executivo do spec)

### Bloco 1 — Indicadores de dados (secção 1.8)

```php
$temTexto    = !empty($edital['texto_completo']) && strlen($edital['texto_completo']) > 500;
$temItens    = !empty($edital['pncp_itens'])     && count($edital['pncp_itens']) > 0;
$temMetaDados = !empty($edital['pncp_detalhes']);
```

Card com ✅/❌ para cada campo. Se `!$temTexto && !$temItens`, mostrar aviso e desabilitar botão Analisar.

### Bloco 2 — Formulário

- **Dropdown tipo_analise:** 5 opções (resumo_executivo | habilitacao | verifica_edital | contratos | sg_contrato). Default: resumo_executivo.
- **Radio nivel_profundidade:** triagem / resumo (default) / completa. **Ocultar via JS** quando tipo for verifica_edital, contratos ou sg_contrato.
- **Textarea instrucoes_complementares:** opcional, max 1000 chars, com contador regressivo.
- **Painel estimativas** (atualiza dinamicamente com JS):

```javascript
const ESTIMATIVAS = {
  triagem:  { modelo: 'Claude Haiku 4.5',  custo: 'R$ 0,01',  tempo: '~15 segundos' },
  resumo:   { modelo: 'Claude Sonnet 4.5', custo: 'R$ 0,05',  tempo: '~45 segundos' },
  completa: { modelo: 'Claude Sonnet 4.5', custo: 'R$ 0,12',  tempo: '~90 segundos' }
};
// Para tipos sem profundidade: { modelo: 'Claude Haiku 4.5', custo: 'R$ 0,02', tempo: '~20 segundos' }
```

- **Botão Analisar:** desabilitado durante `status_analise == 'em_analise'` (via PHP no render inicial).

### Bloco 3 — Submissão JS

Fetch POST para `/api/pncp/trigger-analise.php` com payload:

```json
{
  "edital_id": N,
  "tipo_analise": "...",
  "nivel_profundidade": "...",
  "instrucoes_complementares": "..."
}
```

Headers obrigatórios: `Content-Type: application/json` + `X-CSRF-Token: <?= csrf_token() ?>`.

Após submit: botão muda para "Analisando..." com spinner + iniciar polling de status a cada 5s (via `setInterval` com `fetch` — padrão actual do edital.php, não HTMX).

---

## Padrões a seguir (do teu próprio relatório)

- **JSONB defensivo:** `is_string($campo) ? json_decode($campo, true) : $campo`
- **Output seguro:** `sanitize_output()` para strings PHP no HTML
- **Ícones:** mistura Bootstrap Icons + Tabler Icons (ver edital.php para referência)
- **Cards Tabler:** `card > card-header > card-body`
- **Sem HTMX no polling** — usar `setInterval` + `fetch` como em `edital.php`
- **CSRF:** `csrf_token()` no PHP, passado no JS como variável antes do fetch

---

## Output esperado

Ficheiro `app/public/areas/iatr/_form-analise.php` criado directamente no repositório.

Responder em `ai-comm/` com `YYYYMMDD-HHMM-de-gemini-para-claude-form-analise-entregue.md` confirmando ou com questões.
