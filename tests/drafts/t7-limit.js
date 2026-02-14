/**
 * T7: Limite de 10 Drafts
 * Verifica que o sistema rejeita o 11º draft com erro HTTP 409 Conflict
 */

const { chromium } = require('playwright');
const { loginAndOpenForm, CREDENTIALS_ADMIN, getCsrfToken, getCanvasTemplateId } = require('./helpers');

async function runTest() {
    console.log('🧪 T7: Limite de 10 Drafts\n');
    
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    
    const results = {
        passed: 0,
        failed: 0,
        details: []
    };
    
    try {
        // Login
        console.log('   Fazendo login como admin...');
        await loginAndOpenForm(page, CREDENTIALS_ADMIN, 'iatr', 'iatr-geral-manus-test');
        
        const csrfToken = await getCsrfToken(page);
        const canvasTemplateId = await getCanvasTemplateId(page);
        
        console.log(`   CSRF Token: ${csrfToken?.substring(0, 20)}...`);
        console.log(`   Canvas Template ID: ${canvasTemplateId}`);
        
        if (!csrfToken || !canvasTemplateId) {
            throw new Error(`CSRF token ou Template ID não encontrados. CSRF: ${!!csrfToken}, Template: ${!!canvasTemplateId}`);
        }
        
        // Limpar drafts existentes
        console.log('   Limpando drafts existentes...');
        await page.evaluate(async (args) => {
            const { templateId } = args;
            const r = await fetch(`/api/drafts/list.php?template_id=${templateId}`);
            if (r.ok) {
                const data = await r.json();
                if (data.drafts && data.drafts.length > 0) {
                    for (const draft of data.drafts) {
                        await fetch(`/api/drafts/delete.php?id=${draft.id}`, { method: 'DELETE' });
                    }
                }
            }
        }, { templateId: canvasTemplateId });
        
        await page.waitForTimeout(500);
        
        // Criar 10 drafts via API
        console.log('   Criando 10 drafts...');
        const createdIds = [];
        
        for (let i = 0; i < 10; i++) {
            const response = await page.evaluate(async (args) => {
                const { templateId, csrf, index } = args;
                const r = await fetch('/api/drafts/save.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        canvas_template_id: templateId,  // Must be number!
                        form_data: { campo_test: `draft ${index}` },
                        label: `Draft ${index + 1}`
                    })
                });
                return { status: r.status, body: await r.json() };
            }, { templateId: canvasTemplateId, csrf: csrfToken, index: i });
            
            console.log(`     Draft ${i + 1}: HTTP ${response.status}`);
            
            if (response.status === 200 && response.body.draft_id) {
                createdIds.push(response.body.draft_id);
            } else if (response.status === 200) {
                console.log(`     ⚠️  Draft ${i + 1}: ${JSON.stringify(response.body)}`);
            } else {
                console.log(`     ⚠️  Draft ${i + 1} erro: ${response.body.error}`);
            }
        }
        
        console.log(`   Criados: ${createdIds.length} drafts`);
        
        // Verificar que 10 drafts foram criados
        console.log('   Verificando contagem de drafts...');
        const count = await page.evaluate(async (args) => {
            const { templateId } = args;
            const r = await fetch(`/api/drafts/list.php?template_id=${templateId}`);
            if (r.ok) {
                const data = await r.json();
                return data.drafts ? data.drafts.length : 0;
            }
            return 0;
        }, { templateId: canvasTemplateId });
        
        console.log(`   Drafts encontrados: ${count}`);
        
        if (count === 10) {
            console.log('   ✅ 10 drafts criados com sucesso');
            results.passed++;
            results.details.push({ test: '10 drafts criados', status: 'PASS', count });
        } else {
            console.log(`   ❌ Esperado 10 drafts, encontrado ${count}`);
            results.failed++;
            results.details.push({ test: '10 drafts criados', status: 'FAIL', expected: 10, actual: count });
        }
        
        // Tentar criar 11º draft (deve falhar com 409)
        console.log('   Tentando criar 11º draft (deve falhar)...');
        const response11 = await page.evaluate(async (args) => {
            const { templateId, csrf } = args;
            const r = await fetch('/api/drafts/save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    canvas_template_id: templateId,
                    form_data: { campo_test: 'draft 11' },
                    label: 'Draft 11 - SHOULD FAIL'
                })
            });
            return { status: r.status, body: await r.json() };
        }, { templateId: canvasTemplateId, csrf: csrfToken });
        
        console.log(`   11º draft: HTTP ${response11.status}`);
        console.log(`   Resposta: ${JSON.stringify(response11.body)}`);
        
        // Verificar HTTP 409
        if (response11.status === 409) {
            console.log('   ✅ HTTP 409 retornado');
            results.passed++;
            results.details.push({ test: 'HTTP 409 no 11º draft', status: 'PASS' });
        } else {
            console.log(`   ❌ Esperado HTTP 409, recebido ${response11.status}`);
            results.failed++;
            results.details.push({ test: 'HTTP 409 no 11º draft', status: 'FAIL', expected: 409, actual: response11.status });
        }
        
        // Verificar mensagem de erro contém "limite"
        const errorMsg = response11.body.error || response11.body.message || '';
        if (errorMsg.toLowerCase().includes('limite') || errorMsg.toLowerCase().includes('máximo')) {
            console.log('   ✅ Mensagem de erro contém "limite" ou "máximo"');
            results.passed++;
            results.details.push({ test: 'Mensagem de erro contém "limite"', status: 'PASS', message: errorMsg });
        } else {
            console.log(`   ⚠️  Mensagem não contém "limite": "${errorMsg}"`);
            results.details.push({ test: 'Mensagem de erro contém "limite"', status: 'WARN', message: errorMsg });
        }
        
        // Verificar contador permanece 10
        const finalCount = await page.evaluate(async (args) => {
            const { templateId } = args;
            const r = await fetch(`/api/drafts/list.php?template_id=${templateId}`);
            if (r.ok) {
                const data = await r.json();
                return data.drafts ? data.drafts.length : 0;
            }
            return 0;
        }, { templateId: canvasTemplateId });
        
        console.log(`   Contagem final: ${finalCount} drafts`);
        
        if (finalCount === 10) {
            console.log('   ✅ Contador permaneceu em 10');
            results.passed++;
            results.details.push({ test: 'Contador permaneceu em 10', status: 'PASS' });
        } else {
            console.log(`   ❌ Contador mudou para ${finalCount}`);
            results.failed++;
            results.details.push({ test: 'Contador permaneceu em 10', status: 'FAIL', expected: 10, actual: finalCount });
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
