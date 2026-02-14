/**
 * Security Tests Runner
 * Executa T7 (Limite 10 drafts) e T8 (IDOR Protection)
 */

const { runTest: runT7 } = require('./t7-limit.js');
const { runTest: runT8 } = require('./t8-idor.js');

async function runAllSecurityTests() {
    console.log('\n╔════════════════════════════════════════════════════════════╗');
    console.log('║     TESTES DE SEGURANÇA - DRAFTS MVP (T7 & T8)            ║');
    console.log('╚════════════════════════════════════════════════════════════╝\n');
    
    const allResults = {};
    
    // T7: Limite de 10 drafts
    console.log('\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n');
    const t7Results = await runT7();
    allResults.t7 = t7Results;
    
    // T8: IDOR Protection
    console.log('\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n');
    const t8Results = await runT8();
    allResults.t8 = t8Results;
    
    // Resumo Final
    console.log('\n╔════════════════════════════════════════════════════════════╗');
    console.log('║                    RESUMO FINAL                           ║');
    console.log('╚════════════════════════════════════════════════════════════╝\n');
    
    const totalPassed = t7Results.passed + t8Results.passed;
    const totalFailed = t7Results.failed + t8Results.failed;
    const totalTests = totalPassed + totalFailed;
    const passRate = totalTests > 0 ? ((totalPassed / totalTests) * 100).toFixed(0) : 0;
    
    console.log(`T7 (Limite 10 Drafts): ${t7Results.passed} ✅ / ${t7Results.failed} ❌`);
    console.log(`T8 (IDOR Protection):  ${t8Results.passed} ✅ / ${t8Results.failed} ❌`);
    console.log(`\nTotal: ${totalPassed}/${totalTests} ✅ (${passRate}%)\n`);
    
    if (totalFailed === 0) {
        console.log('🎉 TODOS OS TESTES DE SEGURANÇA PASSARAM!\n');
    } else {
        console.log(`⚠️  ${totalFailed} teste(s) falharam. Veja detalhes acima.\n`);
    }
    
    // Detalhes
    console.log('📋 Detalhes:\n');
    
    console.log('T7 - Limite de 10 Drafts:');
    t7Results.details.forEach(d => {
        const icon = d.status === 'PASS' ? '✅' : d.status === 'FAIL' ? '❌' : '⚠️';
        console.log(`  ${icon} ${d.test}: ${d.status}`);
        if (d.expected !== undefined) {
            console.log(`     Esperado: ${d.expected}, Recebido: ${d.actual}`);
        }
        if (d.message) {
            console.log(`     Mensagem: ${d.message}`);
        }
    });
    
    console.log('\nT8 - IDOR Protection:');
    t8Results.details.forEach(d => {
        const icon = d.status === 'PASS' ? '✅' : d.status === 'FAIL' ? '❌' : '⚠️';
        console.log(`  ${icon} ${d.test}: ${d.status}`);
        if (d.expected !== undefined) {
            console.log(`     Esperado: ${d.expected}, Recebido: ${d.actual}`);
        }
    });
    
    return allResults;
}

// Executar testes
runAllSecurityTests().catch(error => {
    console.error('❌ Erro na execução dos testes:', error);
    process.exit(1);
});
