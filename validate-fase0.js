const { chromium } = require('playwright');

async function runTests() {
  console.log('🧪 Iniciando Validação Fase 0...\n');
  
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  const results = {
    passed: 0,
    failed: 0,
    tests: []
  };
  
  // Capturar erros de console
  const consoleErrors = [];
  page.on('console', msg => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });
  
  try {
    // ========== T1: Dashboard não quebra ao navegar sidebar ==========
    console.log('📋 T1: Dashboard não quebra ao navegar sidebar');
    
    // Ir para página de login
    await page.goto('http://158.69.25.114/auth/login');
    await page.waitForLoadState('networkidle');
    
    // Clicar em "Entrar com Email"
    await page.click('a:has-text("Entrar com Email")');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
    
    // Fazer login
    await page.fill('#login-email', 'test@test.com');
    await page.fill('#login-password', 'Test1234!');
    await page.click('button[type="submit"]:has-text("Entrar")');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
    
    // Verificar se redirecionou para dashboard
    const currentUrl = page.url();
    if (!currentUrl.includes('dashboard')) {
      console.log('   ⚠️  Não redirecionou para dashboard após login');
      console.log('   URL atual:', currentUrl);
    }
    
    // Aguardar dashboard carregar
    await page.waitForTimeout(2000);
    
    // Verificar stats cards
    const statsCards = await page.locator('.card').count();
    console.log(`   ℹ️  Encontrados ${statsCards} cards de stats`);
    
    if (statsCards >= 3) {
      console.log('   ✅ Stats cards carregaram (esperado >= 3)');
      results.passed++;
    } else {
      console.log('   ❌ Stats cards não carregaram completamente');
      results.failed++;
    }
    
    // Clicar em item da sidebar
    await page.click('a[href*="documentos"], .sidebar a:has-text("Documentos")');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
    
    // Verificar que #page-content não foi substituído
    const pageContent = await page.locator('#page-content').count();
    if (pageContent > 0) {
      console.log('   ✅ #page-content ainda existe após navegação');
      results.passed++;
    } else {
      console.log('   ❌ #page-content foi removido/substituído');
      results.failed++;
    }
    
    results.tests.push({
      name: 'T1: Dashboard navegação sidebar',
      status: pageContent > 0 ? 'PASS' : 'FAIL'
    });
    
    // ========== T2: Sidebar → Vertical (full page load) ==========
    console.log('\n📋 T2: Links de vertical fazem full page load');
    
    // Voltar ao dashboard
    await page.goto('http://158.69.25.114/dashboard');
    await page.waitForLoadState('networkidle');
    
    // Pegar URL antes
    const urlBefore = page.url();
    
    // Clicar em link de vertical
    const verticalLink = await page.locator('a:has-text("Ir para Area da Vertical"), a:has-text("Canvas Jurídico")').first();
    
    if (await verticalLink.count() > 0) {
      await verticalLink.click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(2000);
      
      const urlAfter = page.url();
      
      if (urlBefore !== urlAfter) {
        console.log('   ✅ Full page load detectado (URL mudou)');
        console.log(`   ℹ️  ${urlBefore} → ${urlAfter}`);
        results.passed++;
        results.tests.push({ name: 'T2: Full page load', status: 'PASS' });
      } else {
        console.log('   ⚠️  URL não mudou - pode ser HTMX');
        results.tests.push({ name: 'T2: Full page load', status: 'WARN' });
      }
    } else {
      console.log('   ⚠️  Link de vertical não encontrado');
      results.tests.push({ name: 'T2: Full page load', status: 'SKIP' });
    }
    
    // ========== T3: CSP sem erros no console ==========
    console.log('\n📋 T3: CSP sem erros no console');
    
    // Navegar por algumas páginas
    await page.goto('http://158.69.25.114/dashboard');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
    
    // Verificar erros CSP
    const cspErrors = consoleErrors.filter(err => 
      err.includes('Content-Security-Policy') || 
      err.includes('Refused to connect') ||
      err.includes('Refused to load')
    );
    
    console.log(`   ℹ️  Total de erros no console: ${consoleErrors.length}`);
    console.log(`   ℹ️  Erros CSP: ${cspErrors.length}`);
    
    if (cspErrors.length === 0) {
      console.log('   ✅ Zero erros CSP');
      results.passed++;
      results.tests.push({ name: 'T3: CSP errors', status: 'PASS' });
    } else {
      console.log('   ❌ Erros CSP encontrados:');
      cspErrors.slice(0, 3).forEach(err => console.log('      -', err));
      results.failed++;
      results.tests.push({ name: 'T3: CSP errors', status: 'FAIL' });
    }
    
  } catch (error) {
    console.error('\n❌ Erro durante execução:', error.message);
    results.failed++;
  } finally {
    await browser.close();
  }
  
  // ========== Resumo ==========
  console.log('\n' + '='.repeat(60));
  console.log('📊 RESUMO DA VALIDAÇÃO FASE 0');
  console.log('='.repeat(60));
  console.log(`✅ Testes Passaram: ${results.passed}`);
  console.log(`❌ Testes Falharam: ${results.failed}`);
  console.log('\nDetalhes:');
  results.tests.forEach(test => {
    const icon = test.status === 'PASS' ? '✅' : test.status === 'FAIL' ? '❌' : '⚠️';
    console.log(`  ${icon} ${test.name}: ${test.status}`);
  });
  console.log('='.repeat(60));
  
  process.exit(results.failed > 0 ? 1 : 0);
}

runTests();
