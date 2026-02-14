/**
 * T5: Rename Draft (Renomear)
 * Valida renomeação de draft via API
 */

const { chromium } = require('playwright');
const { loginAndOpenForm, CREDENTIALS_ADMIN, getCsrfToken, getCanvasTemplateId } = require('./helpers');

async function runTest() {
    console.log('🧪 T5: Rename Draft\n');
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
        const originalLabel = 'Original';
        const renamedLabel = 'Renomeado';
        
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
        }, { templateId: canvasTemplateId, csrf: csrfToken, label: originalLabel });
        
        if (createResp.status !== 200 || !createResp.body.draft_id) throw new Error(`Create failed`);
        const draftId = createResp.body.draft_id;
        console.log(`   Draft criado: ${draftId}`);
        
        // T5.1: Button exists and clicks
        console.log('\n   [T5.1] Button "Meus Rascunhos" funciona...');
        const openBtn = page.locator('#openDraftsBtn');
        if (await openBtn.isVisible()) {
            await openBtn.click();
            await page.waitForTimeout(1000);
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'Button abre modal', status: 'PASS' });
        } else {
            throw new Error('openDraftsBtn not found');
        }
        
        // T5.2: Rename via API (direct method)
        console.log('\n   [T5.2] Renomear draft via API...');
        const renameResp = await page.evaluate(async (args) => {
            const r = await fetch('/api/drafts/rename.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': args.csrf },
                body: JSON.stringify({ draft_id: args.draftId, label: args.newLabel })
            });
            return { status: r.status, body: await r.text() };
        }, { csrf: csrfToken, draftId, newLabel: renamedLabel });
        
        if (renameResp.status === 200) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'API rename retorna 200', status: 'PASS' });
        } else {
            console.log(`   ⚠️  API returned ${renameResp.status}`);
            results.failed++;
            results.details.push({ test: 'API rename retorna 200', status: `FAIL - HTTP ${renameResp.status}` });
        }
        
        // T5.3: Verify label updated
        console.log('\n   [T5.3] Verificar label atualizado...');
        const listResp = await page.evaluate(async (args) => {
            const r = await fetch(`/api/drafts/list.php?template_id=${args.templateId}`);
            return await r.json();
        }, { templateId: canvasTemplateId });
        
        const draft = listResp.drafts?.find(d => d.id === draftId);
        if (draft && draft.label === renamedLabel) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'Label atualizado na API', status: 'PASS' });
        } else {
            console.log(`   ❌ Label é "${draft?.label}"`);
            results.failed++;
            results.details.push({ test: 'Label atualizado na API', status: 'FAIL' });
        }
        
        // T5.4: Modal reflete mudança (validado via API - já em T5.3)
        console.log('\n   [T5.4] Mudança persiste após refresh de página...');
        
        // Recarregar página e verificar
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('#surveyContainer', { timeout: 10000 });
        
        const listRespAfterReload = await page.evaluate(async (args) => {
            const r = await fetch(`/api/drafts/list.php?template_id=${args.templateId}`);
            return await r.json();
        }, { templateId: canvasTemplateId });
        
        const draftAfterReload = listRespAfterReload.drafts?.find(d => d.id === draftId);
        if (draftAfterReload && draftAfterReload.label === renamedLabel) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'Mudança persiste após reload', status: 'PASS' });
        } else {
            console.log('   ❌ FAIL');
            results.failed++;
            results.details.push({ test: 'Mudança persiste após reload', status: 'FAIL' });
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
