---
de: Claude
para: Filipe
data: 2026-02-15 14:40
assunto: Opção B Iniciada - Timeline e Coordenação
acao: Status update
prioridade: ALTA
status: EM EXECUÇÃO
---

## ✅ Opção B: Fix Críticos + Pausar Fase 3

**Confirmado e iniciado.**

---

## 📋 Equipe Coordenada

**Mensagens enviadas:**
- ✅ Gemini: Avisado do fix C1, solicitada re-validação pós-fix
- ✅ Codex: Avisado do fix C3 (settings), confirmado excelente trabalho
- ✅ Copilot: Avisado de pausa Fase 3, re-test agendado Segunda

**Todos estão alinhados com o plano.**

---

## ⏱️ Timeline HOJE (14:40-17:30)

```
14:40 ━━━━━━━━━━━━━━━ AGORA - Iniciando fix C1
      │
      ├─ Análise Database.php atual
      ├─ Design whitelist strategy
      └─ Implementação refactor
      │
17:00 ━━━━━━━━━━━━━━━ Fix C1 completo
      │
      ├─ Testing local
      └─ Syntax validation
      │
17:05 ━━━━━━━━━━━━━━━ Fix C3: Create settings
      │
      └─ SQL insert portal_system_prompt + portal_api_params
      │
17:10 ━━━━━━━━━━━━━━━ Deploy security fixes
      │
      ├─ Database.php → staging
      ├─ Settings migration → DB
      └─ PHP-FPM reload
      │
17:15 ━━━━━━━━━━━━━━━ Gemini re-validation
      │
      └─ Security re-check do Database.php
      │
17:20 ━━━━━━━━━━━━━━━ Disable microservice mode
      │
      └─ UPDATE settings SET setting_value='direct'
          WHERE setting_key='ai_service_mode'
      │
17:30 ━━━━━━━━━━━━━━━ COMPLETO + Report final
```

---

## 🔧 Fix C1: Database.php Refactor

### Estratégia

**Problema atual:**
```php
// VULNERÁVEL
public function insert($table, $data) {
    $fields = implode(', ', array_keys($data));
    $sql = "INSERT INTO $table ($fields) VALUES (...)";
}
```

**Solução:**
```php
// SEGURO
private array $allowedTables = [
    'users', 'canvas_templates', 'prompt_history',
    'form_drafts', 'settings', 'verticals', ...
];

private array $allowedColumns = [
    'users' => ['id', 'email', 'name', 'password_hash', ...],
    'canvas_templates' => ['id', 'slug', 'name', 'form_config', ...],
    // ... etc
];

public function insert($table, $data) {
    $this->validateTable($table);
    $this->validateColumns($table, array_keys($data));

    // Agora é seguro
    $fields = implode(', ', array_keys($data));
    $sql = "INSERT INTO $table ($fields) VALUES (...)";
}
```

**Métodos a criar:**
- `validateTable($table)` - check whitelist
- `validateColumns($table, $columns)` - check whitelist
- `getAllowedColumns($table)` - getter

**Métodos a refatorar:**
- `insert($table, $data)`
- `update($table, $data, $conditions)`
- `delete($table, $conditions)`

---

## 🔧 Fix C3: Missing Settings

**SQL a executar:**
```sql
INSERT INTO settings (setting_key, setting_value, data_type, description)
VALUES
  (
    'portal_system_prompt',
    '',
    'string',
    'System prompt portal-wide (Nível 0 da hierarquia de prompts)'
  ),
  (
    'portal_api_params',
    '{}',
    'json',
    'API params defaults (Nível 0: model, temperature, max_tokens, top_p)'
  )
ON CONFLICT (setting_key) DO NOTHING;
```

**Validação:**
```bash
# Após insert, verificar
SELECT setting_key, setting_value, data_type
FROM settings
WHERE setting_key IN ('portal_system_prompt', 'portal_api_params');
```

---

## ⏸️ Pausar Fase 3

**Ação:**
```sql
UPDATE settings
SET setting_value = 'direct'
WHERE setting_key = 'ai_service_mode';
```

**Efeito:**
- `ClaudeFacade::usesMicroservice()` retorna `false`
- `generateForCanvas()` usa `ClaudeService::generate()` (direto)
- FastAPI microservice não é chamado
- Sistema volta ao comportamento pré-Fase 3

**Rollback é instant e seguro.**

---

## 📊 Impacto para Usuários

**HOJE (após fixes):**
- ✅ Sistema mais seguro (SQL injection corrigido)
- ✅ Config hierarquia completa (settings criados)
- ⚪ Sem mudança visível (FastAPI não estava ativo para users)

**O que NÃO vai mudar:**
- Formulários continuam funcionando normalmente
- Users não notam diferença (Fase 3 não estava em produção)
- Monitoring dashboard continua funcionando (fix XSS fica para Segunda)

---

## 🎯 Segunda-feira: Refactor Arquitetural

**Agenda proposta:**
- 09:00-10:00: Reunião arquitetural (design session)
- 10:00-12:00: Implementação cache layer (Redis)
- 12:00-13:00: Almoço
- 13:00-15:00: Rate limiting global + connection pooling
- 15:00-17:00: Fix blockers restantes (C2, C4, M1)
- 17:00-18:00: Testing (Copilot re-run)

**Terça-Quinta:** Implementação completa + full testing

**Sexta:** Deploy produção (se tudo OK)

---

## 📝 Próximas Ações (Você)

**Hoje:**
- ☕ Aguardar fix C1 + C3 (até 17:30)
- 📊 Review final do dia (17:30)

**Segunda:**
- 📋 Participar da reunião arquitetural
- ✅ Aprovar design decisions
- 🔍 Review do refactor

**Durante semana:**
- 📊 Acompanhar progresso
- ✅ Aprovar milestones
- 🚀 Decisão GO/NO-GO Sexta

---

## 🎯 Objetivo Final

**Sexta (19/02):**
- ✅ Sistema v2 production-ready
- ✅ Fase 3 re-deployada (arquitetura correta)
- ✅ 9/9 E2E tests passing
- ✅ Zero vulnerabilidades críticas
- ✅ Multi-user ready (cache, rate limit, pooling)
- ✅ Observability ativa

**Deploy com confiança total.** 🚀

---

**Iniciando fix C1 agora...** ⚙️

**Claude - Coordenador** 🔵
