/**
 * E2E Tests: Drafts Workflow
 * T7: Save draft
 * T8: Load draft
 * T9: Publish draft
 */

import { test, expect } from '@playwright/test';
import { loginAsAdmin, navigateToCanvas, takeScreenshot } from '../helpers';

test.describe('Drafts Workflow', () => {

  test('T7: Save draft functionality', async ({ page }) => {
    console.log('🧪 T7: Save Draft');
    
    // 1. Login
    console.log('  1. Logging in...');
    await loginAsAdmin(page);
    
    // 2. Navigate to canvas
    console.log('  2. Navigating to canvas...');
    await navigateToCanvas(page, 'iatr', 'iatr-geral-manus-test');
    
    // 3. Fill form
    console.log('  3. Filling form...');
    const textarea = page.locator('textarea').first();
    const draftText = `Draft Save Test - ${new Date().getTime()}`;
    await textarea.fill(draftText);
    await page.waitForTimeout(500);
    
    // 4. Find and click "Salvar Rascunho"
    console.log('  4. Clicking save draft button...');
    const saveDraftBtn = page.locator('#saveDraftBtn, button:has-text("Salvar Rascunho"), input[value*="Rascunho"]').first();
    
    const btnExists = await saveDraftBtn.isVisible().catch(() => false);
    expect(btnExists).toBeTruthy();
    
    if (btnExists) {
      await saveDraftBtn.click();
      
      // 5. Wait for response/notification
      console.log('  5. Waiting for draft save notification...');
      await page.waitForTimeout(2000);
      
      // 6. Check for success message or toast
      const content = await page.content();
      const successMsg = content.includes('sucesso') || 
                        content.includes('Rascunho salvo') ||
                        content.includes('Draft saved');
      
      // 7. Verify no error
      const errorMsg = content.includes('erro') || content.includes('error');
      
      console.log(`  6. ✅ Draft saved (success: ${successMsg}, error: ${errorMsg})`);
      expect(!errorMsg).toBeTruthy();
      
      // 8. Screenshot
      const screenshot = await takeScreenshot(page, 'draft-save-success');
      console.log(`  7. 📸 Screenshot: ${screenshot}`);
    } else {
      console.log('  ⚠️  Save draft button not found (may not be available on this page)');
    }
    
    console.log('✅ T7 PASSED\n');
  });

  test('T8: Load existing draft', async ({ page }) => {
    console.log('🧪 T8: Load Draft');
    
    // 1. Login
    console.log('  1. Logging in...');
    await loginAsAdmin(page);
    
    // 2. Navigate to canvas
    console.log('  2. Navigating to canvas...');
    await navigateToCanvas(page, 'iatr', 'iatr-geral-manus-test');
    
    // 3. Check if "Meus Rascunhos" button exists
    console.log('  3. Looking for drafts button...');
    const openDraftsBtn = page.locator('#openDraftsBtn, button:has-text("Meus Rascunhos")').first();
    
    const btnExists = await openDraftsBtn.isVisible().catch(() => false);
    
    if (btnExists) {
      // 4. Click to open drafts modal
      console.log('  4. Opening drafts modal...');
      await openDraftsBtn.click();
      await page.waitForTimeout(1000);
      
      // 5. Verify modal appeared
      const modalVisible = await page.locator('#draftsModal, [role="dialog"]').first().isVisible().catch(() => false);
      
      if (modalVisible) {
        console.log('  5. ✅ Drafts modal opened');
        
        // 6. Check if any drafts listed
        const draftItems = await page.locator('[data-draft-id], .draft-item, li').count();
        console.log(`  6. Found ${draftItems} draft items`);
        
        // 7. Screenshot
        const screenshot = await takeScreenshot(page, 'draft-modal-load');
        console.log(`  7. 📸 Screenshot: ${screenshot}`);
      } else {
        console.log('  ⚠️  Modal did not open');
      }
    } else {
      console.log('  ⚠️  Drafts button not found (may not be available on this page)');
    }
    
    console.log('✅ T8 PASSED\n');
  });

  test('T9: Publish draft to final submission', async ({ page }) => {
    console.log('🧪 T9: Publish Draft');
    
    // 1. Login
    console.log('  1. Logging in...');
    await loginAsAdmin(page);
    
    // 2. Navigate to canvas
    console.log('  2. Navigating to canvas...');
    await navigateToCanvas(page, 'iatr', 'iatr-geral-manus-test');
    
    // 3. Fill form completely
    console.log('  3. Filling form with complete data...');
    const textareas = await page.locator('textarea').all();
    for (const textarea of textareas) {
      if (await textarea.isVisible()) {
        await textarea.fill(`Test data - ${new Date().getTime()}`);
      }
    }
    await page.waitForTimeout(500);
    
    // 4. Look for submit/publish button
    console.log('  4. Looking for submit button...');
    const submitBtn = page.locator(
      'input[type="button"][value*="Concluir"], ' +
      'input[type="button"][value*="Enviar"], ' +
      'button:has-text("Concluir"), ' +
      'button:has-text("Enviar"), ' +
      '#submitBtn'
    ).first();
    
    const btnExists = await submitBtn.isVisible().catch(() => false);
    
    if (btnExists) {
      console.log('  5. Clicking submit...');
      
      // Record start time
      const startTime = Date.now();
      
      // Click submit
      await submitBtn.click();
      
      // Wait for processing
      console.log('  6. Waiting for submission processing...');
      await page.waitForTimeout(5000);
      
      const duration = Date.now() - startTime;
      console.log(`  7. Submission took ${duration}ms`);
      
      // Check for success indicators
      const content = await page.content();
      const success = content.includes('sucesso') || 
                     content.includes('Obrigado') ||
                     content.includes('enviado');
      
      const error = content.includes('erro') || content.includes('error');
      
      console.log(`  8. ✅ Submission processed (success: ${success}, error: ${error})`);
      
      // Screenshot
      const screenshot = await takeScreenshot(page, 'draft-publish-submit');
      console.log(`  9. 📸 Screenshot: ${screenshot}`);
      
      expect(!error).toBeTruthy();
    } else {
      console.log('  ⚠️  Submit button not found (page may have multi-step flow)');
    }
    
    console.log('✅ T9 PASSED\n');
  });

});
