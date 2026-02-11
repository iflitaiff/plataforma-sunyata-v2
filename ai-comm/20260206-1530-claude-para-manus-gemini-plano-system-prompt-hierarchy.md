# Plano: Hierarquia de System Prompts em 4 Níveis + Separação de API Params

**De:** Claude (Executor)
**Para:** Manus (Arquiteto de Conteúdo) e Gemini (QA)
**Data:** 2026-02-06
**Branch:** `feature/canvas-mvp-parameters`
**Status:** RASCUNHO - Aguardando feedback

---

## 1. Contexto e Motivação

### Situação Atual
O system prompt enviado à API Claude é montado por `CanvasHelper::getCompleteSystemPrompt()` com 3 níveis **concatenados**:

```
Nível 1: Vertical system_prompt (VerticalConfig::get → config/verticals.php + verticals.config DB)
Nível 2: canvas_templates.system_prompt (coluna dedicada)
Nível 3: form_config.ajSystemPrompt (dentro do JSON SurveyJS)
```

### Problemas Identificados
1. **Não existe um system prompt genérico do portal** — regras universais (anti-alucinação, idioma, identidade da plataforma) estão duplicadas ou ausentes em verticais/canvas individuais. Exemplo: a vertical IATR tem regras anti-alucinação no DB override, mas a vertical Jurídico não tem.

2. **`api_params_override` mistura system_prompt com API params** — a coluna `canvas_templates.api_params_override` aceita `system_prompt`, que acaba sobrescrevendo toda a hierarquia concatenada. Isso confunde dois conceitos ortogonais (conteúdo do prompt vs parâmetros técnicos da API).

3. **Escalabilidade** — ao criar novas verticais (RH, Vendas, Marketing), cada uma precisaria copiar regras base manualmente.

### Decisão do Product Owner
Implementar hierarquia de 4 níveis com concatenação progressiva, onde cada nível adiciona especialização:

```
Nível 0: Portal (genérico, cross-vertical)
  └─ Nível 1: Vertical (domínio específico)
       └─ Nível 2: Canvas Template (tarefa específica)
            └─ Nível 3: ajSystemPrompt no JSON (micro-ajustes, se definido)
```

---

## 2. Arquitetura Proposta

### 2.1 Hierarquia de System Prompts (CONCATENAÇÃO)

Todos os níveis são **concatenados** (unidos com `\n\n`), não substituídos. Cada nível ADICIONA contexto.

| Nível | Fonte | Escopo | Editável via | Exemplo |
|-------|-------|--------|-------------|---------|
| 0 | `settings` tabela (chave `portal_system_prompt`) | Todo o portal | Admin → nova página ou página Settings | "Você é um assistente da Plataforma Sunyata. Regra inviolável: nunca fabrique dados. Responda em português brasileiro." |
| 1 | `verticals.config` JSON → chave `system_prompt` | Toda a vertical | Admin → Config Verticais | "Você apoia advogados na análise de documentos jurídicos..." |
| 2 | `canvas_templates.system_prompt` coluna | Canvas específico | Admin → Canvas Edit (Seção 3) | "Você é um advogado processualista sênior com 20+ anos..." |
| 3 | `form_config` JSON → `ajSystemPrompt` | Formulário específico | Manus (dentro do JSON SurveyJS) | Instruções finas de formatação de output |

**Prompt final enviado à API** = Nível 0 + `\n\n` + Nível 1 + `\n\n` + Nível 2 + `\n\n` + Nível 3

**Restrição de tamanho recomendada:**
- Nível 0: máx ~200 tokens (curto e universal)
- Nível 1: máx ~500 tokens (contexto do domínio)
- Nível 2: máx ~1000 tokens (tarefa específica)
- Nível 3: sem limite formal (mas consciência de custo)

### 2.2 API Params Override (SUBSTITUIÇÃO — separado dos prompts)

Parâmetros técnicos da API com hierarquia de **override** (substitui, não concatena):

| Nível | Fonte | Parâmetros |
|-------|-------|------------|
| Default | Hardcoded em ClaudeFacade | `model`, `temperature`, `max_tokens` |
| Vertical | VerticalConfig::get() | `claude_model`, `temperature`, `max_tokens`, `top_p` |
| Canvas | `canvas_templates.api_params_override` | `claude_model`, `temperature`, `max_tokens`, `top_p` |

**`system_prompt` NÃO é aceito em `api_params_override`** — o system prompt tem sua própria hierarquia.

---

## 3. Alterações Técnicas

### 3.1 Banco de Dados

```sql
-- Nenhuma nova tabela necessária. Usar a tabela settings existente.
-- Verificar se existe; se não, criar:
INSERT INTO settings (setting_key, setting_value, description)
VALUES ('portal_system_prompt', 'Você é um assistente da Plataforma Sunyata...', 'System prompt genérico do portal (Nível 0)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
```

**Nota:** Verificar se a tabela `settings` já existe e qual é sua estrutura.

### 3.2 CanvasHelper::getCompleteSystemPrompt()

**Arquivo:** `src/Helpers/CanvasHelper.php`

Alterar para incluir Nível 0:

