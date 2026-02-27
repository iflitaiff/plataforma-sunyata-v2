# Instruções — Formulário de Análise IATR v4
# De: Claude Chat (Arquiteto) → Claude Code (Executor)
# Data: 25/02/2026
# Contexto: Evolução do formulário de submissão de análise na página de detalhes do edital

---

## Motivação

O workflow `IATR - Análise de Edital v3 (Modular)` está publicado no N8N e funciona. Porém, o formulário que dispara a análise é primitivo — o portal faz POST para `/webhook/iatr/analisar` com `{ edital_id, tipo_analise }` e pronto.

O cliente (Maurício, Investiplan) é um analista de licitações que já iterou 14+ versões dos prompts por conta própria e criou um agente no Copilot. Ele precisa de:

1. **Controle de profundidade** — a maioria das análises não precisa das 12 seções completas; ele faz triagem de dezenas de editais/dia
2. **Instruções complementares por execução** — ajustes situacionais sem criar nova versão do prompt
3. **Transparência de custo/tempo** — saber antes de clicar quanto vai gastar
4. **Futuramente: edição dos prompts base** — ele deve poder ajustar os TASK prompts (não o SYSTEM_BASE)

Este documento cobre as tarefas 1-3. A tarefa 4 (templates editáveis no banco) fica para uma fase posterior.

---

## Dependência

Este formulário depende da **página de detalhes do edital** (tarefa 3 do plano atual). Se a página ainda não existe, este documento serve como spec para quando ela for criada. O formulário é um componente dessa página.

---

## 1. Formulário de Análise — Layout e Comportamento

### 1.1 Localização

Na página de detalhes do edital (`/iatr/edital?id={id}`), abaixo dos dados do edital e acima da área de resultado. Visível sempre que `status_analise` != `em_analise`.

### 1.2 Campos

```
┌──────────────────────────────────────────────────────┐
│  SOLICITAR ANÁLISE                                   │
│                                                      │
│  Tipo de análise:                                    │
│  [Resumo Executivo ▾]                                │
│  (dropdown com: Resumo Executivo, Habilitação,       │
│   Verificação de Edital, Contratos, SG Contrato)     │
│                                                      │
│  Profundidade:                                       │
│  [○ Triagem] [● Resumo] [○ Completa]                │
│                                                      │
│  Instruções complementares (opcional):               │
│  ┌──────────────────────────────────────────────┐    │
│  │ Ex: "Foco em requisitos de ar condicionado   │    │
│  │ e prazos de entrega"                         │    │
│  └──────────────────────────────────────────────┘    │
│                                                      │
│  ┌─────────────────────────────────────────┐         │
│  │ Modelo: Claude Haiku 4.5               │         │
│  │ Custo estimado: ~R$ 0,01               │         │
│  │ Tempo estimado: ~15 segundos           │         │
│  └─────────────────────────────────────────┘         │
│                                                      │
│  [Analisar]                                          │
└──────────────────────────────────────────────────────┘
```

### 1.3 Dropdown — Tipo de análise

Corresponde ao `tipo_analise` existente no v3:

| Valor | Label no dropdown |
|-------|-------------------|
| `resumo_executivo` | Resumo Executivo |
| `habilitacao` | Documentação de Habilitação |
| `verifica_edital` | Verificação de Edital |
| `contratos` | Contratos e Aditivos |
| `sg_contrato` | Seguro-Garantia |

Default: `resumo_executivo`.

### 1.4 Radio buttons — Profundidade

3 níveis. O nível controla modelo, max_tokens e variante do prompt:

| Nível | Label | Modelo | max_tokens | Comportamento |
|-------|-------|--------|------------|---------------|
| `triagem` | Triagem | `claude-haiku-4-5` | 2000 | 1 parágrafo + 3-5 pontos-chave. Resposta direta: vale ou não vale investir tempo neste edital |
| `resumo` | Resumo | `claude-sonnet-4-5` | 8000 | Seções 1, 2, 3, 10, 12 apenas (essenciais + valores + riscos). ~2-3 páginas |
| `completa` | Completa | `claude-sonnet-4-5` | 16000 | Todas as seções do TASK prompt. Comportamento atual do v3 |

Default: `resumo` (melhor equilíbrio custo/valor para triagem diária).

**Nota sobre interação tipo × profundidade:**
- Para `resumo_executivo` e `habilitacao`: os 3 níveis fazem sentido
- Para `verifica_edital`, `contratos`, `sg_contrato`: profundidade não se aplica (são tarefas mais curtas/estruturadas). Nestes casos, esconder o seletor de profundidade e usar o comportamento padrão do v3 (já otimizado com Haiku)

### 1.5 Textarea — Instruções complementares

