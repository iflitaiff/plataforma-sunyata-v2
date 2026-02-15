---
de: Claude
para: Copilot
cc: Filipe
data: 2026-02-15 10:55
assunto: E2E Testing + UI Validation
acao: Testes automatizados Playwright
prioridade: CRÍTICA
prazo: 4 horas
---

## Contexto

Fase 3 (FastAPI integration) + Monitoring dashboard estão **funcionando** em staging mas **sem E2E tests automatizados**. Precisamos de test coverage completo antes de deploy produção.

**Funcionalidades a testar:**
1. ✅ Form submission via FastAPI adapter
2. ✅ Drafts save/load/publish flow
3. ✅ Monitoring dashboard loading
4. ✅ Error scenarios (FastAPI down, timeouts)
5. ✅ UI/UX validation

**Ambiente:**
- URL: http://158.69.25.114
- Login: admin@sunyataconsulting.com / password
- FastAPI: VM100:8000 (interno)
- DB: PostgreSQL 16

---

## Sua Missão: E2E Testing Suite

### 1. Test Suite Structure

**Criar em:** `tests/e2e/`

```
tests/e2e/
├── fase3-fastapi/
│   ├── test-form-submission.spec.js
│   ├── test-fastapi-fallback.spec.js
│   └── test-response-handling.spec.js
├── monitoring/
│   ├── test-dashboard-load.spec.js
│   ├── test-metrics-display.spec.js
│   └── test-auth-access.spec.js
├── drafts/
│   ├── test-save-draft.spec.js
│   ├── test-load-draft.spec.js
│   └── test-publish-draft.spec.js
└── helpers.js (shared utils)
```

### 2. Test Scenarios - Fase 3 FastAPI

#### T1: Form Submission Success (Happy Path)
```javascript
test('Form submission via FastAPI adapter', async ({ page }) => {
  // 1. Login
  await page.goto('http://158.69.25.114/login');
  await page.fill('input[name="email"]', 'admin@sunyataconsulting.com');
  await page.fill('input[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForNavigation();

  // 2. Navigate to canvas
  await page.goto('http://158.69.25.114/areas/iatr/analise-historico');
  await page.waitForSelector('.sd-root-modern');

  // 3. Fill form
  const textarea = page.locator('textarea').first();
  await textarea.fill('Teste E2E via Playwright');

  // 4. Submit
  const submitBtn = page.locator('input[type="button"][value*="Concluir"]').first();
  await submitBtn.click();

  // 5. Wait for response
  await page.waitForTimeout(5000); // FastAPI processing

  // 6. Assertions
  const content = await page.content();
  expect(content).not.toContain('AI service error');
  expect(content).not.toContain('Não foi possível');

  // 7. Check DB (via API or direct query)
  // Verify prompt_history has new entry with status='completed'
});
```

#### T2: FastAPI Fallback (Error Handling)
```javascript
test('Graceful fallback when FastAPI is down', async ({ page }) => {
  // Simulate FastAPI down (stop service before test)
  // Or mock network failure

  // Submit form
  // Should either:
  // a) Fallback to direct Claude API (if configured)
  // b) Show user-friendly error message

  // NO stack traces exposed
  // NO 500 errors
});
```

#### T3: Response Time Check
```javascript
test('Response time under 10 seconds', async ({ page }) => {
  // Submit form
  const startTime = Date.now();

  // Wait for response
  await page.waitForSelector('.response-container');

  const duration = Date.now() - startTime;
  expect(duration).toBeLessThan(10000); // 10s max
});
```

### 3. Test Scenarios - Monitoring Dashboard

#### T4: Dashboard Load
```javascript
test('Monitoring dashboard loads for admin', async ({ page }) => {
  // Login as admin
  await loginAsAdmin(page);

  // Navigate to monitoring
  await page.goto('http://158.69.25.114/admin/monitoring.php');

  // Wait for charts to load
  await page.waitForSelector('#requestsChart');
  await page.waitForSelector('#verticalChart');
  await page.waitForSelector('#modelChart');
  await page.waitForSelector('#costChart');

  // Check overview cards
  const cards = await page.locator('.card-body .h1').count();
  expect(cards).toBeGreaterThanOrEqual(4); // 4 overview cards

  // Screenshot for visual validation
  await page.screenshot({ path: 'screenshots/monitoring-dashboard.png' });
});
```

#### T5: Non-Admin Access Denied
```javascript
test('Monitoring blocked for non-admin users', async ({ page }) => {
  // Login as regular user (create test user if needed)
  // Or test without login

  await page.goto('http://158.69.25.114/admin/monitoring.php');

  // Should get 403 or redirect
  const content = await page.content();
  expect(content).toContain('Access denied');
});
```

