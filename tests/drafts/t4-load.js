/**
 * T4: Load Draft (Carregar)
 * Valida que draft é carregado corretamente
 */

const { chromium } = require('playwright');
const { loginAndOpenForm, CREDENTIALS_ADMIN, getCsrfToken, getCanvasTemplateId } = require('./helpers');

async function runTest() {
    console.log('🧪 T4: Load Draft\n');
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const results = { passed: 0, failed: 0, details: [] };
    
    try {
        await loginAndOpenForm(page, CREDENTIALS_ADMIN, 'iatr', 'iatr-geral-manus-test');
        const csrfToken = await getCsrfToken(page);
        const canvasTemplateId = await getCanvasTemplateId(page);
        if (!csrfToken || !canvasTemplateId) throw new Error('Missing credentials');
        
        // Cleanup
        console.log('   Limpando drafts...');
        await page.evaluate(async (args) => {
            const { templateId, csrf } = args;
            const r = await fetch(`/api/drafts/list.php?template_id=${templateId}`);
            if (r.ok) {
                const data = await r.json();
                for (const d of data.drafts || []) {
                    await fetch('/api/drafts/delete.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ draft_id: d.id })
                    });
                }
            }
        }, { templateId: canvasTemplateId, csrf: csrfToken });
        
        // Create draft
        console.log('   Criando draft...');
        const testLabel = `Draft Test ${Date.now()}`;
        const createResp = await page.evaluate(async (args) => {
            const r = await fetch('/api/drafts/save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': args.csrf },
                body: JSON.stringify({
                    canvas_template_id: args.templateId,
                    form_data: { test: 'data' },
                    label: args.label
                })
            });
            return { status: r.status, body: await r.json() };
        }, { templateId: canvasTemplateId, csrf: csrfToken, label: testLabel });
        
        if (createResp.status !== 200 || !createResp.body.draft_id) throw new Error(`Create failed: ${JSON.stringify(createResp.body)}`);
        const draftId = createResp.body.draft_id;
        console.log(`   Draft criado: ${draftId}`);
        
        // T4.1: Modal abre
        console.log('\n   [T4.1] Modal de drafts abre...');
        const openBtn = page.locator('#openDraftsBtn');
        if (await openBtn.isVisible()) {
            await openBtn.click();
            await page.waitForTimeout(1000);
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'Modal de drafts abre', status: 'PASS' });
        } else {
            throw new Error('openDraftsBtn not found');
        }
        
        // T4.2: Draft na lista
        console.log('\n   [T4.2] Draft aparece na lista...');
        const bodyText = await page.locator('body').textContent();
        if (bodyText.includes(testLabel)) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'Draft aparece na lista', status: 'PASS' });
        } else {
            console.log('   ❌ FAIL');
            results.failed++;
            results.details.push({ test: 'Draft aparece na lista', status: 'FAIL' });
        }
        
        // T4.3: API retorna draft
        console.log('\n   [T4.3] API retorna draft...');
        const listResp = await page.evaluate(async (args) => {
            const r = await fetch(`/api/drafts/list.php?template_id=${args.templateId}`);
            return await r.json();
        }, { templateId: canvasTemplateId });
        
        if (listResp.drafts?.find(d => d.id === draftId)) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'API retorna draft', status: 'PASS' });
        } else {
            console.log('   ❌ FAIL');
            results.failed++;
            results.details.push({ test: 'API retorna draft', status: 'FAIL' });
        }
        
        // T4.4: Label correto
        console.log('\n   [T4.4] Label correto...');
        const draft = listResp.drafts?.find(d => d.id === draftId);
        if (draft && draft.label === testLabel) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'Label correto', status: 'PASS' });
        } else {
            console.log('   ❌ FAIL');
            results.failed++;
            results.details.push({ test: 'Label correto', status: 'FAIL' });
        }
        
        // Cleanup
        await page.evaluate(async (args) => {
            await fetch('/api/drafts/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': args.csrf },
                body: JSON.stringify({ draft_id: args.draftId })
            });
        }, { csrf: csrfToken, draftId });
        
    } catch (error) {
        console.error(`\n❌ Error: ${error.message}`);
        results.failed++;
        results.details.push({ test: 'Execution', status: `FAIL - ${error.message}` });
    } finally {
        await browser.close();
    }
    
    console.log('\n' + '='.repeat(50));
    console.log(`RESULTADO: ${results.passed}/${results.passed + results.failed}`);
    console.log('='.repeat(50));
    results.details.forEach(d => console.log(`${d.status.startsWith('PASS') ? '✅' : '❌'} ${d.test}: ${d.status}`));
    
    return results;
}

if (require.main === module) runTest().then(r => process.exit(r.failed > 0 ? 1 : 0));
module.exports = { runTest };
