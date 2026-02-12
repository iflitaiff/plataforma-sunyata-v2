-- Migration 006: Licitacoes vertical and canvas templates
-- Date: 2026-02-12
-- Description: Creates the licitacoes vertical and 3 initial canvas templates
--   1. licitacoes-resumo-executivo (Resumo Executivo de Edital)
--   2. licitacoes-habilitacao (Análise de Habilitação)
--   3. licitacoes-monitor-pncp (Monitor PNCP)

BEGIN;

-- 1. Create the licitacoes vertical
INSERT INTO verticals (slug, name, config, is_active)
VALUES (
    'licitacoes',
    'Licitações',
    '{"descricao": "Ferramentas para análise de editais, monitoramento de licitações e gestão de processos licitatórios."}',
    TRUE
) ON CONFLICT (slug) DO UPDATE SET
    name = EXCLUDED.name,
    is_active = EXCLUDED.is_active,
    updated_at = NOW();

-- 2. Canvas: Resumo Executivo de Edital
INSERT INTO canvas_templates (
    slug, name, vertical, type, category, icon, display_order,
    form_config, system_prompt, max_questions, is_active, status
) VALUES (
    'licitacoes-resumo-executivo',
    '📄 Resumo Executivo de Edital',
    'licitacoes',
    'forms',
    'analise',
    '📄',
    1,
    '{
        "pages": [{
            "name": "page1",
            "elements": [
                {
                    "name": "intro_banner",
                    "type": "html",
                    "html": "<div class=\"alert alert-info\" style=\"border-left: 4px solid #667eea; background: #f0f4ff;\"><strong>📄 Resumo Executivo de Edital</strong><br>Faça upload do edital completo (PDF) para receber um relatório técnico estruturado com as 11 seções obrigatórias: informações essenciais, resumo executivo, detalhamento do objeto, prazos, habilitação, participação, consórcios, julgamento, valores e legislação.<br><br><em>Formatos aceitos: PDF — Tamanho máximo: 10MB por arquivo</em></div>"
                },
                {
                    "name": "documentos",
                    "type": "file",
                    "title": "Edital e Anexos",
                    "maxSize": 10485760,
                    "isRequired": true,
                    "description": "Faça upload do edital completo e seus anexos. Quanto mais documentos, mais completa será a análise.",
                    "promptLabel": "documentos_prompt",
                    "promptOrder": 0,
                    "acceptedTypes": "application/pdf,.pdf",
                    "allowMultiple": true,
                    "waitForUpload": true,
                    "filePlaceholder": "Clique ou arraste o edital aqui (PDF — máx. 10MB cada)",
                    "storeDataAsText": false,
                    "promptInstruction": "Documentos do edital de licitação fornecidos pelo usuário para análise completa."
                },
                {
                    "name": "foco_analise",
                    "rows": 4,
                    "type": "comment",
                    "title": "Foco da Análise (opcional)",
                    "maxLength": 5000,
                    "description": "Se houver aspectos específicos que deseja priorizar na análise, descreva aqui.",
                    "placeholder": "Ex: Priorizar análise de exigências técnicas e prazos de entrega. Verificar se há restrições para empresas de pequeno porte.",
                    "promptLabel": "foco_analise_prompt",
                    "promptOrder": 1,
                    "promptInstruction": "Foco ou prioridades indicados pelo usuário para a análise do edital."
                },
                {
                    "name": "contexto_empresa",
                    "rows": 3,
                    "type": "comment",
                    "title": "Contexto da Empresa (opcional)",
                    "maxLength": 3000,
                    "description": "Informações sobre a empresa interessada podem ajudar a personalizar pontos de atenção.",
                    "placeholder": "Ex: Empresa de TI, 50 funcionários, faturamento anual R$ 5 milhões, experiência em contratos governamentais de infraestrutura.",
                    "promptLabel": "contexto_empresa_prompt",
                    "promptOrder": 2,
                    "promptInstruction": "Contexto da empresa do usuário para personalizar a análise de viabilidade de participação."
                }
            ]
        }],
        "title": "📄 Resumo Executivo de Edital",
        "locale": "pt-br",
        "showTitle": true,
        "widthMode": "responsive",
        "description": "Análise completa de editais de licitação com relatório técnico estruturado em 11 seções obrigatórias.",
        "completeText": "Analisar Edital",
        "logoPosition": "right",
        "completedHtml": "<div class=''alert alert-success''><strong>Formulário enviado!</strong><br>Processando análise do edital...</div>",
        "progressBarType": "questions",
        "showProgressBar": "top",
        "questionsOnPageMode": "singlePage",
        "showQuestionNumbers": "off"
    }'::jsonb,
    E'PAPEL E MISSÃO\nVocê atuará como analista especializado em editais de licitação no Brasil. Sua missão é realizar a leitura integral do edital e de todos os anexos fornecidos e, com base exclusivamente nesses documentos, elaborar um relatório técnico estruturado nas onze SEÇÕES MÍNIMAS OBRIGATÓRIAS (1 a 11).\n\nREGRAS GERAIS\n- Idioma e tom: Português (Brasil), linguagem formal, clara, objetiva e imparcial.\n- Fontes: Basear a análise exclusivamente nos documentos fornecidos (não usar fontes externas).\n- Abrangência: Leitura integral de todos os documentos fornecidos.\n- Não duplicar: Caso uma exigência seja mencionada em mais de um local, consolidá-la em um único item.\n- Imparcialidade: Não emitir parecer jurídico nem opinião subjetiva.\n\nFORMATAÇÃO E ESTRUTURA DA RESPOSTA\n- Estrutura: Relatório técnico utilizando numeração hierárquica (1., 1.1, 1.1.1) e listas numeradas ou com marcadores.\n- Títulos: Escrever títulos e subtítulos diferenciados por MAIÚSCULAS, negrito e numeração.\n- Localização: Indicar em itálico a fonte exata de cada informação.\n- Datas e horas: Formato DD/MM/AAAA HH:MM (horário de Brasília).\n- Valores: Formato R$ 0.000,00.\n- Sem divagações: Não incluir saudações, comentários pessoais ou conclusões subjetivas.\n\nSEÇÕES MÍNIMAS OBRIGATÓRIAS (NESTA ORDEM, SEM REPETIR INFORMAÇÕES)\n\n1. INFORMAÇÕES ESSENCIAIS – dados do processo\n- Órgão licitante e unidade requisitante.\n- Número da UASG, se aplicável.\n- Número da licitação e número do processo administrativo.\n- Modalidade, forma de disputa e plataforma da sessão pública.\n- Abrangência ou local(is) de execução/entrega.\n\n2. RESUMO EXECUTIVO – visão geral\n- Síntese do objeto.\n- Modalidade, forma de disputa e critério de julgamento.\n- Principais prazos.\n- Visão geral sobre valores.\n- Principais riscos, exigências incomuns e pontos de atenção.\n\n3. DETALHAMENTO DO OBJETO – especificações e quantidades\n- Natureza do objeto e resumo dos itens/lotes.\n- Requisitos técnicos: características, desempenho mínimo, padrões, certificações.\n- SLAs, métricas e penalidades.\n- POCs, amostras, protótipos ou testes.\n- Critérios de aceite.\n- Logística e implantação.\n- Garantia e suporte.\n- Subcontratação.\n- Documentos do fabricante.\n- Propriedade intelectual, confidencialidade, LGPD.\n- Sustentabilidade e aceitabilidade de marcas.\n\n4. PRAZOS E CRONOGRAMA – processo e execução\n- Datas do processo licitatório.\n- Prazos de execução do contrato.\n- Vigência contratual e prorrogações.\n\n5. LOCAL, DATA E HORÁRIO DA LICITAÇÃO\n- Local da sessão pública.\n- Data e horário oficiais de abertura.\n\n6. DOCUMENTAÇÃO DE HABILITAÇÃO – requisitos jurídicos, fiscais, técnicos e econômico-financeiros\n- Habilitação jurídica.\n- Regularidade fiscal e trabalhista.\n- Qualificação técnica.\n- Qualificação econômico-financeira.\n- Declarações exigidas.\n- Exigências dispersas.\n\n7. REGRAS DE PARTICIPAÇÃO – condições para participar\n- Requisitos gerais.\n- Tratamento diferenciado (ME/EPP).\n- Visita técnica.\n\n8. CONSÓRCIOS – participação conjunta\n- Se permitida, vedada ou não mencionada.\n- Requisitos quando permitida.\n\n9. CRITÉRIOS DE JULGAMENTO – classificação das propostas\n- Critério de julgamento.\n- Forma de classificação.\n- Critérios de desempate.\n\n10. VALORES – orçamentos e referências de preço\n- Orçamento estimado.\n- Valores de referência.\n- Forma de cotação.\n- Limites de preços.\n\n11. LEGISLAÇÃO APLICÁVEL – normas citadas\n- Normas legais expressamente mencionadas no edital.',
    5,
    TRUE,
    'published'
) ON CONFLICT (slug) DO UPDATE SET
    name = EXCLUDED.name,
    form_config = EXCLUDED.form_config,
    system_prompt = EXCLUDED.system_prompt,
    category = EXCLUDED.category,
    icon = EXCLUDED.icon,
    display_order = EXCLUDED.display_order,
    updated_at = NOW();