- Placeholder: `"Ex: Foco em requisitos de ar condicionado e prazos de entrega"`
- Max length: 1000 caracteres
- Opcional (pode ficar vazio)
- Persistido no banco junto com o resultado da análise

### 1.6 Painel de estimativas

Atualiza dinamicamente conforme tipo + profundidade selecionados. Valores estimados:

```javascript
const ESTIMATIVAS = {
  triagem:  { modelo: 'Claude Haiku 4.5',  custo: 'R$ 0,01',  tempo: '~15 segundos' },
  resumo:   { modelo: 'Claude Sonnet 4.5', custo: 'R$ 0,05',  tempo: '~45 segundos' },
  completa: { modelo: 'Claude Sonnet 4.5', custo: 'R$ 0,12',  tempo: '~90 segundos' }
};
```

Estes são estimativas para exibição — o custo real é calculado pelo workflow e salvo no banco.

### 1.7 Botão "Analisar"

- Desabilitado durante `status_analise = 'em_analise'`
- Ao clicar: muda para "Analisando..." com spinner
- Faz POST para o webhook
- Inicia polling de `status_analise` (já implementado)

### 1.8 Dados disponíveis e Modo automático

O formulário deve indicar visualmente o que está disponível antes da submissão:

```
┌─────────────────────────────────────────┐
│ Dados disponíveis para análise:         │
│ ✅ Metadados do edital                  │
│ ✅ Itens (6 itens)                      │
│ ❌ Texto completo do edital             │
│                                         │
│ ⚠️ Análise será baseada em metadados   │
│ e itens da API PNCP.                    │
└─────────────────────────────────────────┘
```

Isto depende dos dados enriquecidos (tarefa 1). Se os campos `pncp_itens` e `texto_completo` existirem no banco, a lógica é:

```javascript
const temTexto = edital.texto_completo && edital.texto_completo.length > 500;
const temItens = edital.pncp_itens && edital.pncp_itens.length > 0;

if (!temTexto && !temItens) {
  // Modo C: desabilitar botão, mostrar mensagem
  // "Dados insuficientes para análise. Documentos não disponíveis."
}
```

Se a tarefa 1 (enriquecimento) ainda não estiver implementada quando este formulário for construído, ignorar esta seção e tratar tudo como tendo metadados apenas.

---

## 2. Alterações no POST para o Webhook

### 2.1 Payload atual

```json
{
  "edital_id": 148,
  "tipo_analise": "resumo_executivo"
}
```

### 2.2 Payload novo

```json
{
  "edital_id": 148,
  "tipo_analise": "resumo_executivo",
  "nivel_profundidade": "resumo",
  "instrucoes_complementares": "Foco em requisitos de ar condicionado e prazos"
}
```

Campos novos são opcionais — o workflow deve funcionar sem eles (backward-compatible):
- `nivel_profundidade` ausente → default `"completa"` (comportamento atual)
- `instrucoes_complementares` ausente ou vazio → ignorar

---

## 3. Alterações no Workflow N8N v3

### 3.1 Nó "Validate Input" — aceitar novos campos

Adicionar ao body parsing:

```javascript
const nivelProfundidade = body.nivel_profundidade || 'completa';
const instrucoesComplementares = body.instrucoes_complementares || null;

const NIVEIS_VALIDOS = ['triagem', 'resumo', 'completa'];
if (!NIVEIS_VALIDOS.includes(nivelProfundidade)) {
  throw new Error(`nivel_profundidade must be one of: ${NIVEIS_VALIDOS.join(', ')}`);
}

return [{ json: {
  edital_id: editalId,
  tipo_analise: tipoAnalise,
  nivel_profundidade: nivelProfundidade,
  instrucoes_complementares: instrucoesComplementares,
  contexto_empresa: contextoEmpresa
} }];
```

### 3.2 Nó "Build Analysis Prompt" — roteamento por profundidade

O MODEL_MAP e MAX_TOKENS_MAP passam a considerar o nível:

```javascript
// Override de modelo/tokens por nível de profundidade
// Só se aplica a resumo_executivo e habilitacao
const NIVEL_OVERRIDES = {
  triagem: {
    model: 'claude-haiku-4-5',
    max_tokens: 2000
  },
  resumo: {
    model: 'claude-sonnet-4-5',
    max_tokens: 8000
  }
  // 'completa' usa os valores do MODEL_MAP/MAX_TOKENS_MAP existentes
};

const nivelProfundidade = edital.nivel_profundidade || 'completa';
const aplicaNivel = ['resumo_executivo', 'habilitacao'].includes(tipoAnalise);

let selectedModel, maxTokens;
if (aplicaNivel && NIVEL_OVERRIDES[nivelProfundidade]) {
  selectedModel = NIVEL_OVERRIDES[nivelProfundidade].model;
  maxTokens = NIVEL_OVERRIDES[nivelProfundidade].max_tokens;
} else {
  selectedModel = MODEL_MAP[tipoAnalise] || 'claude-sonnet-4-5';
  maxTokens = MAX_TOKENS_MAP[tipoAnalise] || 16000;
}
```

