/**
 * SurveyJS Commercial License Activation
 *
 * Este arquivo ativa a licença comercial do SurveyJS para remover
 * banners de trial e desbloquear features completas do Survey Creator.
 *
 * Licença válida até: 27/10/2026
 * Produtos incluídos: Survey Creator, Dashboard, PDF Export
 *
 * Documentação: https://surveyjs.io/remove-alert-banner
 * Suporte: https://surveyjs.answerdesk.io/
 */

(function() {
    'use strict';

    // Aguardar SurveyJS carregar
    function activateLicense() {
        // Chave de licença comercial
        const licenseKey = "NTkyZDU2MjctOWY1Yi00M2RlLWJmMDAtZDExMjUzNzc5YmQzOzE9MjAyNi0xMC0yNw==";

        // Verificar se Survey está disponível
        if (typeof Survey !== 'undefined') {
            // Método moderno (v1.9.115+)
            if (typeof Survey.setLicenseKey === 'function') {
                Survey.setLicenseKey(licenseKey);
                console.info('✅ SurveyJS commercial license activated (Survey Library)');
            }

            // Também aplicar para SurveyCreator se estiver carregado
            if (typeof SurveyCreator !== 'undefined' && typeof SurveyCreator.setLicenseKey === 'function') {
                SurveyCreator.setLicenseKey(licenseKey);
                console.info('✅ SurveyJS commercial license activated (Survey Creator)');
            }
        } else {
            console.warn('⚠️  Survey library not loaded yet. License will be applied when available.');

            // Tentar novamente após um curto delay
            setTimeout(activateLicense, 100);
        }
    }

    // Executar quando DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', activateLicense);
    } else {
        activateLicense();
    }
})();
