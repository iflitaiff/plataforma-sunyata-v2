# Integração DataJud (CNJ) — Proposta para o Jurídico (IATR)

**Data:** 25/02/2026 (rev. 2)
**Preparado por:** Equipe Sunyata
**Para:** Andrea e equipe jurídica

---

## Resumo

A Plataforma Sunyata possui integração com a **API pública do DataJud (CNJ)**, que consolida dados processuais de todos os tribunais do Brasil. A vertical IATR (Inteligência e Análise para Tomada de Risco) foi construída pensando na equipe jurídica, e o DataJud adiciona uma camada de **inteligência judicial baseada em dados reais** ao trabalho do escritório.

Esta proposta descreve como a equipe jurídica pode usar essa integração nas diversas frentes de atuação — trabalhista, imobiliário, empresarial e suporte a licitações.

---

## Escopo e limitações da integração atual

A API pública do DataJud fornece dados por **tribunal, classe processual e período**. Os dados são agregados por tribunal (ex: TJRJ, TRT-1, TRF-2), não por comarca ou município.

**O que a plataforma consegue hoje:**
- "Qual o volume de reclamações trabalhistas no TRT-2 no último ano?"
- "Falências no TJSP estão crescendo ou diminuindo?"
- "Quantas execuções fiscais foram distribuídas no TRF-1 nos últimos 6 meses?"

**O que a plataforma ainda não consegue:**
- "A empresa XPTO Ltda é ré em algum processo trabalhista?"
- "O órgão licitante tem histórico de inadimplência?"

A API não indexa CNPJ/CPF das partes processuais. Todas as funcionalidades abaixo foram desenhadas para funcionar dentro dessa limitação. Estamos mapeando APIs complementares (PJe, CNDJ, BNMP) que permitiriam consulta por parte no futuro.

---

## O que a plataforma consulta

| Dado | O que significa | Tipo de consulta |
|------|----------------|------------------|
| Classe processual | Tipo do processo: falência, reclamação trabalhista, ação possessória, etc. | Filtro de busca |
| Tribunal | Estadual (TJ), Federal (TRF), Trabalhista (TRT), Superior (STJ/TST) | Filtro de busca |
| Período | Range de datas de distribuição | Filtro de busca |
| Assuntos CNJ | Temas padronizados: dívida ativa, ICMS, contrato de trabalho, etc. | Filtro de busca |
| Total de processos | Contagem real retornada pelo ElasticSearch do CNJ | Dado agregado |
| Processos individuais | Metadados: órgão julgador, data, grau, última movimentação | Amostra (paginada) |

**Cobertura:** Todos os 27 estados, 5 TRFs, todos os TRTs, STJ e TST. Custo zero (API pública).

---

## Disponível agora (v1)

### 1. Panorama Judicial por Tribunal/UF

Para qualquer UF onde a holding atue ou pretenda atuar, a plataforma pode gerar um **relatório automático** com:

- Volume de processos por classe processual no(s) tribunal(is) da UF
- Distribuição por tribunal (TJ, TRF, TRT)
- Tendência temporal — a litigiosidade está aumentando ou diminuindo?
- Comparativo entre tribunais — ex: TRT-2 (SP) vs. TRT-1 (RJ)

**Cenário de uso:** A holding está expandindo operações para o Nordeste. O jurídico consulta a plataforma para ter um panorama dos tribunais da região (TJBA, TJPE, TJCE, TRT-5, TRF-5) antes de definir estratégia jurídica local.

**Status técnico:** Backend implementado (FastAPI + cache PostgreSQL 24h). Aguardando interface na vertical IATR.

**Custo operacional por consulta:** ~10-15 chamadas à API (uma por tribunal relevante na UF, filtrando 2-3 classes de interesse). Resultado em segundos, cacheado por 24h.

### 2. Consultas por Área de Atuação

A plataforma já pode gerar panoramas segmentados por área, variando apenas quais tribunais e classes processuais consultar:

#### Trabalhista
- Volume de reclamações trabalhistas por TRT e período
- Comparativo entre TRTs — referência para avaliação de risco regional

**Cenário de uso:** A empresa está contratando equipe em Minas Gerais. O jurídico consulta o panorama do TRT-3 para entender o volume de reclamações trabalhistas na região, ajudando a calibrar contratos e políticas de compliance.

#### Imobiliário
- Volume de ações possessórias (reintegração, manutenção de posse) no TJ da UF
- Presença de usucapião e desapropriações — indica disputas fundiárias na região
- Ações de despejo — contexto do mercado locatício

**Cenário de uso:** A holding está avaliando aquisição de imóvel comercial no Paraná. O jurídico consulta o panorama de ações possessórias e usucapião no TJPR para avaliar riscos fundiários na UF.

#### Empresarial
- Volume de falências e recuperações judiciais no TJ da UF — ambiente econômico
- Execuções de título extrajudicial — nível de inadimplência na região
- Tendência temporal — a UF está em crise ou recuperação?

**Cenário de uso:** A holding está negociando parceria com fornecedor de Manaus. O jurídico consulta o panorama de falências e recuperações judiciais no TJAM para avaliar a saúde do ambiente empresarial local.

#### Suporte a Licitações (integração com equipe do Maurício)
- Risco jurisdicional: volume de execuções fiscais na UF (indica inadimplência do poder público)
- Classes que afetam habilitação (falência, recuperação judicial, execução fiscal)
- Mandados de segurança — impugnações a atos administrativos na jurisdição