### 3.3 Nó "Build Analysis Prompt" — prompt de triagem

Adicionar ao objeto TASKS:

```javascript
// Prompt especial para triagem — conciso, direto ao ponto
const TRIAGEM_PREFIX = `TAREFA: TRIAGEM RÁPIDA DO EDITAL

Produza uma avaliação concisa para triagem. Formato OBRIGATÓRIO:

PARECER: [RELEVANTE / PARCIALMENTE RELEVANTE / NÃO RELEVANTE]

SÍNTESE: Um parágrafo de no máximo 5 linhas descrevendo o objeto, valores e prazo.

PONTOS-CHAVE:
- [3 a 5 bullet points com as informações mais críticas para decisão]

ALERTAS (se houver):
- [Exigências incomuns, prazos apertados, riscos evidentes]

Não produzir mais do que isso. A brevidade é essencial.`;
```

E na lógica de montagem do prompt:

```javascript
let taskPrompt;
if (nivelProfundidade === 'triagem' && aplicaNivel) {
  taskPrompt = TRIAGEM_PREFIX;
} else if (nivelProfundidade === 'resumo' && aplicaNivel) {
  // Usar o TASK prompt existente mas com instrução de focar nas seções essenciais
  taskPrompt = TASKS[tipoAnalise] + '\n\nINSTRUÇÃO ADICIONAL: Priorize as seções 1, 2, 3, 10 e 12. As demais seções podem ser omitidas ou resumidas em 1-2 linhas se os dados não forem suficientes para detalhamento.';
} else {
  taskPrompt = TASKS[tipoAnalise];
}
```

### 3.4 Nó "Build Analysis Prompt" — injeção de instruções complementares

O campo `contexto_empresa` já é injetado no final do user message. Usar o mesmo padrão:

```javascript
// Instruções complementares do usuário (ad hoc, por execução)
const instrucoesComplementares = edital.instrucoes_complementares;
if (instrucoesComplementares && instrucoesComplementares.trim().length > 0) {
  userMessage += `\n\n---\n\nINSTRUÇÕES COMPLEMENTARES DO USUÁRIO:\n${instrucoesComplementares.trim()}`;
}

// Contexto da empresa (já existente)
if (contextoEmpresa) {
  userMessage += `\n\n---\n\nCONTEXTO DA EMPRESA LICITANTE:\n${contextoEmpresa}`;
}
```

### 3.5 Nó "Save Analysis Result" — persistir novos campos

Adicionar ao UPDATE:

```sql
UPDATE pncp_editais SET
  status_analise = '{{ $json.status }}',
  analise_resultado = COALESCE(analise_resultado, '{}'::jsonb) || $TAG${{ $json.resultado }}$TAG$::jsonb,
  analise_modelo = '{{ $json.model }}',
  analise_tipo = '{{ $json.tipo_analise }}',
  analise_nivel = '{{ $json.nivel_profundidade }}',
  analise_instrucoes_complementares = $TAG${{ $json.instrucoes_complementares || '' }}$TAG$,
  analise_tokens = {{ $json.tokens }},
  analise_tokens_input = {{ $json.tokens_input }},
  analise_tokens_output = {{ $json.tokens_output }},
  analise_custo_usd = {{ $json.custo_usd }},
  analise_concluida_em = NOW(),
  analise_com_texto_completo = {{ $json.has_full_text }},
  updated_at = NOW()
WHERE id = {{ $json.edital_id }};
```

**Novos campos no banco** (migration):

```sql
ALTER TABLE pncp_editais
  ADD COLUMN IF NOT EXISTS analise_nivel VARCHAR(20),
  ADD COLUMN IF NOT EXISTS analise_instrucoes_complementares TEXT;
```

### 3.6 Nó "Process LLM Response" — passar novos campos

Adicionar ao return:

```javascript
return [{ json: {
  // ... campos existentes ...
  nivel_profundidade: promptData.nivel_profundidade || 'completa',
  instrucoes_complementares: promptData.instrucoes_complementares || null
}}];
```

### 3.7 Nó "Format Response" — incluir nível na resposta

```javascript
return [{ json: {
  success: parsed.status === 'concluida',
  edital_id: parsed.edital_id,
  tipo_analise: parsed.tipo_analise,
  nivel_profundidade: parsed.nivel_profundidade,
  modelo: parsed.model,
  tokens_input: parsed.tokens_input,
  tokens_output: parsed.tokens_output,
  custo_usd: parsed.custo_usd,
  com_texto_completo: parsed.has_full_text,
  versao_prompt: '3.0',
  timestamp: new Date().toISOString()
} }];
```

