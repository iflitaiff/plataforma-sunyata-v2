/**
 * Helper functions para testes de Drafts MVP
 */

const BASE_URL = 'http://158.69.25.114';
const CREDENTIALS_ADMIN = { email: 'admin@sunyataconsulting.com', password: 'password' };
const CREDENTIALS_TEST = { email: 'test@test.com', password: 'Test1234!' };

/**
 * Login e navegação para formulário
 */
async function loginAndOpenForm(page, credentials, verticalPath, templateSlug) {
    // Ir para login
    await page.goto(`${BASE_URL}/auth/login`);
    await page.waitForLoadState('networkidle');
    
    // Clicar em "Entrar com Email"
    await page.click('a:has-text("Entrar com Email")');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
    
    // Fazer login
    await page.fill('#login-email', credentials.email);
    await page.fill('#login-password', credentials.password);
    await page.click('button[type="submit"]:has-text("Entrar")');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
    
    // Navegar para formulário
    await page.goto(`${BASE_URL}/areas/${verticalPath}/formulario.php?template=${templateSlug}`);
    await page.waitForLoadState('networkidle');
    
    // Aguardar SurveyJS carregar
    await page.waitForSelector('#surveyContainer', { timeout: 10000 });
    await page.waitForTimeout(2000); // DraftManager init
}

/**
 * Capturar CSRF token da página (via regex no HTML)
 */
async function getCsrfToken(page) {
    const html = await page.content();
    const match = html.match(/csrf[^<>]*["']([a-zA-Z0-9\/\+\-_.=]+)["']/i);
    return match ? match[1] : null;
}

/**
 * Obter template_id inteiro (canvas_template_id) da página
 * Tenta encontrar em window.survey ou usa slug mapping
 */
async function getCanvasTemplateId(page) {
    // Se estiver disponível em window.survey após JS load
    const fromSurvey = await page.evaluate(async () => {
        if (window.survey && window.survey.data && window.survey.data.canvas_template_id) {
            return window.survey.data.canvas_template_id;
        }
        return null;
    });
    
    if (fromSurvey) {
        return fromSurvey;
    }
    
    // Fallback para template slug da URL
    const slug = await page.evaluate(() => {
        const params = new URLSearchParams(window.location.search);
        return params.get('template');
    });
    
    // Mapeamento de slugs para IDs (conhecido pela API inspection)
    const TEMPLATE_SLUG_MAP = {
        'iatr-geral-manus-test': 3
    };
    
    if (TEMPLATE_SLUG_MAP[slug]) {
        return TEMPLATE_SLUG_MAP[slug];
    }
    
    return null;
}

/**
 * Limpar todos os drafts de teste via API
 */
async function cleanupDrafts(page, templateId) {
    await page.evaluate(async (tplId) => {
        const response = await fetch(`/api/drafts/list.php?template_id=${tplId}`);
        if (response.ok) {
            const data = await response.json();
            if (data.drafts && data.drafts.length > 0) {
                for (const draft of data.drafts) {
                    // Get CSRF token
                    const html = await fetch('/').then(r => r.text());
                    const match = html.match(/csrf[^<>]*["']([a-zA-Z0-9\/\+\-_.=]+)["']/i);
                    const csrf = match ? match[1] : '';
                    
                    await fetch('/api/drafts/delete.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        body: JSON.stringify({ draft_id: draft.id })
                    });
                }
            }
        }
    }, templateId);
}

/**
 * Preencher campo do SurveyJS
 */
async function fillSurveyField(page, fieldName, value) {
    await page.evaluate(({ name, val }) => {
        if (window.survey) {
            window.survey.setValue(name, val);
        }
    }, { name: fieldName, val: value });
}

module.exports = {
    BASE_URL,
    CREDENTIALS_ADMIN,
    CREDENTIALS_TEST,
    loginAndOpenForm,
    getCsrfToken,
    getCanvasTemplateId,
    cleanupDrafts,
    fillSurveyField
};
