/**
 * T6: Delete Draft (Deletar)
 * Valida deleção de draft
 */

const { chromium } = require('playwright');
const { loginAndOpenForm, CREDENTIALS_ADMIN, getCsrfToken, getCanvasTemplateId } = require('./helpers');

async function runTest() {
    console.log('🧪 T6: Delete Draft\n');
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
        
        // Create 2 drafts
        console.log('   Criando 2 drafts...');
        const draftIds = [];
        
        for (let i = 1; i <= 2; i++) {
            const createResp = await page.evaluate(async (args) => {
                const r = await fetch('/api/drafts/save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': args.csrf },
                    body: JSON.stringify({
                        canvas_template_id: args.templateId,
                        form_data: { test: 'data' },
                        label: `Draft ${args.index}`
                    })
                });
                return { status: r.status, body: await r.json() };
            }, { templateId: canvasTemplateId, csrf: csrfToken, index: i });
            
            if (createResp.status !== 200 || !createResp.body.draft_id) throw new Error(`Create failed`);
            draftIds.push(createResp.body.draft_id);
        }
        
        console.log(`   Drafts criados: ${draftIds.join(', ')}`);
        
        // Count before
        const listBefore = await page.evaluate(async (args) => {
            const r = await fetch(`/api/drafts/list.php?template_id=${args.templateId}`);
            return (await r.json()).count || 0;
        }, { templateId: canvasTemplateId });
        
        console.log(`   Count antes: ${listBefore}`);
        
        // T6.1: Button exists
        console.log('\n   [T6.1] Button "Meus Rascunhos" funciona...');
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
        
        // T6.2: Delete via API
        console.log('\n   [T6.2] Deletar draft via API...');
        const deleteResp = await page.evaluate(async (args) => {
            const r = await fetch('/api/drafts/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': args.csrf },
                body: JSON.stringify({ draft_id: args.draftId })
            });
            return { status: r.status, body: await r.text() };
        }, { csrf: csrfToken, draftId: draftIds[0] });
        
        if (deleteResp.status === 200) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'API delete retorna 200', status: 'PASS' });
        } else {
            console.log(`   ⚠️  API returned ${deleteResp.status}`);
            results.failed++;
            results.details.push({ test: 'API delete retorna 200', status: `FAIL - HTTP ${deleteResp.status}` });
        }
        
        // T6.3: Verify deleted
        console.log('\n   [T6.3] Verificar se draft foi deletado...');
        const listAfter = await page.evaluate(async (args) => {
            const r = await fetch(`/api/drafts/list.php?template_id=${args.templateId}`);
            return await r.json();
        }, { templateId: canvasTemplateId });
        
        if (!listAfter.drafts?.find(d => d.id === draftIds[0])) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'Draft removido da lista', status: 'PASS' });
        } else {
            console.log('   ❌ FAIL');
            results.failed++;
            results.details.push({ test: 'Draft removido da lista', status: 'FAIL' });
        }
        
        // T6.4: Counter decremented
        console.log('\n   [T6.4] Contador decrementou...');
        const countAfter = listAfter.count || 0;
        console.log(`   Count depois: ${countAfter} (antes: ${listBefore})`);
        
        if (countAfter < listBefore) {
            console.log('   ✅ PASS');
            results.passed++;
            results.details.push({ test: 'Contador decrementou', status: 'PASS' });
        } else {
            console.log('   ❌ FAIL');
            results.failed++;
            results.details.push({ test: 'Contador decrementou', status: 'FAIL' });
        }
        
        // Cleanup remaining
        for (const id of draftIds) {
            await page.evaluate(async (args) => {
                await fetch('/api/drafts/delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': args.csrf },
                    body: JSON.stringify({ draft_id: args.draftId })
                });
            }, { csrf: csrfToken, draftId: id });
        }
        
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