-- 3. Canvas: Análise de Habilitação
INSERT INTO canvas_templates (
    slug, name, vertical, type, category, icon, display_order,
    form_config, system_prompt, max_questions, is_active, status
) VALUES (
    'licitacoes-habilitacao',
    '✅ Análise de Habilitação',
    'licitacoes',
    'forms',
    'analise',
    '✅',
    2,
    '{
        "pages": [{
            "name": "page1",
            "elements": [
                {
                    "name": "intro_banner",
                    "type": "html",
                    "html": "<div class=\"alert alert-info\" style=\"border-left: 4px solid #667eea; background: #f0f4ff;\"><strong>✅ Análise de Habilitação</strong><br>Faça upload do edital para extrair e consolidar TODAS as exigências de habilitação: jurídica, fiscal, técnica, econômico-financeira e declarações. A análise inclui exigências dispersas em anexos e termos de referência.<br><br><em>Formatos aceitos: PDF — Tamanho máximo: 10MB por arquivo</em></div>"
                },
                {
                    "name": "documentos",
                    "type": "file",
                    "title": "Edital e Anexos",
                    "maxSize": 10485760,
                    "isRequired": true,
                    "description": "Inclua o edital completo e todos os anexos relevantes (Termo de Referência, minuta contratual, modelos de declarações).",
                    "promptLabel": "documentos_prompt",
                    "promptOrder": 0,
                    "acceptedTypes": "application/pdf,.pdf",
                    "allowMultiple": true,
                    "waitForUpload": true,
                    "filePlaceholder": "Clique ou arraste o edital aqui (PDF — máx. 10MB cada)",
                    "storeDataAsText": false,
                    "promptInstruction": "Documentos do edital de licitação para análise focada em exigências de habilitação."
                },
                {
                    "name": "perfil_empresa",
                    "rows": 4,
                    "type": "comment",
                    "title": "Perfil da Empresa (opcional)",
                    "maxLength": 5000,
                    "description": "Descreva o perfil da empresa para verificação de compatibilidade com as exigências.",
                    "placeholder": "Ex: Empresa ME, 8 anos no mercado, faturamento R$ 3M/ano, possui ISO 9001, experiência em contratos com órgãos federais, equipe técnica de 20 profissionais.",
                    "promptLabel": "perfil_empresa_prompt",
                    "promptOrder": 1,
                    "promptInstruction": "Perfil da empresa para avaliação de compatibilidade com exigências de habilitação."
                },
                {
                    "name": "observacoes",
                    "rows": 3,
                    "type": "comment",
                    "title": "Observações Adicionais (opcional)",
                    "maxLength": 3000,
                    "description": "Dúvidas específicas ou pontos que deseja investigar sobre habilitação.",
                    "placeholder": "Ex: Verificar se há exigência de visita técnica obrigatória e se a qualificação técnica exige atestados específicos de TI.",
                    "promptLabel": "observacoes_prompt",
                    "promptOrder": 2,
                    "promptInstruction": "Observações ou dúvidas específicas do usuário sobre habilitação."
                }
            ]
        }],
        "title": "✅ Análise de Habilitação",
        "locale": "pt-br",
        "showTitle": true,
        "widthMode": "responsive",
        "description": "Extração e consolidação completa de todas as exigências de habilitação do edital.",
        "completeText": "Analisar Habilitação",
        "logoPosition": "right",
        "completedHtml": "<div class=''alert alert-success''><strong>Formulário enviado!</strong><br>Processando análise de habilitação...</div>",
        "progressBarType": "questions",
        "showProgressBar": "top",
        "questionsOnPageMode": "singlePage",
        "showQuestionNumbers": "off"
    }'::jsonb,
    E'PAPEL E MISSÃO\nVocê atuará como analista especializado em editais de licitação no Brasil. Sua missão é examinar o edital e os anexos fornecidos, identificando e detalhando TODAS as exigências de habilitação (qualificação) presentes em qualquer parte desses documentos.\n\nENTRADAS (FORNECIDAS PELO USUÁRIO)\n- Edital de licitação e anexos pertinentes (fornecidos em PDF).\n\nREGRAS GERAIS\n- Idioma e tom: Português (Brasil), linguagem formal, clara, objetiva e imparcial.\n- Fontes: Basear a análise exclusivamente nos documentos fornecidos (não usar fontes externas).\n- Abrangência: Leitura integral de todos os documentos fornecidos (edital e anexos, incluindo capa, índice, corpo do edital, Termo de Referência, minutas contratuais, modelos, erratas, esclarecimentos etc.).\n- Não duplicar: Caso uma exigência seja mencionada em mais de um local, consolidá-la em um único item, anotando eventuais diferenças de redação ou condições adicionais.\n- Escopo: Relatar somente o que estiver explicitamente previsto nos documentos, sem presumir práticas usuais. Requisitos normalmente esperados, mas ausentes, devem ser registrados como \"não exigidos\".\n- Imparcialidade: Não emitir parecer jurídico nem opinião subjetiva.\n- Organização única: Estruturar todas as informações exclusivamente nas seções definidas abaixo.\n\nFORMATAÇÃO E ESTRUTURA DA RESPOSTA\n- Estrutura: Relatório técnico utilizando numeração hierárquica (1., 1.1, 1.1.1) e listas numeradas ou com marcadores.\n- Títulos: Escrever títulos e subtítulos diferenciados por MAIÚSCULAS, negrito e numeração.\n- Localização: Indicar em itálico a fonte exata de cada informação, no formato [seção/cláusula, pág. PDF X; pág. interna Y].\n- Datas e horas: Formato DD/MM/AAAA HH:MM (horário de Brasília).\n- Valores: Formato R$ 0.000,00.\n- Sem divagações: Não incluir saudações, comentários pessoais ou conclusões subjetivas.\n\nDOCUMENTAÇÃO DE HABILITAÇÃO – requisitos jurídicos, fiscais, técnicos e econômico-financeiros\n- Habilitação jurídica: documentos relativos à constituição da empresa (registro comercial, contrato social/estatuto e alterações, atas, procurações etc.).\n- Regularidade fiscal e trabalhista: certidões negativas ou positivas com efeito de negativa dos tributos federais, estaduais e municipais; comprovantes de regularidade com FGTS e INSS; CNDT; e outras certidões exigidas.\n- Qualificação técnica: atestados de capacidade técnica, certificações de qualidade, registros em conselhos profissionais, comprovação de equipe técnica ou equipamentos, POCs/testes.\n- Qualificação econômico-financeira: balanço patrimonial e demonstrações contábeis, índices financeiros, certidões de falência/recuperação judicial, capital social ou patrimônio líquido mínimos, garantias de proposta.\n- Declarações exigidas: Listar todas as declarações requeridas, resumindo seu teor e indicando a localização exata.\n- Exigências dispersas: Consolidar requisitos de habilitação mencionados fora da seção própria (Termo de Referência, minuta contratual, modelos, anexos técnicos, cláusulas sobre garantias).\n\nRISCOS, AMBIGUIDADES E OBSERVAÇÕES – pontos críticos\n- Exigências incomuns ou potencialmente restritivas/complexas, destacando o local exato.\n- Ambiguidades, contradições internas ou lacunas.\n- Requisitos geralmente esperados que não foram exigidos.\n- Pontos que poderiam motivar pedidos de esclarecimento ou impugnação.',
    5,
    TRUE,
    'published'
) ON CONFLICT (slug) DO UPDATE SET
    name = EXCLUDED.name,
    form_config = EXCLUDED.form_config,
    system_prompt = EXCLUDED.system_prompt,
    category = EXCLUDED.category,
    icon = EXCLUDED.icon,
    display_order = EXCLUDED.display_order,
    updated_at = NOW();

-- 4. Canvas: Monitor PNCP (page type - custom search interface)
INSERT INTO canvas_templates (
    slug, name, vertical, type, category, icon, display_order,
    page_url, description, form_config, max_questions, is_active, status
) VALUES (
    'licitacoes-monitor-pncp',
    '🔍 Monitor PNCP',
    'licitacoes',
    'page',
    'monitoramento',
    '🔍',
    3,
    '/areas/licitacoes/monitor-pncp.php',
    'Busca e monitoramento de licitações no Portal Nacional de Contratações Públicas (PNCP).',
    NULL,
    5,
    TRUE,
    'published'
) ON CONFLICT (slug) DO UPDATE SET
    name = EXCLUDED.name,
    type = EXCLUDED.type,
    page_url = EXCLUDED.page_url,
    description = EXCLUDED.description,
    category = EXCLUDED.category,
    icon = EXCLUDED.icon,
    display_order = EXCLUDED.display_order,
    updated_at = NOW();

COMMIT;
