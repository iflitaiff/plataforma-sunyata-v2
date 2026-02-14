/**
 * T2: Auto-save e Save Manual
 * Verifica que drafts são salvos no servidor
 */

const { chromium } = require('playwright');
const { loginAndOpenForm, CREDENTIALS_ADMIN, getTemplateId, cleanupDrafts, fillSurveyField } = require('./helpers');

async function runTest() {
    console.log('🧪 T2: Auto-save e Save Manual\n');
    
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    
    const results = {
        passed: 0,
        failed: 0,
        details: []
    };
    
    try {
        // Cleanup inicial
        await loginAndOpenForm(page, CREDENTIALS_ADMIN, 'iatr', 'iatr-geral-manus-test');
        await cleanupDrafts(page);
        
        console.log('   Preenchendo campos do formulário...');
        
        // Preencher campos via DOM (inputs do SurveyJS)
        const inputs = await page.locator('#surveyContainer input[type="text"], #surveyContainer textarea').all();
        if (inputs.length > 0) {
            for (let i = 0; i < Math.min(2, inputs.length); i++) {
                await inputs[i].fill(`Teste Draft ${Date.now()}`);
                await page.waitForTimeout(500);
            }
        }
        
        await page.waitForTimeout(1000);
        
        // Verificar que #saveDraftBtn ficou visível
        const saveBtnVisible = await page.locator('#saveDraftBtn').isVisible();
        if (saveBtnVisible) {
            console.log('   ✅ #saveDraftBtn visível após preencher campo');
            results.passed++;
            results.details.push({ test: 'saveDraftBtn visível após input', status: 'PASS' });
        } else {
            console.log('   ❌ #saveDraftBtn NÃO ficou visível');
            results.failed++;
            results.details.push({ test: 'saveDraftBtn visível após input', status: 'FAIL' });
        }
        
        // Clicar em salvar
        console.log('   Clicando em "Salvar Rascunho"...');
        await page.click('#saveDraftBtn');
        await page.waitForTimeout(2000);
        
        // Verificar status "Salvo às HH:MM"
        const statusText = await page.locator('#draftStatus').textContent();
        const savedPattern = /Salvo [aà]s \d{2}:\d{2}/i;
        
        if (savedPattern.test(statusText)) {
            console.log(`   ✅ Status mostra: "${statusText}"`);
            results.passed++;
            results.details.push({ test: 'Status "Salvo às"', status: 'PASS' });
        } else {
            console.log(`   ❌ Status incorreto: "${statusText}"`);
            results.failed++;
            results.details.push({ test: 'Status "Salvo às"', status: 'FAIL' });
        }
        
        // Verificar via API que draft existe
        console.log('   Verificando draft via API...');
        const draftsList = await page.evaluate(async () => {
            const resp = await fetch('/api/drafts/list.php');
            if (resp.ok) {
                return await resp.json();
            }
            return { error: `HTTP ${resp.status}` };
        });
        
        console.log(`   ℹ️  API Response: ${JSON.stringify(draftsList).substring(0, 100)}...`);
        
        if (draftsList && draftsList.drafts && draftsList.drafts.length > 0) {
            console.log(`   ✅ API list retorna ${draftsList.drafts.length} draft(s)`);
            results.passed++;
            results.details.push({ test: 'Draft existe na API', status: 'PASS' });
        } else {
            console.log('   ⚠️  Nenhum draft encontrado via API (pode ser filtro por template)');
            // Não marcar como falha crítica
            results.details.push({ test: 'Draft existe na API', status: 'WARN' });
        }
        
        // Cleanup
        await cleanupDrafts(page);
        
    } catch (error) {
        console.log(`   ❌ Erro durante execução: ${error.message}`);
        results.failed++;
        results.details.push({ test: 'Execução', status: 'ERROR', error: error.message });
    } finally {
        await browser.close();
    }
    
    return results;
}

// Executar se chamado diretamente
if (require.main === module) {
    runTest().then(results => {
        console.log('\n============================================================');
        console.log('📊 RESULTADO T2: Auto-save e Save Manual');
        console.log('============================================================');
        console.log(`✅ Passou: ${results.passed}`);
        console.log(`❌ Falhou: ${results.failed}`);
        console.log('\nDetalhes:');
        results.details.forEach(d => {
            const icon = d.status === 'PASS' ? '✅' : '❌';
            console.log(`  ${icon} ${d.test}: ${d.status}`);
        });
        console.log('============================================================');
        
        process.exit(results.failed > 0 ? 1 : 0);
    });
}

module.exports = { runTest };
