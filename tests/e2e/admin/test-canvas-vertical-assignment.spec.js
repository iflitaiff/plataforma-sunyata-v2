/**
 * E2E Tests: Canvas-Vertical Many-to-Many Assignment
 *
 * Tests Phase 3.5 Part 2 functionality:
 * - Create vertical via admin
 * - Assign canvas to multiple verticals
 * - Verify visibility in all assigned verticals
 * - Remove vertical assignment
 * - Edge cases (no verticals, all verticals)
 *
 * Target: http://158.69.25.114 (VM100 - v2)
 * Requires: Admin credentials
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = 'http://158.69.25.114';
const ADMIN_EMAIL = 'admin@sunyataconsulting.com';
const ADMIN_PASSWORD = 'password';

// Helper: Login as admin
async function loginAsAdmin(page) {
    await page.goto(`${BASE_URL}/login.php`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(/dashboard|admin/, { timeout: 10000 });
}

// Helper: Create a test vertical
async function createTestVertical(page, slug, name) {
    await page.goto(`${BASE_URL}/admin/verticals.php`);
    await page.waitForLoadState('networkidle');

    // Click create button
    await page.click('button:has-text("Criar Vertical"), button:has-text("Nova Vertical")');
    await page.waitForSelector('input[name="slug"]', { timeout: 5000 });

    // Fill form
    await page.fill('input[name="slug"]', slug);
    await page.fill('input[name="nome"]', name);
    await page.fill('input[name="icone"]', '🧪'); // Test icon
    await page.check('input[name="disponivel"]');

    // Submit
    await page.click('button[type="submit"]:has-text("Criar"), button:has-text("Salvar")');
    await page.waitForLoadState('networkidle');

    // Verify success message
    const successMessage = await page.locator('.alert-success, .alert.alert-success').count();
    expect(successMessage).toBeGreaterThan(0);
}

// Helper: Delete a vertical
async function deleteVertical(page, slug) {
    await page.goto(`${BASE_URL}/admin/verticals.php`);
    await page.waitForLoadState('networkidle');

    // Find delete button for this vertical
    const deleteBtn = page.locator(`button[data-slug="${slug}"]:has-text("Deletar"), button[data-vertical="${slug}"]:has-text("Deletar")`).first();
    if (await deleteBtn.count() > 0) {
        await deleteBtn.click();

        // Confirm deletion
        page.on('dialog', dialog => dialog.accept());
        await page.waitForLoadState('networkidle');
    }
}

test.describe('Canvas-Vertical Many-to-Many Assignment', () => {

    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('T1: Create vertical and verify in admin list', async ({ page }) => {
        const testSlug = `test-vertical-${Date.now()}`;
        const testName = 'Test Vertical E2E';

        // Create vertical
        await createTestVertical(page, testSlug, testName);

        // Verify it appears in list
        await page.goto(`${BASE_URL}/admin/verticals.php`);
        const verticalCard = page.locator(`.card:has-text("${testName}")`);
        await expect(verticalCard).toBeVisible();

        // Cleanup
        await deleteVertical(page, testSlug);
    });

    test('T2: Assign canvas to multiple verticals', async ({ page }) => {
        // Navigate to a canvas edit page (use existing canvas)
        await page.goto(`${BASE_URL}/admin/canvas-templates.php`);
        await page.waitForLoadState('networkidle');

        // Find first canvas and click edit
        const firstEditBtn = page.locator('a:has-text("Editar"), a:has-text("Edit")').first();
        await firstEditBtn.click();
        await page.waitForLoadState('networkidle');

        // Find vertical checkboxes section
        const checkboxes = page.locator('input[type="checkbox"][name="verticals[]"]');
        const checkboxCount = await checkboxes.count();

        expect(checkboxCount).toBeGreaterThan(0); // Should have at least one vertical

        // Check first 3 verticals (or all if less than 3)
        const verticalsToAssign = Math.min(3, checkboxCount);
        const assignedVerticals = [];

        for (let i = 0; i < verticalsToAssign; i++) {
            const checkbox = checkboxes.nth(i);
            const value = await checkbox.getAttribute('value');
            assignedVerticals.push(value);

            // Check if not already checked
            if (!(await checkbox.isChecked())) {
                await checkbox.check();
            }
        }

        // Save
        await page.click('button[type="submit"]:has-text("Salvar"), button[type="submit"]:has-text("Atualizar")');
        await page.waitForLoadState('networkidle');

        // Verify success message
        const successAlert = page.locator('.alert-success:has-text("sucesso"), .alert-success:has-text("atualizado")');
        await expect(successAlert).toBeVisible({ timeout: 5000 });

        // Verify checkboxes are still checked after save
        for (let i = 0; i < verticalsToAssign; i++) {
            const checkbox = checkboxes.nth(i);
            await expect(checkbox).toBeChecked();
        }

        console.log(`✅ Canvas assigned to ${verticalsToAssign} verticals:`, assignedVerticals);
    });

    test('T3: Verify canvas appears in assigned vertical areas', async ({ page }) => {
        // Get a canvas with known assignment
        await page.goto(`${BASE_URL}/admin/canvas-templates.php`);
        await page.waitForLoadState('networkidle');

        // Get first canvas info
        const firstCard = page.locator('.card').first();
        const canvasName = await firstCard.locator('.card-title, h3, h4, h5').first().textContent();

        // Click edit to see which verticals it's assigned to
        const editBtn = firstCard.locator('a:has-text("Editar")').first();
        await editBtn.click();
        await page.waitForLoadState('networkidle');

        // Get checked verticals
        const checkedCheckboxes = page.locator('input[type="checkbox"][name="verticals[]"]:checked');
        const checkedCount = await checkedCheckboxes.count();

        if (checkedCount === 0) {
            console.log('⚠️  Canvas has no vertical assignments, skipping visibility test');
            test.skip();
        }

        // Get first assigned vertical slug
        const firstVerticalSlug = await checkedCheckboxes.first().getAttribute('value');

        // Navigate to that vertical's index page
        await page.goto(`${BASE_URL}/areas/${firstVerticalSlug}/index.php`);
        await page.waitForLoadState('networkidle');

        // Verify canvas appears in the list
        const canvasInList = page.locator(`.card:has-text("${canvasName.trim()}"), a:has-text("${canvasName.trim()}")`);

        // May not be visible if name is truncated, so check if exists
        const exists = await canvasInList.count();
        expect(exists).toBeGreaterThan(0);

        console.log(`✅ Canvas "${canvasName.trim()}" found in vertical "${firstVerticalSlug}"`);
    });

    test('T4: Remove vertical assignment and verify', async ({ page }) => {
        // Go to canvas edit
        await page.goto(`${BASE_URL}/admin/canvas-templates.php`);
        await page.waitForLoadState('networkidle');

        const firstEditBtn = page.locator('a:has-text("Editar")').first();
        await firstEditBtn.click();
        await page.waitForLoadState('networkidle');

        // Find checkboxes
        const checkboxes = page.locator('input[type="checkbox"][name="verticals[]"]');
        const checkedCheckboxes = page.locator('input[type="checkbox"][name="verticals[]"]:checked');
        const checkedCount = await checkedCheckboxes.count();

        if (checkedCount === 0) {
            console.log('⚠️  Canvas has no assignments, skipping removal test');
            test.skip();
        }

        // Uncheck first vertical
        const firstChecked = checkedCheckboxes.first();
        const verticalSlug = await firstChecked.getAttribute('value');
        await firstChecked.uncheck();

        // Save
        await page.click('button[type="submit"]:has-text("Salvar"), button[type="submit"]:has-text("Atualizar")');
        await page.waitForLoadState('networkidle');

        // Verify it's unchecked after save
        const checkboxAfterSave = page.locator(`input[type="checkbox"][name="verticals[]"][value="${verticalSlug}"]`);
        await expect(checkboxAfterSave).not.toBeChecked();

        console.log(`✅ Vertical "${verticalSlug}" removed from canvas`);
    });

    test('T5: Edge case - Canvas with no verticals shows warning', async ({ page }) => {
        await page.goto(`${BASE_URL}/admin/canvas-templates.php`);
        await page.waitForLoadState('networkidle');

        const firstEditBtn = page.locator('a:has-text("Editar")').first();
        await firstEditBtn.click();
        await page.waitForLoadState('networkidle');

        // Uncheck all verticals
        const checkboxes = page.locator('input[type="checkbox"][name="verticals[]"]:checked');
        const count = await checkboxes.count();

        for (let i = 0; i < count; i++) {
            await checkboxes.nth(0).uncheck(); // Always uncheck first (list shrinks)
        }

        // Save
        await page.click('button[type="submit"]:has-text("Salvar"), button[type="submit"]:has-text("Atualizar")');
        await page.waitForLoadState('networkidle');

        // Verify warning is shown
        const warning = page.locator('.alert-warning:has-text("sem verticais"), .alert-warning:has-text("atribuídas")');
        await expect(warning).toBeVisible({ timeout: 5000 });

        console.log('✅ Warning displayed for canvas with no verticals');
    });

    test('T6: Canvas assigned to all verticals', async ({ page }) => {
        await page.goto(`${BASE_URL}/admin/canvas-templates.php`);
        await page.waitForLoadState('networkidle');

        const firstEditBtn = page.locator('a:has-text("Editar")').first();
        await firstEditBtn.click();
        await page.waitForLoadState('networkidle');

        // Check all verticals
        const checkboxes = page.locator('input[type="checkbox"][name="verticals[]"]');
        const count = await checkboxes.count();

        for (let i = 0; i < count; i++) {
            const checkbox = checkboxes.nth(i);
            if (!(await checkbox.isChecked())) {
                await checkbox.check();
            }
        }

        // Save
        await page.click('button[type="submit"]:has-text("Salvar"), button[type="submit"]:has-text("Atualizar")');
        await page.waitForLoadState('networkidle');

        // Verify all are checked
        const checkedAfter = await page.locator('input[type="checkbox"][name="verticals[]"]:checked').count();
        expect(checkedAfter).toBe(count);

        console.log(`✅ Canvas assigned to all ${count} verticals`);
    });
});

test.describe('Canvas Creation with Vertical Assignment', () => {

    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('T7: Create canvas from scratch and assign to verticals', async ({ page }) => {
        await page.goto(`${BASE_URL}/admin/canvas-templates.php`);
        await page.waitForLoadState('networkidle');

        // Click create canvas button
        const createBtn = page.locator('button:has-text("Criar Canvas"), button:has-text("Novo Canvas")').first();
        await createBtn.click();
        await page.waitForSelector('input[name="nome"], input[name="name"]', { timeout: 5000 });

        const testSlug = `test-canvas-${Date.now()}`;
        const testName = `Test Canvas ${Date.now()}`;

        // Fill form
        await page.fill('input[name="nome"], input[name="name"]', testName);
        await page.fill('input[name="slug"]', testSlug);

        // Select vertical (if single select) or check first vertical (if checkboxes)
        const verticalSelect = page.locator('select[name="vertical"]');
        const verticalCheckbox = page.locator('input[type="checkbox"][name="verticals[]"]').first();

        if (await verticalSelect.count() > 0) {
            // Single select (old style)
            await verticalSelect.selectOption({ index: 1 });
        } else if (await verticalCheckbox.count() > 0) {
            // Checkboxes (new style)
            await verticalCheckbox.check();
        }

        // Submit
        await page.click('button[type="submit"]:has-text("Criar"), button[type="submit"]:has-text("Salvar")');
        await page.waitForLoadState('networkidle');

        // Should redirect to edit page
        expect(page.url()).toContain('canvas-edit.php');

        console.log(`✅ Canvas "${testName}" created successfully`);
    });
});
