# Integração DataJud (CNJ) — Proposta para o Jurídico (IATR)

**Data:** 25/02/2026
**Preparado por:** Equipe Sunyata
**Para:** Andrea e equipe jurídica

---

## Resumo

A Plataforma Sunyata possui integração com a **API pública do DataJud (CNJ)**, que consolida dados processuais de todos os tribunais do Brasil. A vertical IATR (Inteligência e Análise para Tomada de Risco) foi construída pensando na equipe jurídica, e o DataJud adiciona uma camada de **inteligência judicial baseada em dados reais** ao trabalho do escritório.

Esta proposta descreve como a equipe jurídica pode usar essa integração nas diversas frentes de atuação — trabalhista, imobiliário, empresarial e suporte a licitações.

---

## Funcionalidades propostas

### 1. Panorama Judicial por Jurisdição

Para qualquer região onde a holding atue ou pretenda atuar, a plataforma pode gerar um **relatório automático** com:

- Volume de processos por classe (trabalhista, fiscal, falência, posse, etc.)
- Distribuição por tribunal (TJ, TRF, TRT, STJ/TST)
- Tendência temporal — a litigiosidade está aumentando ou diminuindo?
- Comparativo entre jurisdições — ex: TRT-2 (SP) vs. TRT-1 (RJ)

**Cenário de uso:** A holding está expandindo operações para o Nordeste. O jurídico consulta a plataforma para ter um panorama dos tribunais da região antes de definir estratégia jurídica local.

### 2. Inteligência Trabalhista

O DataJud permite consultar todos os TRTs do país. A plataforma pode fornecer:

- Volume de **reclamações trabalhistas** por região e período
- Distribuição por varas do trabalho — quais concentram mais processos
- Tendência: regiões com crescimento acelerado de litígios trabalhistas
- Comparativo entre TRTs — referência para avaliação de risco

**Cenário de uso:** A empresa está contratando equipe em Minas Gerais. O jurídico consulta o panorama do TRT-3 para entender o volume e perfil de reclamações trabalhistas na região, ajudando a calibrar contratos e políticas de compliance.

### 3. Análise Imobiliária e Possessória

Para operações envolvendo imóveis, a plataforma pode levantar:

- Volume de **ações possessórias** (reintegração, manutenção de posse) na jurisdição
- Presença de **usucapião** e **desapropriações** — sinaliza disputas fundiárias na região
- **Ações de despejo** — indica contexto do mercado locatício
- Panorama por comarca — quais varas concentram litígios imobiliários

**Cenário de uso:** A holding está avaliando a aquisição de um imóvel comercial em Curitiba. O jurídico consulta o panorama de ações possessórias e usucapião na comarca para avaliar riscos fundiários na região.

### 4. Inteligência Empresarial

No contexto de operações societárias e relações comerciais:

- Volume de **falências** e **recuperações judiciais** por jurisdição — sinaliza ambiente econômico
- **Execuções de título extrajudicial** — indica nível de inadimplência na região
- **Dissoluções de sociedade** — contexto para operações de M&A ou parcerias
- Tendência temporal — a região está em crise ou recuperação?

**Cenário de uso:** A holding está negociando parceria com um fornecedor de Manaus. O jurídico consulta o panorama de falências e recuperações judiciais no TJAM para avaliar a saúde do ambiente empresarial local.

### 5. Suporte a Licitações (integração com equipe do Maurício)

Quando a equipe de licitações identifica um edital, o jurídico pode complementar a análise com dados judiciais:

- **Risco jurisdicional:** volume de execuções fiscais contra órgãos públicos na região (indica inadimplência do poder público)
- **Habilitação:** panorama de classes que afetam habilitação (falência, recuperação judicial, execução fiscal)
- **Contraparte:** histórico de litigiosidade na jurisdição do órgão licitante

**Cenário de uso:** O Maurício identifica uma licitação em Belém. O jurídico consulta os dados do TJPA e TRF-1 para emitir parecer de risco jurisdicional. A decisão go/no-go é tomada com dados do edital (equipe Maurício) + contexto judicial (equipe Andrea).

### 6. Inteligência para Contencioso

Para qualquer litígio em andamento, a plataforma pode fornecer contexto comparativo:

