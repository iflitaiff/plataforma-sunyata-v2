<?php
/**
 * Configuração Centralizada de Verticais
 *
 * Define todas as verticais disponíveis no sistema, seus metadados e comportamentos.
 * Este é o único lugar onde as verticais devem ser definidas.
 *
 * @package Sunyata
 * @since 2025-10-20
 */

return [
    'docencia' => [
        'nome' => 'Docência',
        'icone' => '👨‍🏫',
        'descricao' => 'Ferramentas para planejamento de aulas, criação de conteúdo educacional e gestão pedagógica.',
        'ferramentas' => [
            'Canvas Docente',
            'Canvas Pesquisa',
            'Biblioteca de Prompts (Jogos)',
            'Guia de Prompts (Jogos)',
            'Repositório de Prompts'
        ],
        'disponivel' => true,
        'requer_info_extra' => false,
        'requer_aprovacao' => false,
        'ordem' => 1
    ],

    'pesquisa' => [
        'nome' => 'Pesquisa',
        'icone' => '🔬',
        'descricao' => 'Recursos para estruturação de projetos de pesquisa acadêmica e científica.',
        'ferramentas' => [
            'Canvas Docente',
            'Canvas Pesquisa',
            'Repositório de Prompts'
        ],
        'disponivel' => true,
        'requer_info_extra' => false,
        'requer_aprovacao' => false,
        'ordem' => 2
    ],

    'ifrj_alunos' => [
        'nome' => 'IFRJ - Alunos',
        'icone' => '🎓',
        'descricao' => 'Área exclusiva para alunos do IFRJ com ferramentas de apoio ao aprendizado.',
        'ferramentas' => [
            'Biblioteca de Prompts (Jogos)',
            'Guia de Prompts (Jogos)',
            'Canvas Pesquisa',
            'Repositório de Prompts'
        ],
        'disponivel' => true,
        'requer_info_extra' => true, // Precisa de nível e curso
        'requer_aprovacao' => false,
        'form_extra' => 'onboarding-ifrj.php',
        'ordem' => 3
    ],

    'juridico' => [
        'nome' => 'Jurídico',
        'icone' => '⚖️',
        'descricao' => 'Ferramentas especializadas para profissionais do Direito.',
        'descricao_aprovacao' => ' <strong>Requer aprovação.</strong>',
        'ferramentas' => [
            'Canvas Jurídico',
            'Guia de Prompts (Jurídico)',
            'Padrões Avançados (Jurídico)',
            'Repositório de Prompts'
        ],
        'disponivel' => true,
        'requer_info_extra' => false,
        'requer_aprovacao_setting' => 'juridico_requires_approval', // Dinâmico via Settings!
        'form_aprovacao' => 'onboarding-juridico.php',
        'ordem' => 4,
        // Parâmetros da API Claude (defaults que podem ser sobrescritos via admin UI)
        'api_params' => [
            'system_prompt' => 'Você é um assistente jurídico especializado em direito corporativo brasileiro. Sua função é auxiliar na análise de documentos jurídicos, contratos, e questões legais, fornecendo informações estruturadas e sugestões baseadas na legislação vigente. IMPORTANTE: Sempre ressalte que suas respostas não constituem consultoria jurídica formal.',
            'claude_model' => 'claude-sonnet-4-5',
            'temperature' => 0.3,
            'max_tokens' => 4000,
            'top_p' => 0.95,
        ]
    ],

    'vendas' => [
        'nome' => 'Vendas',
        'icone' => '📈',
        'descricao' => 'Ferramentas para otimizar processos de vendas e relacionamento com clientes.',
        'ferramentas' => [],
        'disponivel' => false,
        'requer_info_extra' => false,
        'requer_aprovacao' => false,
        'ordem' => 5
    ],

    'marketing' => [
        'nome' => 'Marketing',
        'icone' => '📢',
        'descricao' => 'Recursos para criação de conteúdo, campanhas e estratégias de marketing digital.',
        'ferramentas' => [],
        'disponivel' => false,
        'requer_info_extra' => false,
        'requer_aprovacao' => false,
        'ordem' => 6
    ],

    'licitacoes' => [
        'nome' => 'Licitações',
        'icone' => '📋',
        'descricao' => 'Ferramentas para análise de editais, monitoramento de licitações e gestão de processos licitatórios.',
        'ferramentas' => [
            'Resumo Executivo de Edital',
            'Análise de Habilitação',
            'Monitor PNCP'
        ],
        'disponivel' => true,
        'requer_info_extra' => false,
        'requer_aprovacao' => false,
        'ordem' => 7,
        'api_params' => [
            'system_prompt' => 'Você é um analista especializado em editais de licitação no Brasil. Sua missão é realizar a leitura integral de editais e anexos fornecidos e elaborar relatórios técnicos estruturados com base exclusivamente nos documentos fornecidos. Use linguagem formal, clara, objetiva e imparcial. Não emita parecer jurídico nem opinião subjetiva.',
            'claude_model' => 'claude-sonnet-4-5',
            'temperature' => 0.2,
            'max_tokens' => 16000,
        ]
    ],

    'rh' => [
        'nome' => 'Recursos Humanos',
        'icone' => '👥',
        'descricao' => 'Soluções para recrutamento, seleção e gestão de pessoas.',
        'ferramentas' => [],
        'disponivel' => false,
        'requer_info_extra' => false,
        'requer_aprovacao' => false,
        'ordem' => 8
    ],

    'geral' => [
        'nome' => 'Geral',
        'icone' => '🌐',
        'descricao' => 'Ferramentas de propósito geral para diversas áreas e aplicações.',
        'ferramentas' => [],
        'disponivel' => false,
        'requer_info_extra' => false,
        'requer_aprovacao' => false,
        'ordem' => 9
    ],

    'iatr' => [
        'nome' => 'IATR',
        'icone' => '🧪',
        'descricao' => 'Vertical de testes para escritório de advocacia. Acesso limitado a 5 usuários.',
        'descricao_aprovacao' => ' <strong>Vagas limitadas.</strong>',
        'ferramentas' => [
            'Canvas Jurídico v2',
            'Resumo Executivo de Edital',
            'Análise de Habilitação',
            'Monitor PNCP'
        ],
        'disponivel' => true,
        'requer_info_extra' => false, // Acesso direto sem formulário extra
        'requer_aprovacao' => false,
        'max_users' => 5, // Limite máximo de usuários
        'ordem' => 10,
        // Parâmetros da API Claude (defaults que podem ser sobrescritos via admin UI)
        'api_params' => [
            'system_prompt' => 'Você é um especialista em análise de riscos e IATR (Identificação e Análise de Temas de Risco). Sua função é auxiliar na identificação, categorização e análise de riscos em processos corporativos, fornecendo insights estruturados e recomendações baseadas em boas práticas de gestão de riscos.',
            'claude_model' => 'claude-sonnet-4-5',
            'temperature' => 0.3,
            'max_tokens' => 4000,
            'top_p' => 0.95,
        ]
    ],

    'prompt-builder' => [
        'nome' => 'Administrativo',
        'icone' => '🛠️',
        'descricao' => 'Ferramentas administrativas para construção e otimização de prompts.',
        'ferramentas' => [
            'gpbV6 - Framework de Delimitação',
            'Treinamento EP - Engenharia de Prompts'
        ],
        'disponivel' => true,
        'requer_info_extra' => false,
        'requer_aprovacao' => false,
        'ordem' => 11,
    ],

    'nicolay-advogados' => [
        'nome' => 'Nicolay Advogados',
        'icone' => '⚖️',
        'descricao' => 'Ferramentas de análise jurídica com IA para Nicolay Advogados.',
        'ferramentas' => [
            'Canvas Jurídico'
        ],
        'disponivel' => true,
        'requer_info_extra' => false,
        'requer_aprovacao' => false, // Future: requer_aprovacao_setting
        'ordem' => 12,
        'api_params' => [
            'system_prompt' => 'Você é um assistente jurídico especializado em direito corporativo brasileiro. Sua função é auxiliar na análise de documentos jurídicos, contratos, e questões legais, fornecendo informações estruturadas e sugestões baseadas na legislação vigente. IMPORTANTE: Sempre ressalte que suas respostas não constituem consultoria jurídica formal.',
            'claude_model' => 'claude-sonnet-4-5',
            'temperature' => 0.3,
            'max_tokens' => 4000,
            'top_p' => 0.95,
        ]
    ],

    'legal' => [
        'nome' => 'Legal',
        'icone' => '⚖️',
        'descricao' => 'Ferramentas de análise jurídica com IA para profissionais do Direito.',
        'ferramentas' => [
            'Canvas Jurídico'
        ],
        'disponivel' => true,
        'requer_info_extra' => false,
        'requer_aprovacao' => false, // Future: requer_aprovacao_setting
        'ordem' => 13,
        'api_params' => [
            'system_prompt' => 'Você é um assistente jurídico especializado em direito corporativo brasileiro. Sua função é auxiliar na análise de documentos jurídicos, contratos, e questões legais, fornecendo informações estruturadas e sugestões baseadas na legislação vigente. IMPORTANTE: Sempre ressalte que suas respostas não constituem consultoria jurídica formal.',
            'claude_model' => 'claude-sonnet-4-5',
            'temperature' => 0.3,
            'max_tokens' => 4000,
            'top_p' => 0.95,
        ]
    ]
];