---

## 4. Resultado da Análise — Exibição por Nível

### 4.1 Triagem

Resultado curto. Exibir inline na página, sem necessidade de scroll ou expansão. Formato visual sugerido:

```
┌──────────────────────────────────────────────────────┐
│ 🟢 RELEVANTE                                        │
│                                                      │
│ Pregão eletrônico para aquisição de 15.880           │
│ microcomputadores e notebooks com garantia de 60     │
│ meses para o Estado do RJ. Valor estimado            │
│ R$ 171 milhões. Abertura 26/02/2026.                 │
│                                                      │
│ • Atestados de 50% do quantitativo — alta barreira   │
│ • Vedação a consórcios e subcontratação              │
│ • Amostras físicas obrigatórias com teste            │
│ • Garantia on-site com SLA de dia útil seguinte      │
│                                                      │
│ Haiku 4.5 | 342 tokens | R$ 0,002 | 8s              │
│                                                      │
│ [Aprofundar → Resumo]  [Aprofundar → Completa]      │
└──────────────────────────────────────────────────────┘
```

Os botões "Aprofundar" resubmetem com `nivel_profundidade` maior, mantendo o `tipo_analise`.

### 4.2 Resumo e Completa

Exibir o texto do relatório com formatação (o output segue a estrutura de numeração hierárquica). Usar o mesmo componente de exibição que já existe ou que for criado para renderizar o `analise_resultado`.

Adicionar footer com metadados:

```
Sonnet 4.5 | 4.832 tokens | R$ 0,07 | 42s | Análise parcial (sem texto do edital)
```

---

## 5. Schema — Resumo das Alterações no Banco

```sql
-- Novos campos na tabela pncp_editais
ALTER TABLE pncp_editais
  ADD COLUMN IF NOT EXISTS analise_nivel VARCHAR(20),
  ADD COLUMN IF NOT EXISTS analise_instrucoes_complementares TEXT;

-- Para futuro (templates editáveis — NÃO implementar agora):
-- CREATE TABLE prompt_templates (
--   id SERIAL PRIMARY KEY,
--   user_id INTEGER REFERENCES users(id),
--   nome VARCHAR(100) NOT NULL,
--   tipo VARCHAR(30) NOT NULL,  -- resumo_executivo, habilitacao, etc.
--   versao INTEGER DEFAULT 1,
--   system_prompt TEXT,          -- NULL = usar SYSTEM_BASE do workflow
--   task_prompt TEXT NOT NULL,
--   ativo BOOLEAN DEFAULT true,
--   created_at TIMESTAMPTZ DEFAULT NOW()
-- );
```

---

## 6. Critérios de Aceite

1. ✅ Formulário com dropdown de tipo + radio de profundidade + textarea de instruções + painel de estimativas
2. ✅ Profundidade escondida para tipos que não se aplicam (verifica_edital, contratos, sg_contrato)
3. ✅ Painel de estimativas atualiza dinamicamente ao mudar tipo/profundidade
4. ✅ POST para webhook inclui `nivel_profundidade` e `instrucoes_complementares`
5. ✅ Workflow v3 roteia corretamente: triagem→Haiku/2000, resumo→Sonnet/8000, completa→Sonnet/16000
6. ✅ Triagem retorna resposta curta no formato especificado (parecer + síntese + pontos-chave)
7. ✅ Instruções complementares aparecem no prompt enviado ao LLM
8. ✅ Novos campos persistidos no banco (`analise_nivel`, `analise_instrucoes_complementares`)
9. ✅ Backward-compatible: POST sem novos campos funciona como antes (default: completa, sem instruções)
10. ✅ Botões "Aprofundar" na exibição de triagem permitem resubmeter com nível maior

---

## 7. O Que NÃO Fazer Agora

- **Templates editáveis no banco** — fica para fase posterior. Os TASK prompts permanecem no Code Node do workflow
- **Edição do SYSTEM_BASE** — nunca será editável pelo usuário
- **Histórico de análises** — cada submissão sobrescreve a anterior. Histórico é feature futura
- **Chat/conversa sobre o edital** — fora de escopo

---

## 8. Prioridade de Implementação

Esta tarefa depende de:
- Tarefa 1 (enriquecimento na captação) — para a seção 1.8 (indicador de dados disponíveis)
- Tarefa 3 (página de detalhes) — o formulário vive dentro dessa página

Se as tarefas 1 e 3 já estiverem prontas, implementar nesta ordem:
1. Migration (ALTER TABLE)
2. Formulário no portal (HTML/JS)
3. Alterações no workflow v3 (Validate Input, Build Analysis Prompt, Process LLM Response, Save Analysis Result, Format Response)
4. Exibição do resultado com metadados e botões "Aprofundar"
5. Testes end-to-end: triagem de um edital real, depois aprofundamento