- Panorama de processos da **mesma classe processual** na mesma jurisdição
- Distribuição por órgão julgador — quais varas/câmaras concentram processos do tipo
- Dados sobre movimentação recente — ritmo de tramitação no tribunal

**Cenário de uso:** A empresa está em execução fiscal com a Receita Federal. O jurídico consulta os processos de mesma classe no TRF da região para entender prazos e padrões, fundamentando a estratégia processual.

---

## O que a plataforma consulta

| Dado | O que significa |
|------|----------------|
| Classe processual | Tipo do processo: falência, reclamação trabalhista, ação possessória, etc. |
| Tribunal | Estadual (TJ), Federal (TRF), Trabalhista (TRT), Superior (STJ/TST) |
| Órgão julgador | Vara ou câmara específica |
| Data de ajuizamento | Quando o processo foi iniciado |
| Assuntos | Temas: dívida ativa, ICMS, contrato de trabalho, posse, etc. |
| Última movimentação | Ação mais recente no processo |
| Grau | 1ª instância, recurso, tribunais superiores |

**Cobertura:** Todos os 27 estados, 5 TRFs, todos os TRTs, STJ e TST.

---

## Classes processuais por área de atuação

### Trabalhista
| Classe | Relevância |
|--------|------------|
| **Reclamação Trabalhista** | Tipo mais comum no TRT. Volume indica litigiosidade na região. |
| **Ação Civil Pública Trabalhista** | Indica atuação do MPT. Sinaliza problemas setoriais. |

### Imobiliário
| Classe | Relevância |
|--------|------------|
| **Reintegração de Posse** | Disputas possessórias ativas. Risco para aquisições. |
| **Usucapião** | Disputas fundiárias. Indica regularização pendente na região. |
| **Ação de Despejo** | Contexto do mercado locatício. |
| **Desapropriação** | Ação do poder público. Afeta investimentos imobiliários. |

### Empresarial
| Classe | Relevância |
|--------|------------|
| **Falência** (1037) | Sinal crítico sobre ambiente econômico e contrapartes. |
| **Recuperação Judicial** (1049) | Indica stress financeiro no setor/região. |
| **Execução de Título Extrajudicial** (12135) | Nível de inadimplência. |
| **Dissolução de Sociedade** | Contexto para operações societárias. |

### Licitações e Contratos Públicos
| Classe | Relevância |
|--------|------------|
| **Execução Fiscal** (1116/1117) | Dívidas com o fisco. Afeta habilitação. |
| **Ação Civil Pública** (65) | Judicialização de contratos públicos. |
| **Mandado de Segurança** | Impugnações a atos administrativos. |

---

## Limitação atual

A API pública do DataJud fornece dados por **tribunal, classe processual e período**. Ela **não permite buscar processos pelo CNPJ ou nome de uma das partes**.

Na prática:
- **Conseguimos:** "Qual o volume de reclamações trabalhistas no TRT-2 no último ano?"
- **Ainda não conseguimos:** "A empresa XPTO Ltda é ré em algum processo trabalhista?"

Todas as funcionalidades propostas foram desenhadas para funcionar dentro dessa limitação. Estamos mapeando APIs complementares (PJe, CNDJ, BNMP) que permitiriam consulta por CNPJ no futuro.

---

## Visão de futuro: integração entre verticais

Hoje, a equipe jurídica usa a vertical **IATR** e a equipe de licitações usa a vertical **Licitações**. No roadmap da plataforma, estamos planejando:

- **Integração entre verticais** — possibilidade de ferramentas de uma vertical alimentarem a outra
- **Canvas encadeados** — o resultado de uma análise (ex: panorama judicial) pode servir de input para outra ferramenta (ex: parecer de risco para licitação)
- **Orquestração** — workflows automatizados que conectam as análises das duas equipes

Isso permitiria, por exemplo, que quando o Maurício solicitar análise de um edital, o sistema automaticamente gere o contexto judicial para a Andrea revisar — tudo dentro da mesma plataforma.

---

## Próximo passo

Gostaríamos de agendar uma conversa com a Andrea para:

1. Entender quais áreas (trabalhista, imobiliário, empresarial) são prioritárias no dia-a-dia
2. Identificar as jurisdições mais relevantes para a holding
3. Definir qual funcionalidade implementar primeiro na vertical IATR
4. Mapear como o jurídico e a equipe de licitações interagem hoje para otimizar o fluxo na plataforma
