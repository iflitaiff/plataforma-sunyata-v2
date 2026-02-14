/**
 * T8: IDOR Protection
 * Verifica que usuário A NÃO pode acessar/deletar drafts de usuário B
 */

const { chromium } = require('playwright');
const { loginAndOpenForm, CREDENTIALS_ADMIN, CREDENTIALS_TEST, getCsrfToken, getCanvasTemplateId } = require('./helpers');

async function runTest() {
    console.log('🧪 T8: IDOR Protection\n');
    
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    
    const results = {
        passed: 0,
        failed: 0,
        details: []
    };
    
    let adminDraftId = null;
    
    try {
        const templateSlug = 'iatr-geral-manus-test';
        
        // ===== SETUP: Criar draft como admin =====
        console.log('   [Setup] Fazendo login como admin...');
        await loginAndOpenForm(page, CREDENTIALS_ADMIN, 'iatr', templateSlug);
        
        const csrfAdmin = await getCsrfToken(page);
        const canvasTemplateId = await getCanvasTemplateId(page);
        
        console.log(`   CSRF Token: ${csrfAdmin?.substring(0, 20)}...`);
        console.log(`   Canvas Template ID: ${canvasTemplateId}`);
        
        if (!canvasTemplateId) {
            throw new Error('Canvas Template ID não encontrado');
        }
        
        // Limpar TODOS drafts do admin (aggressivamente)
        console.log('   [Setup] Limpando TODOS os drafts do admin...');
        const allDrafts = await page.evaluate(async () => {
            const r = await fetch('/api/drafts/list.php?template_id=3');  // Hardcoded for now
            if (r.ok) {
                const data = await r.json();
                return data.drafts || [];
            }
            return [];
        });
        
        console.log(`   [Setup] Encontrados ${allDrafts.length} drafts - deletando...`);
        for (const draft of allDrafts) {
            await page.evaluate(async (args) => {
                await fetch(`/api/drafts/delete.php?id=${args.draftId}`, { method: 'POST' });
            }, { draftId: draft.id });
        }
        
        await page.waitForTimeout(1000);
        
        // Criar 1 draft como admin via API
        console.log('   [Setup] Criando draft como admin...');
        const adminDraftResponse = await page.evaluate(async (args) => {
            const { templateId, csrf } = args;
            const r = await fetch('/api/drafts/save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    canvas_template_id: templateId,
                    form_data: { campo_test: 'DRAFT DO ADMIN' },
                    label: 'Draft Admin - Teste IDOR'
                })
            });
            return { status: r.status, body: await r.json() };
        }, { templateId: canvasTemplateId, csrf: csrfAdmin });
        
        adminDraftId = adminDraftResponse.body.draft_id;
        console.log(`   [Setup] Admin draft criado: ID ${adminDraftId}`);
        
        if (!adminDraftId) {
            throw new Error(`Falha ao criar draft do admin: ${JSON.stringify(adminDraftResponse.body)}`);
        }
        
        results.details.push({ test: 'Draft admin criado', status: 'PASS', id: adminDraftId });
        
        // ===== LOGOUT =====
        console.log('   [Setup] Fazendo logout do admin...');
        await page.goto('http://158.69.25.114/auth/logout.php');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
        
        // ===== LOGIN COMO TEST USER =====
        console.log('   Fazendo login como test user...');
        await loginAndOpenForm(page, CREDENTIALS_TEST, 'iatr', templateSlug);
        
        // ===== T8.1: Tentar CARREGAR draft do admin =====
        console.log('   [T8.1] Tentando carregar draft do admin...');
        const loadResponse = await page.evaluate(async (args) => {
            const { draftId } = args;
            const r = await fetch(`/api/drafts/load.php?id=${draftId}`);
            return { status: r.status, body: await r.json() };
        }, { draftId: adminDraftId });
        
        console.log(`   Load draft: HTTP ${loadResponse.status}`);
        
        if (loadResponse.status === 404 || loadResponse.status === 403) {
            console.log(`   ✅ HTTP ${loadResponse.status} retornado (acesso negado)`);
            results.passed++;
            results.details.push({ test: 'LOAD: acesso negado (404/403)', status: 'PASS', status_code: loadResponse.status });
        } else if (loadResponse.status === 401) {
            console.log(`   ✅ HTTP 401 (não autenticado ou sem permissão)`);
            results.passed++;
            results.details.push({ test: 'LOAD: acesso negado (401)', status: 'PASS', status_code: loadResponse.status });
        } else {
            console.log(`   ❌ HTTP ${loadResponse.status} - deveria ser 403/404/401`);
            results.failed++;
            results.details.push({ test: 'LOAD: acesso negado', status: 'FAIL', expected: '403/404/401', actual: loadResponse.status });
        }
        
        // Verificar success: false
        if (loadResponse.body.success === false) {
            console.log('   ✅ body.success === false');
            results.passed++;
            results.details.push({ test: 'LOAD: success=false', status: 'PASS' });
        } else {
            console.log(`   ❌ body.success deveria ser false: ${loadResponse.body.success}`);
            results.failed++;
            results.details.push({ test: 'LOAD: success=false', status: 'FAIL' });
        }
        
        // ===== T8.2: Tentar DELETAR draft do admin =====
        console.log('   [T8.2] Tentando deletar draft do admin...');
        const deleteResponse = await page.evaluate(async (args) => {
            const { draftId } = args;
            const r = await fetch(`/api/drafts/delete.php?id=${draftId}`, {
                method: 'POST'
            });
            return { status: r.status, body: await r.json() };
        }, { draftId: adminDraftId });
        
        console.log(`   Delete draft: HTTP ${deleteResponse.status}`);
        
        if (deleteResponse.status === 404 || deleteResponse.status === 403) {
            console.log(`   ✅ HTTP ${deleteResponse.status} retornado (acesso negado)`);
            results.passed++;
            results.details.push({ test: 'DELETE: acesso negado (404/403)', status: 'PASS', status_code: deleteResponse.status });
        } else if (deleteResponse.status === 401) {
            console.log(`   ✅ HTTP 401 (não autenticado ou sem permissão)`);
            results.passed++;
            results.details.push({ test: 'DELETE: acesso negado (401)', status: 'PASS', status_code: deleteResponse.status });
        } else {
            console.log(`   ❌ HTTP ${deleteResponse.status} - deveria ser 403/404/401`);
            results.failed++;
            results.details.push({ test: 'DELETE: acesso negado', status: 'FAIL', expected: '403/404/401', actual: deleteResponse.status });
        }
        
        // Verificar success: false
        if (deleteResponse.body.success === false) {
            console.log('   ✅ body.success === false');
            results.passed++;
            results.details.push({ test: 'DELETE: success=false', status: 'PASS' });
        } else {
            console.log(`   ❌ body.success deveria ser false: ${deleteResponse.body.success}`);
            results.failed++;
            results.details.push({ test: 'DELETE: success=false', status: 'FAIL' });
        }
        
        // ===== LOGOUT test user =====
        console.log('   [Cleanup] Fazendo logout do test user...');
        await page.goto('http://158.69.25.114/auth/logout.php');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
        
        // ===== LOGIN NOVAMENTE COMO ADMIN =====
        console.log('   [Verification] Fazendo login como admin novamente...');
        await loginAndOpenForm(page, CREDENTIALS_ADMIN, 'iatr', templateSlug);
        
        // ===== T8.3: Verificar que draft admin ainda existe =====
        console.log('   [T8.3] Verificando que draft do admin permaneceu intacto...');
        const adminDrafts = await page.evaluate(async (args) => {
            const { templateId } = args;
            const r = await fetch(`/api/drafts/list.php?template_id=${templateId}`);
            if (r.ok) {
                const data = await r.json();
                return data.drafts || [];
            }
            return [];
        }, { templateId: canvasTemplateId });
        
        const draftStillExists = adminDrafts.some(d => d.id === adminDraftId);
        
        if (draftStillExists) {
            console.log(`   ✅ Draft ${adminDraftId} ainda existe`);
            results.passed++;
            results.details.push({ test: 'Draft admin ainda existe', status: 'PASS' });
        } else {
            console.log(`   ❌ Draft ${adminDraftId} foi deletado ou não encontrado`);
            results.failed++;
            results.details.push({ test: 'Draft admin ainda existe', status: 'FAIL' });
        }
        
    } catch (error) {
        console.log(`   ❌ Erro: ${error.message}`);
        results.failed++;
        results.details.push({ test: 'Execução geral', status: 'ERROR', error: error.message });
    } finally {
        await browser.close();
    }
    
    return results;
}

module.exports = { runTest };
