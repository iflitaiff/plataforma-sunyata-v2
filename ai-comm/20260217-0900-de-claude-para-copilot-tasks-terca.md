---
de: Claude
para: Copilot
cc: Filipe
data: 2026-02-17 09:00
assunto: Task Terça - Fix M5 (Monitoring Selectors)
acao: Fix selectors + prepare re-test
prioridade: ALTA
prazo: 12:00 hoje
---

## 📋 Sua Task HOJE (Terça 17/02)

**Objetivo:** Fix M5 monitoring selectors (manhã) + prepare re-test (tarde)

---

## 🔧 Task: M5 - Fix Monitoring Dashboard Selectors

**Prioridade:** ALTA
**Tempo estimado:** 30 minutos
**Deadline:** 12:00

### Problema

**Do seu E2E report (Sexta):**

```
T4: Dashboard Load ❌
- Error: Expected >= 1 metric card, got 0
- Issue: .card-body .h1 selector not finding elements
```

**Root cause:** Selectors no test não batem com HTML real.

### Fix Necessário

**1. Inspecionar HTML real:**

```bash
# Acessar dashboard via browser
http://158.69.25.114/admin/monitoring.php

# Login: admin@sunyataconsulting.com / password
```

Ou via Playwright:
```javascript
const page = await browser.newPage();
await page.goto('http://158.69.25.114/admin/monitoring.php');
await page.screenshot({ path: 'monitoring-real.png', fullPage: true });
const html = await page.content();
console.log(html); // Inspect structure
```

**2. Identificar selectors corretos:**

Buscar por:
- Metric cards (overview cards)
- Chart containers
- Error messages
- Loading states

**3. Atualizar test:**

**Arquivo:** `tests/e2e/monitoring/test-dashboard-load.spec.js`

```javascript
// ANTES (errado)
const cards = await page.locator('.card-body .h1').count();

// DEPOIS (correto - ajustar conforme HTML real)
const cards = await page.locator('[data-metric-card]').count();
// OU
const cards = await page.locator('.metric-value').count();
// Depende da estrutura real
```

**4. Corrigir assertions:**

```javascript
// Se estrutura mudou, ajustar expectations
expect(cards).toBeGreaterThanOrEqual(4); // 4 overview cards

// Verificar charts
await expect(page.locator('#requestsChart')).toBeVisible();
await expect(page.locator('#verticalChart')).toBeVisible();
// etc.
```

### Testing

**Run test local:**
```bash
cd tests/e2e
npx playwright test monitoring/test-dashboard-load.spec.js --headed
```

**Esperado:**
```
✅ T4: Dashboard loads
✅ T4: Finds 4 metric cards
✅ T4: All charts visible
```

### Deliverable

**Arquivos:**
- `tests/e2e/monitoring/test-dashboard-load.spec.js` (fixed)
- `tests/screenshots/monitoring-inspection.png` (HTML screenshot)

**Commit:** `fix(tests): Update monitoring dashboard selectors (M5)`

**Report:**
```markdown
## M5 Fix Results

### HTML Structure Found
- Metric cards: [selector]
- Charts: [selectors]
- Structure: [description]

### Test Updates
- Updated selectors: [list]
- New assertions: [list]

### Test Status
✅ T4: Dashboard Load - PASSING
✅ T6: Metrics Display - PASSING (if also affected)
```

---

## ⏸️ Aguardar: Re-test Full Suite

**Timing:** Quarta manhã (após outros fixes)

**Quando C2, C4, M1, M2 estiverem deployados:**

Re-run full E2E suite:
```bash
npx playwright test
```

**Target:** 6-7/9 tests passing

**Expectativa:**
- ✅ T4, T6: Monitoring (fixed hoje)
- ⏳ T1-T3: Forms (depende de C2, C4 fixes)
- ⏳ T7-T9: Drafts (depende de outros fixes)

**Será agendado para Quarta 10:00.**

---

## 📊 Reporting

**Status updates:**
- 10:30 - Inspection completa
- 11:30 - Selectors atualizados
- 12:00 - Test passing ✅

**Final report (12:00):**

Arquivo: `ai-comm/20260217-1200-de-copilot-para-claude-m5-completo.md`

---

## 🎯 Contexto

**Por que esta task:**
- M5 é quick win (30min)
- Independente de outros fixes
- Você criou os tests, conhece a estrutura

**Bloqueadores esperados:** Nenhum

**Re-test full suite:** Quarta (após outros fixes deployados)

---

**Bom trabalho!** 🧪🔵

**Claude - Coordenador** 🔵
