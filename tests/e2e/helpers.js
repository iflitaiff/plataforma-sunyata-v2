/**
 * E2E Test Helpers
 * Shared utilities for all E2E tests
 */

const BASE_URL = 'http://158.69.25.114';

// Credentials
const CREDENTIALS_ADMIN = {
  email: 'admin@sunyataconsulting.com',
  password: 'password'
};

const CREDENTIALS_USER = {
  email: 'test@test.com',
  password: 'Test1234!'
};

/**
 * Login as admin
 */
async function loginAsAdmin(page) {
  await page.goto(`${BASE_URL}/auth/login`);
  await page.waitForLoadState('networkidle');
  
  // Click "Entrar com Email"
  const emailBtn = page.locator('a:has-text("Entrar com Email")').first();
  if (await emailBtn.isVisible()) {
    await emailBtn.click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
  }
  
  // Fill credentials
  await page.fill('input[name="email"], input[id*="email"], input[type="email"]', CREDENTIALS_ADMIN.email);
  await page.fill('input[name="password"], input[id*="password"], input[type="password"]', CREDENTIALS_ADMIN.password);
  
  // Submit
  const submitBtn = page.locator('button[type="submit"]:has-text("Entrar"), button:has-text("Entrar")').first();
  await submitBtn.click();
  
  // Wait for navigation
  await page.waitForURL('**/dashboard', { timeout: 10000 }).catch(() => {
    // May redirect elsewhere, that's OK
  });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}

/**
 * Login as regular user
 */
async function loginAsUser(page) {
  await page.goto(`${BASE_URL}/auth/login`);
  await page.waitForLoadState('networkidle');
  
  const emailBtn = page.locator('a:has-text("Entrar com Email")').first();
  if (await emailBtn.isVisible()) {
    await emailBtn.click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
  }
  
  await page.fill('input[name="email"], input[id*="email"], input[type="email"]', CREDENTIALS_USER.email);
  await page.fill('input[name="password"], input[id*="password"], input[type="password"]', CREDENTIALS_USER.password);
  
  const submitBtn = page.locator('button[type="submit"]:has-text("Entrar"), button:has-text("Entrar")').first();
  await submitBtn.click();
  
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}

/**
 * Logout
 */
async function logout(page) {
  // Try to find logout button or link
  const logoutBtn = page.locator('a:has-text("Sair"), button:has-text("Sair"), [href*="logout"]').first();
  if (await logoutBtn.isVisible()) {
    await logoutBtn.click();
    await page.waitForNavigation();
  }
}

/**
 * Get CSRF token from page
 */
async function getCsrfToken(page) {
  const html = await page.content();
  const match = html.match(/csrf[^<>]*["']([a-zA-Z0-9\/\+\-_.=]+)["']/i);
  return match ? match[1] : null;
}

/**
 * Navigate to canvas form
 */
async function navigateToCanvas(page, vertical = 'iatr', templateSlug = 'iatr-geral-manus-test') {
  await page.goto(`${BASE_URL}/areas/${vertical}/formulario.php?template=${templateSlug}`);
  await page.waitForSelector('#surveyContainer, .sd-root-modern', { timeout: 10000 });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}

/**
 * Submit form
 */
async function submitForm(page) {
  // Look for submit button
  const submitBtn = page.locator(
    'input[type="button"][value*="Concluir"], ' +
    'input[type="button"][value*="Enviar"], ' +
    'button:has-text("Concluir"), ' +
    'button:has-text("Enviar")'
  ).first();
  
  if (await submitBtn.isVisible()) {
    await submitBtn.click();
    await page.waitForTimeout(5000); // Allow time for processing
    return true;
  }
  return false;
}

/**
 * Fill textarea field
 */
async function fillTextarea(page, text, index = 0) {
  const textarea = page.locator('textarea').nth(index);
  await textarea.fill(text);
}

/**
 * Wait for API call
 */
async function waitForApiCall(page, path, timeout = 10000) {
  return page.waitForResponse(response => 
    response.url().includes(path) && (response.status() === 200 || response.status() === 201),
    { timeout }
  ).catch(() => null);
}

/**
 * Get monitoring dashboard stats
 */
async function getMonitoringStats(page) {
  const stats = {};
  
  try {
    const cards = await page.locator('.card-body .h1').all();
    for (let i = 0; i < cards.length && i < 4; i++) {
      const value = await cards[i].textContent();
      stats[`metric_${i}`] = value?.trim();
    }
  } catch (e) {
    // Stats may not be available
  }
  
  return stats;
}

/**
 * Screenshot helper
 */
async function takeScreenshot(page, name) {
  const filename = `tests/screenshots/${name}.png`;
  await page.screenshot({ path: filename });
  return filename;
}

/**
 * Wait for element with timeout
 */
async function waitForElement(page, selector, timeout = 10000) {
  try {
    await page.waitForSelector(selector, { timeout });
    return true;
  } catch (e) {
    return false;
  }
}

module.exports = {
  BASE_URL,
  CREDENTIALS_ADMIN,
  CREDENTIALS_USER,
  loginAsAdmin,
  loginAsUser,
  logout,
  getCsrfToken,
  navigateToCanvas,
  submitForm,
  fillTextarea,
  waitForApiCall,
  getMonitoringStats,
  takeScreenshot,
  waitForElement,
};
