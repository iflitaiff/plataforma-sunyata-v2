# Integração DataJud (CNJ) — Proposta para Licitações

**Data:** 25/02/2026 (rev. 2)
**Preparado por:** Equipe Sunyata
**Para:** Maurício e equipe de licitações

---

## O que é o DataJud?

O DataJud é a base nacional de dados do Poder Judiciário, mantida pelo CNJ (Conselho Nacional de Justiça). Ele consolida informações de processos judiciais de **todos os tribunais do Brasil** — estaduais, federais, trabalhistas e tribunais superiores.

A Plataforma Sunyata já possui integração técnica com a API pública do DataJud, pronta para uso.

---

## Importante: escopo e limitações da integração

A API pública do DataJud fornece **dados por tribunal, classe processual e período**. Isso significa que a plataforma consegue responder perguntas como:

- "Quantas execuções fiscais foram distribuídas no TJRJ no último ano?"
- "O volume de falências no TJSP está crescendo ou diminuindo?"

A API **não permite**, no momento, buscar processos pelo CNPJ ou nome de uma empresa ou órgão específico. Ou seja:

- ❌ "A Prefeitura de Volta Redonda é ré em algum processo?"
- ❌ "A empresa XPTO Ltda tem processos de falência?"

Todas as funcionalidades abaixo foram desenhadas para funcionar dentro dessa realidade. Estamos mapeando APIs complementares que permitiriam consulta por CNPJ no futuro.

**Granularidade geográfica:** Os dados são por tribunal (ex: TJRJ, TRF-2, TRT-1), não por comarca ou município. Quando o edital é de uma cidade específica, o panorama reflete o tribunal estadual inteiro — o que ainda é útil como indicador do ambiente judicial da UF.

---

## Disponível agora (v1)

### 1. Panorama Judicial por Tribunal/UF

Antes de investir em uma proposta de licitação, a plataforma pode gerar um **relatório automático** do tribunal vinculado ao edital:

- Volume de processos de execução fiscal, falência e recuperação judicial no tribunal da UF
- Distribuição por tribunal na UF (estadual, federal, trabalhista)
- Tendência temporal (crescimento ou redução nos últimos 12 meses)
- Principais classes processuais ativas na jurisdição

**Utilidade:** Avaliar o "clima judicial" da UF. Um estado com alto volume de execuções fiscais pode indicar histórico de inadimplência do poder público — informação relevante para a decisão de participar.

**Status técnico:** Backend implementado e testado. Cache de 24h em PostgreSQL. Custo zero (API pública do CNJ).

### 2. Enriquecimento da Análise de Editais com IA

A análise de editais feita pela IA (Resumo Executivo) já é enriquecida com dados judiciais da jurisdição. Nas seções de "Pontos de Atenção", a IA considera:

- Volume de processos judiciais no tribunal do órgão licitante
- Presença de execuções fiscais ou recuperações judiciais na UF
- Contexto judicial relevante para a decisão de participar ou não

**Utilidade:** Análises de edital mais completas e fundamentadas, com contexto judicial integrado automaticamente.

**Status técnico:** Integrado ao workflow N8N de análise de editais. Em validação com editais reais.

---

## Em desenvolvimento (roadmap)

### 3. Monitoramento Contínuo de Jurisdições

Para UFs onde a holding licita com frequência, a plataforma poderá manter um **monitoramento automático**:

- Alertas por e-mail quando houver variação significativa no volume de processos em classes relevantes (execução fiscal, falência, recuperação judicial)
- Relatório periódico com evolução judicial nas UFs de interesse
- Dados atualizados automaticamente via cache inteligente

**Utilidade:** Acompanhamento proativo das jurisdições estratégicas para licitações, sem necessidade de consulta manual.

**Previsão:** Segue o mesmo padrão técnico do Monitor PNCP (já operacional). Estimativa de implementação: 2-4 semanas após priorização.

### 4. Análise por Classe Processual Relevante

A plataforma poderá cruzar dados judiciais com classes processuais diretamente relevantes para licitações:

- Volume de mandados de segurança (impugnações a atos administrativos) na jurisdição
- Ações civis públicas (judicialização de contratos públicos)
- Execuções de título extrajudicial (inadimplência no setor)

**Utilidade:** Entender os tipos de litígio mais comuns na jurisdição do edital, focando nas classes que afetam diretamente quem licita.

**Previsão:** Requer mapeamento das classes processuais mais relevantes junto com a equipe. Estimativa: 2-3 semanas após definição dos parâmetros.

---

## Cobertura

| Abrangência | Detalhe |
|-------------|---------|
| **UFs** | Todos os 27 estados brasileiros |
| **Tribunais** | Estaduais (TJ), Federais (TRF), Trabalhistas (TRT), Superiores (STJ, TST) |
| **Classes processuais** | Execução fiscal, falência, recuperação judicial, mandado de segurança, entre outras |
| **Atualização** | Dados do CNJ com cache de 24h |
| **Custo da API** | Gratuita (API pública do CNJ) |

---

## Integração com o jurídico

A plataforma permite que as equipes de licitações e jurídico trabalhem de forma integrada:

1. **Maurício** identifica um edital no Monitor PNCP
2. **Maurício** solicita análise com IA (Resumo Executivo) — que já inclui contexto judicial da UF
3. **Andrea** acessa os dados judiciais da jurisdição para complementar com parecer de risco
4. Decisão conjunta go/no-go fundamentada em dados do edital + contexto judicial

No roadmap, estamos planejando integrar as ferramentas das duas verticais (Licitações e IATR) para que esse fluxo seja cada vez mais automatizado.

---

## Próximos passos sugeridos

1. Definir quais UFs são prioritárias para licitações (para testar o panorama judicial)
2. Validar a qualidade do contexto judicial nas análises de editais com casos reais
3. Definir, junto com o jurídico, quais classes processuais são mais relevantes para a decisão go/no-go
4. Priorizar o monitoramento contínuo, se houver interesse
