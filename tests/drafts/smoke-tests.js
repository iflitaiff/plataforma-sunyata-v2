/**
 * Smoke Tests Runner - Drafts MVP
 * Executa T1, T2, T3 em sequência
 */

const t1 = require('./t1-ui-elements');
const t2 = require('./t2-save-draft');
const t3 = require('./t3-modal');

async function runSmokeTests() {
    console.log('🔥 INICIANDO SMOKE TESTS - DRAFTS MVP');
    console.log('============================================================\n');
    
    const allResults = {
        total: 0,
        passed: 0,
        failed: 0,
        tests: []
    };
    
    // T1
    console.log('─────────────────────────────────────────────────────────────');
    const r1 = await t1.runTest();
    allResults.tests.push({ name: 'T1: UI Elements', ...r1 });
    allResults.passed += r1.passed;
    allResults.failed += r1.failed;
    allResults.total += (r1.passed + r1.failed);
    
    console.log('\n');
    
    // T2
    console.log('─────────────────────────────────────────────────────────────');
    const r2 = await t2.runTest();
    allResults.tests.push({ name: 'T2: Save Draft', ...r2 });
    allResults.passed += r2.passed;
    allResults.failed += r2.failed;
    allResults.total += (r2.passed + r2.failed);
    
    console.log('\n');
    
    // T3
    console.log('─────────────────────────────────────────────────────────────');
    const r3 = await t3.runTest();
    allResults.tests.push({ name: 'T3: Modal', ...r3 });
    allResults.passed += r3.passed;
    allResults.failed += r3.failed;
    allResults.total += (r3.passed + r3.failed);
    
    // Relatório consolidado
    console.log('\n');
    console.log('============================================================');
    console.log('📊 RELATÓRIO CONSOLIDADO - SMOKE TESTS');
    console.log('============================================================');
    console.log(`Total de Asserções: ${allResults.total}`);
    console.log(`✅ Passou: ${allResults.passed} (${Math.round(allResults.passed/allResults.total*100)}%)`);
    console.log(`❌ Falhou: ${allResults.failed} (${Math.round(allResults.failed/allResults.total*100)}%)`);
    console.log('');
    console.log('Resumo por Teste:');
    allResults.tests.forEach(t => {
        const status = t.failed === 0 ? '✅ PASS' : '❌ FAIL';
        console.log(`  ${status} - ${t.name}: ${t.passed}/${t.passed + t.failed} asserções`);
    });
    console.log('============================================================');
    
    // Status final
    if (allResults.failed === 0) {
        console.log('\n🎉 SMOKE TESTS PASSARAM! MVP Drafts está funcional.\n');
    } else {
        console.log('\n⚠️  SMOKE TESTS FALHARAM! Verificar problemas acima.\n');
    }
    
    return allResults;
}

// Executar
if (require.main === module) {
    runSmokeTests().then(results => {
        process.exit(results.failed > 0 ? 1 : 0);
    }).catch(error => {
        console.error('Erro fatal:', error);
        process.exit(1);
    });
}

module.exports = { runSmokeTests };
