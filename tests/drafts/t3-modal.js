/**
 * T3: Modal "Meus Rascunhos"
 * Verifica abertura do modal com lista de drafts
 */

const { chromium } = require('playwright');
const { loginAndOpenForm, CREDENTIALS_ADMIN, cleanupDrafts } = require('./helpers');

async function runTest() {
    console.log('🧪 T3: Modal "Meus Rascunhos"\n');
    
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    
    const results = {
        passed: 0,
        failed: 0,
        details: []
    };
    
    try {
        // Login e abrir formulário
        await loginAndOpenForm(page, CREDENTIALS_ADMIN, 'iatr', 'iatr-geral-manus-test');
        await cleanupDrafts(page);
        
        // Criar 1 draft para testar o modal
        console.log('   Criando draft para teste...');
        const firstInput = await page.locator('#surveyContainer input[type="text"], #surveyContainer textarea').first();
        await firstInput.fill('Teste Modal Draft');
        
        // Aguardar botão ficar visível ou forçar após timeout
        await page.waitForTimeout(2000);
        const btnVisible = await page.locator('#saveDraftBtn').isVisible();
        
        if (btnVisible) {
            console.log('   ℹ️  Botão visível, clicando...');
            await page.click('#saveDraftBtn');
        } else {
            console.log('   ℹ️  Botão oculto, forçando clique...');
            await page.locator('#saveDraftBtn').evaluate(el => el.click());
        }
        
        await page.waitForTimeout(2000);
        
        // Clicar em "Meus Rascunhos"
        console.log('   Clicando em "Meus Rascunhos"...');
        await page.click('#openDraftsBtn');
        
        // Aguardar modal abrir
        const modalVisible = await page.waitForSelector('#draftsModal.show', { timeout: 5000 })
            .then(() => true)
            .catch(() => false);
        
        if (modalVisible) {
            console.log('   ✅ Modal abriu com classe .show');
            results.passed++;
            results.details.push({ test: 'Modal abre', status: 'PASS' });
        } else {
            console.log('   ❌ Modal NÃO abriu');
            results.failed++;
            results.details.push({ test: 'Modal abre', status: 'FAIL' });
        }
        
        // Verificar título do modal
        const modalTitle = await page.locator('#draftsModal .modal-title').textContent();
        if (modalTitle.includes('Meus Rascunhos') || modalTitle.includes('Rascunhos')) {
            console.log(`   ✅ Título do modal: "${modalTitle}"`);
            results.passed++;
            results.details.push({ test: 'Título correto', status: 'PASS' });
        } else {
            console.log(`   ❌ Título incorreto: "${modalTitle}"`);
            results.failed++;
            results.details.push({ test: 'Título correto', status: 'FAIL' });
        }
        
        // Verificar badge de contagem
        const badgeText = await page.locator('#draftsModal .badge').textContent().catch(() => '');
        const badgePattern = /\d+\/10/;
        
        if (badgePattern.test(badgeText)) {
            console.log(`   ✅ Badge com formato N/10: "${badgeText}"`);
            results.passed++;
            results.details.push({ test: 'Badge formato N/10', status: 'PASS' });
        } else {
            console.log(`   ⚠️  Badge não encontrado ou formato incorreto: "${badgeText}"`);
            results.failed++;
            results.details.push({ test: 'Badge formato N/10', status: 'FAIL' });
        }
        
        // Verificar presença de pelo menos 1 draft card
        const draftCards = await page.locator('.draft-card').count();
        if (draftCards > 0) {
            console.log(`   ✅ ${draftCards} draft card(s) presente(s)`);
            results.passed++;
            results.details.push({ test: 'Draft cards presentes', status: 'PASS' });
        } else {
            console.log('   ❌ Nenhum draft card encontrado');
            results.failed++;
            results.details.push({ test: 'Draft cards presentes', status: 'FAIL' });
        }
        
        // Verificar estrutura do card
        const firstCard = page.locator('.draft-card').first();
        const hasLabel = await firstCard.locator('.draft-label').count() > 0;
        const hasDate = await firstCard.locator('small').count() > 0;
        const hasLoadBtn = await firstCard.locator('button:has-text("Carregar")').count() > 0;
        const hasDeleteBtn = await firstCard.locator('button.btn-close, button:has-text("×")').count() > 0;
        
        if (hasLabel && hasDate && hasLoadBtn && hasDeleteBtn) {
            console.log('   ✅ Card com estrutura completa (label, data, botões)');
            results.passed++;
            results.details.push({ test: 'Card estrutura completa', status: 'PASS' });
        } else {
            console.log(`   ❌ Card com estrutura incompleta (label:${hasLabel}, date:${hasDate}, load:${hasLoadBtn}, delete:${hasDeleteBtn})`);
            results.failed++;
            results.details.push({ test: 'Card estrutura completa', status: 'FAIL' });
        }
        
        // Fechar modal
        await page.click('#draftsModal .btn-close, #draftsModal button:has-text("Fechar")');
        await page.waitForTimeout(500);
        
        const modalClosed = await page.locator('#draftsModal.show').count() === 0;
        if (modalClosed) {
            console.log('   ✅ Modal fechou corretamente');
            results.passed++;
            results.details.push({ test: 'Modal fecha', status: 'PASS' });
        } else {
            console.log('   ❌ Modal NÃO fechou');
            results.failed++;
            results.details.push({ test: 'Modal fecha', status: 'FAIL' });
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
        console.log('📊 RESULTADO T3: Modal Meus Rascunhos');
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
