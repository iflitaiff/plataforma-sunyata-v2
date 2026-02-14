/**
 * T1: UI Elements Presentes
 * Verifica que os botões de draft foram adicionados corretamente
 */

const { chromium } = require('playwright');
const { loginAndOpenForm, CREDENTIALS_ADMIN, cleanupDrafts } = require('./helpers');

async function runTest() {
    console.log('🧪 T1: UI Elements Presentes\n');
    
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    
    const results = {
        passed: 0,
        failed: 0,
        details: []
    };
    
    try {
        // Login e abrir formulário
        console.log('   Fazendo login e abrindo formulário...');
        await loginAndOpenForm(page, CREDENTIALS_ADMIN, 'iatr', 'iatr-geral-manus-test');
        
        // Verificar #openDraftsBtn (visível)
        const openBtnVisible = await page.locator('#openDraftsBtn').isVisible();
        if (openBtnVisible) {
            console.log('   ✅ #openDraftsBtn visível');
            results.passed++;
            results.details.push({ test: 'openDraftsBtn visível', status: 'PASS' });
        } else {
            console.log('   ❌ #openDraftsBtn NÃO visível');
            results.failed++;
            results.details.push({ test: 'openDraftsBtn visível', status: 'FAIL' });
        }
        
        // Verificar #saveDraftBtn (existe, mas display:none inicialmente)
        const saveBtnExists = await page.locator('#saveDraftBtn').count() > 0;
        if (saveBtnExists) {
            const saveBtnHidden = await page.locator('#saveDraftBtn').evaluate(el => {
                const style = window.getComputedStyle(el);
                return style.display === 'none';
            });
            
            if (saveBtnHidden) {
                console.log('   ✅ #saveDraftBtn existe e está oculto inicialmente');
                results.passed++;
                results.details.push({ test: 'saveDraftBtn oculto', status: 'PASS' });
            } else {
                console.log('   ⚠️  #saveDraftBtn existe mas está visível (esperado oculto)');
                results.failed++;
                results.details.push({ test: 'saveDraftBtn oculto', status: 'FAIL' });
            }
        } else {
            console.log('   ❌ #saveDraftBtn NÃO existe');
            results.failed++;
            results.details.push({ test: 'saveDraftBtn existe', status: 'FAIL' });
        }
        
        // Verificar #draftStatus (existe)
        const statusExists = await page.locator('#draftStatus').count() > 0;
        if (statusExists) {
            console.log('   ✅ #draftStatus existe');
            results.passed++;
            results.details.push({ test: 'draftStatus existe', status: 'PASS' });
        } else {
            console.log('   ❌ #draftStatus NÃO existe');
            results.failed++;
            results.details.push({ test: 'draftStatus existe', status: 'FAIL' });
        }
        
        // Verificar que não há erros JS no console
        const consoleErrors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });
        
        await page.waitForTimeout(1000);
        
        if (consoleErrors.length === 0) {
            console.log('   ✅ Zero erros JS no console');
            results.passed++;
            results.details.push({ test: 'Sem erros console', status: 'PASS' });
        } else {
            console.log('   ❌ Erros JS detectados:');
            consoleErrors.forEach(err => console.log(`      - ${err}`));
            results.failed++;
            results.details.push({ test: 'Sem erros console', status: 'FAIL' });
        }
        
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
        console.log('📊 RESULTADO T1: UI Elements');
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