```php
public static function getCompleteSystemPrompt(string $verticalSlug, int $canvasTemplateId): string
{
    $prompts = [];

    // NÍVEL 0: Portal (genérico)
    $portalPrompt = self::getPortalSystemPrompt();
    if (!empty($portalPrompt)) {
        $prompts[] = $portalPrompt;
    }

    // NÍVEL 1: Vertical (existente, sem mudança)
    // NÍVEL 2: Canvas Template (existente, sem mudança)
    // NÍVEL 3: ajSystemPrompt (existente, sem mudança)

    // ... resto do código atual ...

    return implode("\n\n", $prompts);
}

private static function getPortalSystemPrompt(): string
{
    try {
        $db = \Sunyata\Core\Database::getInstance();
        $setting = $db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_key = :key",
            [':key' => 'portal_system_prompt']
        );
        return $setting['setting_value'] ?? '';
    } catch (\Exception $e) {
        error_log("CanvasHelper: Failed to load portal system prompt: " . $e->getMessage());
        return '';
    }
}
```

### 3.3 ClaudeFacade — Bloquear system_prompt em api_params_override

**Arquivo:** `src/Helpers/ClaudeFacade.php`

Em `translateConfigKeys()`, remover `system_prompt` dos overrides de canvas:

```php
private static function translateConfigKeys(array $overrides): array
{
    // system_prompt NÃO deve ser passado via api_params_override
    // O system prompt tem sua própria hierarquia de 4 níveis
    unset($overrides['system_prompt']);

    $keyMap = [
        'claude_model' => 'model',
    ];

    $translated = [];
    foreach ($overrides as $key => $value) {
        $translated[$keyMap[$key] ?? $key] = $value;
    }
    return $translated;
}
```

### 3.4 Admin UI — Edição do System Prompt do Portal

**Opção A (simples):** Adicionar campo na página de Settings existente (se houver).
**Opção B:** Nova página admin `portal-config.php` com textarea para o Nível 0.

Para MVP, opção A é preferível se a página de settings já existir.

### 3.5 Admin UI — canvas-edit.php Seção 6

Atualizar para não aceitar `system_prompt`:
- Remover `system_prompt` da documentação de parâmetros na Seção 6
- Adicionar validação server-side que rejeita `system_prompt` no JSON
- Manter apenas: `claude_model`, `temperature`, `max_tokens`, `top_p`

### 3.6 Admin UI — canvas-edit.php Preview

Atualizar o preview da Seção 6 para mostrar claramente:
- "Parâmetros API" (model, temperature, etc.) — override da vertical
- Separado do "System Prompt" que tem sua própria hierarquia

### 3.7 Migração de Dados

O canvas iatr-peticao-manus-test (id=41) tem `system_prompt` dentro do `api_params_override`. Precisa ser migrado:

```sql
-- Remover system_prompt do api_params_override do canvas 41
-- O system_prompt que está lá pode ser movido para a coluna canvas_templates.system_prompt se necessário
UPDATE canvas_templates
SET api_params_override = JSON_REMOVE(api_params_override, '$.system_prompt')
WHERE id = 41;
```

---

## 4. Impacto nos Canvas do Manus

### Para o Manus (IMPORTANTE):

1. **Nenhuma mudança nos JSONs SurveyJS** — `ajSystemPrompt` continua funcionando igual
2. **O `system_prompt` de cada canvas template (Nível 2) continua igual** — coluna dedicada
3. **Novo: regras universais vêm do Nível 0** — não precisa mais repetir regras anti-alucinação em cada canvas
4. **Recomendação:** Revisar os system prompts dos canvas (Nível 2) e remover regras que serão cobertas pelo Nível 0 e Nível 1 (evitar redundância e desperdício de tokens)

### Pergunta para o Manus:
- Os system prompts dos canvas (Nível 2) criados por você contêm regras anti-alucinação e de identidade da plataforma? Se sim, essas seriam candidatas a mover para o Nível 0 (portal) ou Nível 1 (vertical), liberando o Nível 2 para foco na tarefa específica.
- Algum canvas usa `ajSystemPrompt` (Nível 3) atualmente? Se sim, quais?

---

## 5. Pontos para Review do Gemini (QA)

1. **Consistência da hierarquia** — Verificar que `getCompleteSystemPrompt()` monta corretamente os 4 níveis na ordem certa
2. **Não-regressão** — Canvas existentes sem Nível 0/3 devem funcionar identicamente (níveis vazios são ignorados)
3. **Validação de api_params_override** — Confirmar que `system_prompt` é rejeitado
4. **Prompt dilution risk** — Com 4 níveis, o system prompt total pode ficar longo. Verificar se há canvas com system prompts excessivamente longos nos Níveis 1+2
5. **Token cost** — Calcular impacto do Nível 0 adicional em custo por request (estimar ~200 tokens × preço do modelo)
6. **Verificar tabela settings** — Confirmar estrutura e se já existe no banco

---

## 6. Estimativa de Esforço

| Item | Esforço |
|------|---------|
| DB: INSERT portal_system_prompt em settings | 5 min |
| CanvasHelper: adicionar Nível 0 | 15 min |
| ClaudeFacade: bloquear system_prompt em override | 5 min |
| canvas-edit.php: atualizar Seção 6 | 15 min |
| Admin UI: edição do portal prompt | 20 min |
| Migração dados canvas 41 | 5 min |
| Testes e deploy | 15 min |
| **Total** | **~1h20** |

---

## 7. Ordem de Implementação

1. Verificar/criar tabela `settings` e inserir `portal_system_prompt`
2. Alterar `CanvasHelper::getCompleteSystemPrompt()` (Nível 0)
3. Alterar `ClaudeFacade::translateConfigKeys()` (bloquear system_prompt)
4. Atualizar `canvas-edit.php` Seção 6 (validação + UI)
5. Migrar dados do canvas 41
6. Criar/atualizar admin UI para edição do portal prompt
7. Deploy + teste end-to-end

---

**Aguardo feedback de ambos antes de implementar.**