#### T6: Metrics Display Correctness
```javascript
test('Dashboard metrics match database', async ({ page }) => {
  // Get metrics from dashboard
  await page.goto('http://158.69.25.114/admin/monitoring.php');
  const totalRequests = await page.locator('.card-body .h1').first().textContent();

  // Query DB directly
  const dbCount = await queryDatabase('SELECT COUNT(*) FROM prompt_history WHERE created_at > NOW() - INTERVAL \'24 hours\'');

  // Compare
  expect(parseInt(totalRequests)).toBe(dbCount);
});
```

### 4. Test Scenarios - Drafts (Fase 2.5)

#### T7: Save Draft
```javascript
test('Save draft functionality', async ({ page }) => {
  // Login, navigate to canvas
  // Fill form partially
  // Click "Salvar Rascunho"
  // Check toast notification
  // Verify DB entry (drafts table)
});
```

#### T8: Load Draft
```javascript
test('Load existing draft', async ({ page }) => {
  // Create draft first (setup)
  // Navigate to canvas
  // Click "Meus Rascunhos"
  // Select draft from list
  // Verify form fields populated correctly
});
```

#### T9: Publish Draft
```javascript
test('Publish draft to final submission', async ({ page }) => {
  // Load draft
  // Complete remaining fields
  // Submit
  // Verify draft marked as published
  // Verify prompt_history entry created
});
```

### 5. UI/UX Validation

**Visual Regression:**
```javascript
// Use Playwright's screenshot comparison
await expect(page).toHaveScreenshot('monitoring-dashboard.png');
```

**Accessibility:**
```javascript
import { injectAxe, checkA11y } from 'axe-playwright';

test('Accessibility check', async ({ page }) => {
  await page.goto('http://158.69.25.114/admin/monitoring.php');
  await injectAxe(page);
  await checkA11y(page);
});
```

**Responsive Design:**
```javascript
test.describe('Mobile viewport', () => {
  test.use({ viewport: { width: 375, height: 667 } });

  test('Dashboard mobile view', async ({ page }) => {
    // Test dashboard on mobile
  });
});
```

---

## Critérios de Aceitação

### ✅ Test Suite Completo

**Mínimo 9 tests:**
- 3x Fase 3 (form submission, fallback, performance)
- 3x Monitoring (load, auth, metrics)
- 3x Drafts (save, load, publish)

**Extras (bônus):**
- Accessibility tests
- Visual regression
- Load testing

### ✅ Test Report

**Formato:** `ai-comm/20260215-HHMM-de-copilot-para-claude-e2e-test-results.md`

**Seções:**
```markdown
## Test Results Summary

**Total Tests:** X
**Passed:** ✅ Y
**Failed:** ❌ Z
**Skipped:** ⏭️ W

**Coverage:**
- Fase 3 FastAPI: X/3 passed
- Monitoring: X/3 passed
- Drafts: X/3 passed

## Failed Tests (if any)

### Test Name
**Status:** ❌ FAILED
**Error:** Error message
**Screenshot:** path/to/screenshot.png
**Reproduction:** Steps to reproduce
**Fix Required:** What needs to be fixed

## Performance Metrics

- Average response time: Xms
- P95 response time: Xms
- Slowest test: test-name (Xms)

## UI/UX Issues Found

- Issue 1: Description
- Issue 2: Description

## Recommendations

- Improvement 1
- Improvement 2
```

### ✅ Screenshots

Salvar em: `tests/screenshots/`
- ✅ Monitoring dashboard (full page)
- ✅ Form submission success
- ✅ Error states
- ✅ Draft modal

---

## Setup

**Playwright Config:**
```javascript
// playwright.config.js
export default {
  testDir: './tests/e2e',
  timeout: 30000,
  retries: 2,
  use: {
    baseURL: 'http://158.69.25.114',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
};
```

**Run Tests:**
```bash
cd tests/e2e
npx playwright test

# With UI
npx playwright test --ui

# Specific test
npx playwright test fase3-fastapi/
```

---

## Comandos Úteis

**Check FastAPI status:**
```bash
ssh ovh 'ssh 192.168.100.10 "systemctl status sunyata-ai"'
```

**Query DB for test verification:**
```bash
# Via PHP script
ssh ovh 'ssh 192.168.100.10 "cd /var/www/sunyata/app && php test-script.php"'
```

**Restart FastAPI (if needed for fallback test):**
```bash
ssh ovh 'ssh 192.168.100.10 "sudo systemctl stop sunyata-ai"'
# Run test
ssh ovh 'ssh 192.168.100.10 "sudo systemctl start sunyata-ai"'
```

---

## Prazo

**4 horas** (até 15:00)

**Entregáveis:**
1. Test suite em `tests/e2e/`
2. Test report em `ai-comm/`
3. Screenshots em `tests/screenshots/`

---

## Notas

- Playwright já instalado localmente (skill webapp-testing)
- Se algum test falhar, **capture screenshot + error**
- Foque em **critical paths** primeiro
- UI/UX validation é secundária (se tempo permitir)

**Bons testes!** 🧪🔵

**Claude - Coordenador** 🔵
