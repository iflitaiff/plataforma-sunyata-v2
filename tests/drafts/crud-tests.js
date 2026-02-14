/**
 * CRUD Tests Runner
 * Executa T4, T5, T6
 */

const { runTest: runT4 } = require('./t4-load');
const { runTest: runT5 } = require('./t5-rename');
const { runTest: runT6 } = require('./t6-delete');

async function runCrudTests() {
    console.log('\n' + '='.repeat(60));
    console.log('🧪 CRUD TESTS - Drafts MVP');
    console.log('='.repeat(60) + '\n');
    
    const allResults = { T4: null, T5: null, T6: null };
    let totalPassed = 0, totalFailed = 0;
    
    try {
        console.log('📋 T4: Load Draft\n');
        allResults.T4 = await runT4();
        totalPassed += allResults.T4.passed;
        totalFailed += allResults.T4.failed;
        await new Promise(r => setTimeout(r, 2000));
        
        console.log('\n📋 T5: Rename Draft\n');
        allResults.T5 = await runT5();
        totalPassed += allResults.T5.passed;
        totalFailed += allResults.T5.failed;
        await new Promise(r => setTimeout(r, 2000));
        
        console.log('\n📋 T6: Delete Draft\n');
        allResults.T6 = await runT6();
        totalPassed += allResults.T6.passed;
        totalFailed += allResults.T6.failed;
    } catch (error) {
        console.error(`\n❌ Error: ${error.message}`);
    }
    
    console.log('\n' + '='.repeat(60));
    console.log('📊 SUMMARY');
    console.log('='.repeat(60));
    console.log(`T4 (Load):   ${allResults.T4.passed}/${allResults.T4.passed + allResults.T4.failed}`);
    console.log(`T5 (Rename): ${allResults.T5.passed}/${allResults.T5.passed + allResults.T5.failed}`);
    console.log(`T6 (Delete): ${allResults.T6.passed}/${allResults.T6.passed + allResults.T6.failed}`);
    console.log(`\n🎯 Total: ${totalPassed}/${totalPassed + totalFailed}`);
    console.log(totalFailed === 0 ? '\n✨ ALL PASS ✨\n' : `\n⚠️  ${totalFailed} failing\n`);
    console.log('='.repeat(60) + '\n');
    
    return { totalPassed, totalFailed, byTest: allResults };
}

if (require.main === module) runCrudTests().then(r => process.exit(r.totalFailed > 0 ? 1 : 0));
module.exports = { runCrudTests };
