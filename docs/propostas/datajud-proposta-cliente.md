# Integração DataJud (CNJ) — Proposta para Licitações

**Data:** 25/02/2026
**Preparado por:** Equipe Sunyata
**Para:** Maurício e equipe de licitações

---

## O que é o DataJud?

O DataJud é a base nacional de dados do Poder Judiciário, mantida pelo CNJ (Conselho Nacional de Justiça). Ele consolida informações de processos judiciais de **todos os tribunais do Brasil** — estaduais, federais, trabalhistas e tribunais superiores.

A Plataforma Sunyata já possui integração técnica com a API pública do DataJud, pronta para uso.

---

## O que podemos oferecer hoje

### 1. Panorama Judicial por Região

Antes de investir em uma proposta de licitação, a plataforma pode gerar um **relatório automático** da jurisdição do edital:

- Quantidade de processos de execução fiscal, falência e recuperação judicial na região
- Distribuição por tribunal (estadual, federal, trabalhista)
- Tendência temporal (crescimento ou redução de processos)
- Principais classes processuais ativas

**Utilidade:** Avaliar o "clima judicial" da região. Um município com alto volume de execuções fiscais pode indicar histórico de inadimplência do poder público — informação relevante para a decisão de participar.

### 2. Análise de Risco Setorial

A plataforma pode cruzar dados judiciais com o setor de atuação da licitação:

- Volume de litígios em setores como TI, refrigeração, digitalização na região
- Tipos de disputa mais comuns (trabalhista, fiscal, contratual)
- Tribunais com maior volume de processos no setor

**Utilidade:** Entender os riscos jurídicos típicos do setor na região do edital antes de investir na proposta.

### 3. Enriquecimento da Análise de Editais com IA

A análise de editais feita pela IA pode ser enriquecida com dados judiciais da região. Nas seções de "Pontos de Atenção" e "Recomendação", a IA considera:

- Volume de processos judiciais na jurisdição do órgão licitante
- Presença de execuções fiscais ou recuperações judiciais na região
- Contexto judicial relevante para a decisão de participar ou não

**Utilidade:** Análises de edital mais completas e fundamentadas, com contexto judicial integrado.

### 4. Monitoramento de Jurisdição

Para regiões onde a holding licita com frequência, a plataforma pode manter um **monitoramento contínuo**:

- Alertas automáticos quando há aumento significativo de processos em determinada classe
- Relatório periódico com evolução judicial nas UFs de interesse
- Dados atualizados a cada 24h via cache inteligente

**Utilidade:** Acompanhamento proativo das jurisdições estratégicas para licitações.

---

## Cobertura

| Abrangência | Detalhe |
|-------------|---------|
| **UFs** | Todos os 27 estados brasileiros |
| **Tribunais** | Estaduais (TJ), Federais (TRF), Trabalhistas (TRT), Superiores (STJ, TST) |
| **Classes processuais** | Execução fiscal, falência, recuperação judicial, ação civil pública, entre outras |
| **Atualização** | Dados do CNJ com cache de 24h |
| **Custo da API** | Gratuita (API pública do CNJ) |

---

## Limitações conhecidas

- A API pública do DataJud fornece **dados agregados por tribunal e classe processual**. Ela não permite, no momento, buscar processos específicos de uma empresa ou órgão pelo CNPJ.
- Para consultas específicas por empresa (ex: "a empresa X tem processos de falência?"), seria necessário acesso a APIs complementares ou consulta direta aos portais dos tribunais.
- Estamos mapeando opções de APIs complementares que permitiriam esse tipo de consulta específica no futuro.

---

## Integração com o jurídico

A plataforma permite que as equipes de licitações e jurídico trabalhem de forma integrada:

1. **Maurício** identifica um edital no Monitor PNCP
2. **Maurício** solicita análise com IA (Resumo Executivo)
3. **Andrea** acessa os dados judiciais da jurisdição para complementar com parecer de risco
4. Decisão conjunta go/no-go fundamentada em dados do edital + contexto judicial

No roadmap, estamos planejando integrar as ferramentas das duas verticais (Licitações e IATR) para que esse fluxo seja cada vez mais automatizado — com a análise judicial sendo incorporada diretamente ao processo de avaliação de editais.

---

## Próximos passos sugeridos

1. Definir quais UFs e setores são prioritários para licitações
2. Configurar os relatórios judiciais nos canvas de análise
3. Ativar o monitoramento contínuo nas jurisdições de interesse
4. Definir, junto com o jurídico, quais dados judiciais são mais relevantes para a decisão go/no-go