**Cenário de uso:** O Maurício identifica licitação em Belém. O jurídico consulta os dados do TJPA e TRF-1 para emitir parecer de risco jurisdicional. A decisão go/no-go é tomada com dados do edital (equipe Maurício) + contexto judicial (equipe Andrea).

**Status técnico:** Mesma infraestrutura do panorama geral — são parametrizações diferentes da mesma consulta. Implementação rápida após definição das classes prioritárias por área.

### 3. Enriquecimento da Análise de Editais

A análise de editais com IA já incorpora dados judiciais da jurisdição. Quando a equipe de licitações solicita um Resumo Executivo, a IA recebe automaticamente contexto judicial da UF do edital, que aparece nas seções de pontos de atenção.

**Status técnico:** Integrado ao workflow N8N. Em validação com editais reais.

---

## Em desenvolvimento (roadmap)

### 4. Monitoramento Contínuo com Alertas

Para UFs e tribunais de interesse permanente, a plataforma poderá:

- Executar consultas periódicas (diária ou semanal) nos tribunais configurados
- Detectar variações significativas no volume de processos por classe
- Enviar alertas por e-mail quando houver mudança relevante
- Gerar relatório periódico com evolução das jurisdições monitoradas

**Cenário de uso:** O jurídico configura monitoramento de reclamações trabalhistas nos TRTs onde a holding tem operação. Se houver pico de distribuição em alguma região, recebe alerta automático.

**Previsão:** Segue o padrão do Monitor PNCP (já operacional). Estimativa: 2-4 semanas.

### 5. Análise Setorial por Assuntos CNJ

Cruzar dados com os códigos padronizados de assuntos do CNJ para identificar padrões em setores específicos. Requer mapeamento prévio de quais assuntos CNJ se correlacionam com cada área de interesse.

**Previsão:** Depende de definição conjunta dos assuntos relevantes. Estimativa: 3-4 semanas.

---

## Futuro

### 6. Inteligência para Contencioso

Para litígios em andamento, análise comparativa avançada:

- Panorama de processos da mesma classe na mesma jurisdição
- Distribuição por órgão julgador — quais varas/câmaras concentram processos do tipo
- Análise estatística de tempo de tramitação (requer processamento de processos individuais)

**Nota:** Esta funcionalidade exige paginação de centenas de processos individuais e processamento estatístico. Será implementada quando houver demanda validada e maturidade da plataforma.

### 7. Consulta por CNPJ/Parte (APIs complementares)

Integração com APIs adicionais (PJe, portais de tribunais, APIs comerciais) para permitir consulta por parte processual — viabilizando perguntas como "a empresa X tem processos?". Em mapeamento.

---

## Classes processuais por área de atuação (referência)

### Trabalhista
| Classe | Código | Relevância |
|--------|--------|------------|
| Reclamação Trabalhista | — | Tipo mais comum no TRT. Volume indica litigiosidade regional. |
| Ação Civil Pública Trabalhista | — | Indica atuação do MPT. Sinaliza problemas setoriais. |

### Imobiliário
| Classe | Código | Relevância |
|--------|--------|------------|
| Reintegração de Posse | — | Disputas possessórias ativas. Risco para aquisições. |
| Usucapião | — | Disputas fundiárias. Regularização pendente na região. |
| Ação de Despejo | — | Contexto do mercado locatício. |
| Desapropriação | — | Ação do poder público. Afeta investimentos imobiliários. |

### Empresarial
| Classe | Código | Relevância |
|--------|--------|------------|
| Falência | 1037 | Sinal crítico sobre ambiente econômico. |
| Recuperação Judicial | 1049 | Stress financeiro no setor/região. |
| Execução de Título Extrajudicial | 12135 | Nível de inadimplência. |
| Dissolução de Sociedade | — | Contexto para operações societárias. |

### Licitações e Contratos Públicos
| Classe | Código | Relevância |
|--------|--------|------------|
| Execução Fiscal | 1116/1117 | Dívidas com o fisco. Afeta habilitação. |
| Ação Civil Pública | 65 | Judicialização de contratos públicos. |
| Mandado de Segurança | — | Impugnações a atos administrativos. |

---

## Visão de futuro: integração entre verticais

Hoje, a equipe jurídica usa a vertical **IATR** e a equipe de licitações usa a vertical **Licitações**. No roadmap:

- **Canvas encadeados** — o resultado de uma análise (ex: panorama judicial) serve de input para outra ferramenta (ex: parecer de risco para licitação)
- **Orquestração via N8N** — workflows automatizados que conectam as análises das duas equipes
- **Dados compartilhados** — consultas judiciais feitas por uma equipe ficam disponíveis para a outra (cache compartilhado, sem chamadas duplicadas)

Exemplo prático: quando o Maurício solicitar análise de edital, o sistema automaticamente gera o contexto judicial para a Andrea revisar — tudo dentro da mesma plataforma.

---

## Próximo passo

Gostaríamos de agendar uma conversa com a Andrea para:

1. Entender quais áreas (trabalhista, imobiliário, empresarial) são prioritárias no dia a dia
2. Identificar os tribunais e UFs mais relevantes para a holding
3. Definir qual funcionalidade implementar primeiro na vertical IATR
4. Preencher os códigos de classe processual CNJ na tabela de referência acima
