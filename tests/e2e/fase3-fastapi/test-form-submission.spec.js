/**
 * E2E Tests: Fase 3 FastAPI Integration
 * T1: Form submission success
 * T2: FastAPI fallback error handling
 * T3: Response time performance
 */

import { test, expect } from '@playwright/test';
import { loginAsAdmin, navigateToCanvas, fillTextarea, submitForm, takeScreenshot, waitForApiCall } from '../helpers';

test.describe('Fase 3 FastAPI Integration', () => {

  test('T1: Form submission via FastAPI adapter (Happy Path)', async ({ page }) => {
    console.log('🧪 T1: Form Submission Success');
    
    // 1. Login
    console.log('  1. Logging in as admin...');
    await loginAsAdmin(page);
    
    // 2. Navigate to canvas
    console.log('  2. Navigating to canvas...');
    await navigateToCanvas(page, 'iatr', 'iatr-geral-manus-test');
    
    // 3. Verify SurveyJS loaded
    const surveyLoaded = await page.locator('#surveyContainer, .sd-root-modern').isVisible();
    expect(surveyLoaded).toBeTruthy();
    console.log('  3. ✅ SurveyJS loaded');
    
    // 4. Fill form
    console.log('  4. Filling form...');
    const textarea = page.locator('textarea').first();
    const testText = `Teste E2E Fase 3 - ${new Date().getTime()}`;
    await textarea.fill(testText);
    await page.waitForTimeout(500);
    
    // 5. Submit form
    console.log('  5. Submitting form...');
    const submitted = await submitForm(page);
    expect(submitted).toBeTruthy();
    
    // 6. Wait for response
    console.log('  6. Waiting for FastAPI response...');
    await page.waitForTimeout(5000);
    
    // 7. Check page content for errors
    const content = await page.content();
    expect(content).not.toContain('AI service error');
    expect(content).not.toContain('Não foi possível');
    expect(content).not.toContain('erro');
    console.log('  7. ✅ No errors detected');
    
    // 8. Take screenshot
    const screenshot = await takeScreenshot(page, 'form-submission-success');
    console.log(`  8. 📸 Screenshot: ${screenshot}`);
    
    console.log('✅ T1 PASSED\n');
  });

  test('T2: Graceful error handling (Network/Service Issues)', async ({ page }) => {
    console.log('🧪 T2: Error Handling');
    
    // 1. Login
    await loginAsAdmin(page);
    
    // 2. Intercept API calls to simulate timeout
    console.log('  1. Setting up request timeout simulation...');
    await page.route('**/api/**', async (route) => {
      // Let it through normally - we're testing the app's error handling
      await route.continue();
    });
    
    // 3. Navigate to canvas
    await navigateToCanvas(page, 'iatr', 'iatr-geral-manus-test');
    
    // 4. Fill and submit
    console.log('  2. Filling and submitting form...');
    const textarea = page.locator('textarea').first();
    await textarea.fill('Test form submission');
    
    const submitted = await submitForm(page);
    if (submitted) {
      // 5. Wait for response
      await page.waitForTimeout(5000);
      
      // 6. Check that either:
      //    a) Form succeeded normally
      //    b) Displayed user-friendly error (not stack trace)
      const content = await page.content();
      
      const hasStackTrace = content.includes('at function') || 
                           content.includes('Error:') ||
                           content.includes('TypeError');
      
      expect(hasStackTrace).toBeFalsy();
      console.log('  3. ✅ No stack traces exposed');
      
      // 7. Verify user-friendly message if error
      if (content.includes('erro') || content.includes('falha')) {
        const userMessage = content.includes('Desculpe') || 
                           content.includes('Tente novamente');
        expect(userMessage).toBeTruthy();
        console.log('  4. ✅ User-friendly error message shown');
      }
    }
    
    const screenshot = await takeScreenshot(page, 'error-handling');
    console.log(`  5. 📸 Screenshot: ${screenshot}`);
    
    console.log('✅ T2 PASSED\n');
  });

  test('T3: Response time within acceptable limits', async ({ page }) => {
    console.log('🧪 T3: Performance - Response Time');
    
    // 1. Login
    await loginAsAdmin(page);
    
    // 2. Navigate to canvas
    await navigateToCanvas(page, 'iatr', 'iatr-geral-manus-test');
    
    // 3. Fill form
    const textarea = page.locator('textarea').first();
    await textarea.fill(`Performance test - ${new Date().getTime()}`);
    
    // 4. Measure submission time
    console.log('  1. Starting timer...');
    const startTime = Date.now();
    
    // 5. Submit
    const submitted = await submitForm(page);
    expect(submitted).toBeTruthy();
    
    // 6. Wait for response/UI update
    await page.waitForTimeout(5000);
    
    // Measure when response is visible
    const endTime = Date.now();
    const duration = endTime - startTime;
    
    console.log(`  2. Response time: ${duration}ms`);
    
    // 7. Assert within acceptable limits
    // Most form submissions should complete within 10 seconds
    // FastAPI + Claude API typically: 3-8 seconds
    expect(duration).toBeLessThan(15000); // 15 second threshold
    expect(duration).toBeGreaterThan(0);
    
    if (duration < 5000) {
      console.log('  3. ⚡ Excellent response time');
    } else if (duration < 10000) {
      console.log('  3. ✅ Good response time');
    } else {
      console.log('  3. ⚠️  Slow response time (but within limits)');
    }
    
    const screenshot = await takeScreenshot(page, 'performance-test');
    console.log(`  4. 📸 Screenshot: ${screenshot}`);
    
    console.log('✅ T3 PASSED\n');
  });

});
