# Continuidade Arquitetural: SurveyJS + RAG (pgvector)

**Data:** 25/02/2026  
**Versão:** 2.0 (consolidação com dimensões adicionais)  
**Contexto:** Checkpoint de arquitetura para retomada futura  
**Referência operacional:** Claude Code segue como arquiteto principal do sistema

---

## Princípio de Arquitetura (decisão aprovada)

Manter o sistema core enxuto e deslocar complexidade para componentes externos:

- **SurveyJS**: interatividade, lógica de formulário, eventos de UI e coleta estruturada.
- **FastAPI**: orquestração de IA, RAG, serviços de retrieval/contexto.
- **LiteLLM**: gateway de modelos (LLM e embeddings).
- **N8N**: automações assíncronas e integrações operacionais.
- **PostgreSQL + pgvector**: armazenamento vetorial e recuperação semântica.

---

## Estado Atual Confirmado

- Extensão `vector` habilitada no schema base do PostgreSQL.
- Fluxo atual usa majoritariamente busca textual (`tsvector`) para documentos/submissions.
- Não existe pipeline vetorial completo ativo para Canvas no estado atual.
- Pipeline IATR (PNCP monitor → enriquecimento → extração de PDFs) já produz texto completo de editais — corpus potencial para ingestão vetorial.

Arquivos-chave:

- `/app/public/api/canvas/submit.php`
- `/app/src/Helpers/ClaudeFacade.php`
- `/app/src/Services/DocumentLibraryService.php`
- `/app/src/Services/SubmissionService.php`
- `/services/ai/app/routers/canvas.py`
- `/services/ai/app/services/llm.py`

---

## Decisão Técnica sobre RAG sem GPU

RAG com `pgvector` é viável sem GPU para este cenário (contratos/minutas/editais), desde que:

- haja filtro estrutural antes da similaridade (tenant/grupo/vertical/tipo),
- índice vetorial apropriado esteja ativo (IVFFlat ou HNSW),
- `top_k` seja controlado (5-10 chunks, não 50),
- ingestão de embeddings seja assíncrona (não no request path).

Conclusão: GPU **não é pré-requisito** para MVP/produção inicial de RAG neste sistema.

---

## Onde RAG traz mais valor no sistema atual

1. **Canvas Submit** — injeção de contexto semântico antes da geração final.
2. **Biblioteca de documentos** — recuperação semântica de cláusulas por similaridade.
3. **Reuso de submissões anteriores** — por vertical/template, com similaridade semântica.
4. **Verticais jurídicas** (`iatr`, `legal`, `licitacoes`, `juridico`) para minutas e pareceres.
5. **Base de conhecimento IATR** — editais extraídos alimentam retrieval automático (ver seção dedicada).

---

## Conexão IATR → Knowledge Base (oportunidade identificada)

O pipeline PNCP (monitor → enriquecimento → extração de PDFs) já produz texto completo de editais com metadados estruturados (órgão, UF, modalidade, objeto, itens, valores). Esse texto é exatamente o corpus que alimentaria o RAG, sem ingestão manual.

### Casos de uso habilitados

1. **Editais similares**: "Encontre editais anteriores com requisitos parecidos com este" (similarity search sobre chunks de objeto/requisitos técnicos).
2. **Análise agregada**: "Quais cláusulas de habilitação são mais comuns para objetos de TI no RJ?" (retrieval + sumarização sobre corpus).
3. **Contexto para análise**: injetar trechos de editais similares já analisados como referência para o LLM ao analisar um novo edital, melhorando a qualidade da triagem.
4. **Comparação**: "Como os requisitos técnicos deste edital se comparam com os de editais similares?"

### Ponto de integração

Após extração de PDF bem-sucedida, N8N chama endpoint de ingestão que chunka, gera embeddings e indexa no pgvector. O trigger já existe no workflow (após `Extract PDF Text` → sucesso). O corpus cresce automaticamente com cada ciclo diário do monitor.

### Decisão pendente

Definir se a ingestão vetorial é habilitada por default ou sob demanda. Recomendação: sob demanda inicialmente (botão "Indexar" ou flag no monitor), com ingestão automática após validação da qualidade de chunking em amostra de 20-30 editais.

---

## Estratégia de Chunking (decisão pendente)

### Documentos jurídicos estruturados (editais, contratos, minutas)

- **Chunk primário**: por seção/cláusula, detectando padrões de estrutura ("Cláusula X", "Seção Y", "Art. Z", numeração hierárquica 1., 1.1, 1.1.1).
- **Chunk secundário**: por parágrafo, se a cláusula/seção exceder ~1500 tokens.
- **Overlap**: 100 tokens entre chunks secundários para preservar continuidade.
- **Metadados por chunk**: tipo_documento, seção_pai, número_cláusula, página_pdf, edital_id (se IATR).

### Documentos não-estruturados

- Chunk por parágrafo com overlap de ~100 tokens.
- Tamanho-alvo: 300-800 tokens por chunk.

### Decisões pendentes

- Threshold exato de split secundário (1500 tokens proposto, validar com amostra).
- Regex/heurísticas para detecção de estrutura hierárquica em editais brasileiros.
- Tratamento de tabelas e anexos técnicos (chunkar separadamente ou inline).

---

## Modelo de Embeddings (decisão pendente)

### Candidatos via LiteLLM

| Modelo | Dimensões | Custo (1M tokens) | Notas |
|--------|-----------|-------------------|-------|
| `text-embedding-3-small` (OpenAI) | 1536 | $0.02 | Bom custo/qualidade, default recomendado |
| `text-embedding-3-large` (OpenAI) | 3072 | $0.13 | Melhor qualidade, 6.5× mais caro |
| `voyage-3` (Voyage AI) | 1024 | $0.06 | Benchmarks superiores para retrieval jurídico |

### Decisão

Começar com `text-embedding-3-small` por custo e simplicidade (já disponível via LiteLLM/OpenAI). Migrar para `voyage-3` se a qualidade de retrieval no domínio jurídico brasileiro for insuficiente após testes com amostra real.

### Requisito técnico

A coluna pgvector deve usar dimensão parametrizável (configuração, não hardcoded). Permite trocar de modelo sem migration. Exemplo:

```sql
-- A dimensão acompanha o modelo ativo
ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(1536);
-- Se migrar para voyage-3: criar nova coluna vector(1024), reindexar, drop antiga
```

### Estimativa de custo para corpus IATR

~320 editais × ~30 chunks/edital × ~500 tokens/chunk = ~4.8M tokens = ~$0.10 total para indexar o corpus existente com `text-embedding-3-small`. Custo marginal por dia: ~220 editais × 30 chunks × 500 tokens = ~$0.07/dia. Negligível.

---

## Busca Híbrida: tsvector + pgvector (padrão aprovado)

O sistema já usa `tsvector` para busca textual. Com pgvector, a busca semântica complementa. Combinar os dois na mesma query PostgreSQL usando **Reciprocal Rank Fusion (RRF)**:

```
score_final = α × (1 / (k + rank_tsvector)) + (1 - α) × (1 / (k + rank_pgvector))
```

- `α = 0.3` (default: favorece semântica sobre keyword para domínio jurídico)
- `k = 60` (constante RRF padrão)
- Ajustável por vertical (licitações pode preferir mais keyword, contratos mais semântica)

### Implementação

2 CTEs na mesma query PostgreSQL — uma para `ts_rank` com `tsvector`, outra para `<=>` distância cosseno com pgvector. Merge por RRF no SELECT final. Não precisa de motor de busca externo (ElasticSearch não necessário para este volume).

### Exemplo conceitual

```sql
WITH keyword_results AS (
    SELECT chunk_id, ts_rank(tsv, query) AS score,
           ROW_NUMBER() OVER (ORDER BY ts_rank(tsv, query) DESC) AS rank
    FROM knowledge_chunks, plainto_tsquery('portuguese', $1) query
    WHERE tsv @@ query AND tenant_id = $2
    LIMIT 20
),
semantic_results AS (
    SELECT chunk_id, 1 - (embedding <=> $3::vector) AS score,
           ROW_NUMBER() OVER (ORDER BY embedding <=> $3::vector) AS rank
    FROM knowledge_chunks
    WHERE tenant_id = $2
    ORDER BY embedding <=> $3::vector
    LIMIT 20
)
SELECT COALESCE(k.chunk_id, s.chunk_id) AS chunk_id,
       0.3 * (1.0 / (60 + COALESCE(k.rank, 1000)))
     + 0.7 * (1.0 / (60 + COALESCE(s.rank, 1000))) AS rrf_score
FROM keyword_results k
FULL OUTER JOIN semantic_results s USING (chunk_id)
ORDER BY rrf_score DESC
LIMIT 10;
```

---

## SurveyJS: Interação Dinâmica com Resultado do RAG

Compatível e recomendado. Padrões aprovados:

1. **`onValueChanged`** para disparar busca semântica quando o usuário preenche campos-chave (ex: tipo de objeto, UF, valor estimado).
2. **`choicesByUrl` / `onLoadChoicesFromServer`** para preencher dropdown de sugestões com resultados do retrieval.
3. **`survey.setValue()`** para injetar cláusulas selecionadas pelo usuário de volta no formulário.
4. **`visibleIf` + variáveis** para UX condicional baseada em contexto recuperado (ex: mostrar alertas se retrieval encontrou cláusulas restritivas em editais similares).
5. **Campos ocultos** para rastreabilidade: `doc_id`, `chunk_id`, `similarity_score`, `modelo_embedding`, `versão_retrieval`.

### Fluxo tipo para Canvas com RAG

```
Usuário preenche campo "Objeto" no SurveyJS
  → onValueChanged dispara POST /api/ai/retrieval/search
  → FastAPI busca chunks similares (híbrido tsvector + pgvector)
  → Retorna top-5 cláusulas relevantes
  → SurveyJS exibe sugestões em painel lateral ou dropdown
  → Usuário seleciona/descarta
  → No submit, contexto selecionado é injetado no prompt do LLM
  → LLM gera resposta com referências aos chunks usados
```

---

## Serviços Recomendados para Próxima Fase

1. **`KnowledgeIngestionService`** — chunk + embedding + metadados + indexação pgvector. Recebe documento ou texto bruto, retorna IDs dos chunks criados. Assíncrono para documentos grandes.

2. **`RetrievalService`** — busca híbrida textual + vetorial. Recebe query + filtros estruturais (tenant, vertical, tipo_doc), retorna chunks ranqueados por RRF. Endpoint FastAPI.

3. **`ContextAssemblyService`** — montagem de contexto para prompt LLM. Recebe chunks selecionados, formata com citações de origem (doc_id, seção, página). Garante que o LLM sabe de onde vem cada trecho.

4. **`TenantKnowledgeService`** — controle de acesso. Garante isolamento por tenant/grupo. Regras de compartilhamento (ex: base de editais PNCP é pública para todos os tenants, base de contratos é privada por grupo).

5. **Worker assíncrono de embeddings** — processa fila de documentos para ingestão. Pode ser endpoint FastAPI com background task ou job N8N. Não bloqueia o request path.

---

## Feedback Loop (futuro)

### Sinais implícitos de relevância

| Sinal | Significado | Onde capturar |
|-------|-------------|---------------|
| Edital triado → aprofundado para Resumo/Completa | Relevante | `system_events`: dois `analysis.requested` para mesmo `entity_id` com níveis diferentes |
| Edital triado → ignorado | Irrelevante | Ausência de segundo evento em janela de 24h |
| Análise completa realizada | Alta relevância | `system_events`: `analysis.requested` com nível `completa` |
| Chunk de retrieval selecionado pelo usuário | Contexto útil | Campo oculto SurveyJS → payload do submit |
| Chunk descartado pelo usuário | Contexto irrelevante | Sugestão exibida mas não selecionada |

### Uso futuro

- Fine-tuning de ranking RRF (ajustar `α` por vertical/usuário baseado em feedback).
- Personalização de triagem por perfil de busca do cliente.
- Re-ranking de chunks baseado em histórico de seleção.

### Pré-requisito

A tabela `system_events` com `trace_id` (já especificada) captura os sinais de análise automaticamente. Os sinais de retrieval (seleção/descarte de chunks) requerem instrumentação no SurveyJS — campos ocultos no submit.

---

## Schema Vetorial (referência para implementação)

```sql
-- Tabela principal de chunks com embeddings
CREATE TABLE knowledge_chunks (
    id BIGSERIAL PRIMARY KEY,
    
    -- Origem
    source_type VARCHAR(30) NOT NULL,     -- 'edital', 'contrato', 'minuta', 'documento'
    source_id INTEGER,                     -- FK para pncp_editais, documents, etc.
    tenant_id INTEGER,                     -- isolamento por tenant (NULL = público)
    
    -- Conteúdo
    content TEXT NOT NULL,                 -- texto do chunk
    chunk_index INTEGER NOT NULL,          -- posição sequencial no documento
    
    -- Metadados estruturais
    metadata JSONB,                        -- seção_pai, número_cláusula, página_pdf, etc.
    
    -- Busca textual
    tsv tsvector GENERATED ALWAYS AS (to_tsvector('portuguese', content)) STORED,
    
    -- Busca vetorial (dimensão do modelo ativo)
    embedding vector(1536),                -- text-embedding-3-small; ajustar se trocar modelo
    
    -- Controle
    embedding_model VARCHAR(50),           -- modelo usado para gerar o embedding
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Índices
CREATE INDEX idx_chunks_tsv ON knowledge_chunks USING gin(tsv);
CREATE INDEX idx_chunks_embedding ON knowledge_chunks USING hnsw(embedding vector_cosine_ops);
CREATE INDEX idx_chunks_source ON knowledge_chunks(source_type, source_id);
CREATE INDEX idx_chunks_tenant ON knowledge_chunks(tenant_id) WHERE tenant_id IS NOT NULL;
```

**Nota:** Este schema é referência. Não criar a tabela até a implementação efetiva do pipeline vetorial. A decisão de dimensão (1536) acompanha o modelo escolhido.

---

## Checklist de Depuração para Retomada

### Canvas (fluxo existente)

1. Confirmar `ai_service_mode` e caminho de execução (`generateForCanvas` vs `generateViaService`).
2. Verificar origem dos arquivos usados no prompt (`user_files` vs `user_documents`).
3. Logar payload de retrieval por canvas (filtros + top_k + latência).
4. Validar que contexto RAG entrou no prompt final (amostra com IDs de chunks).
5. Auditar isolamento por tenant/grupo e permissões de leitura.
6. Medir tempo E2E: formulário → retrieval → geração → persistência.

### RAG (quando implementado)

7. Validar qualidade de chunking em amostra de 20-30 editais (chunks completos, sem cortes no meio de cláusula).
8. Comparar resultados de busca keyword-only vs. semântica-only vs. híbrida para 10 queries de teste.
9. Medir latência de retrieval sob carga (pgvector HNSW com 10K+ chunks).
10. Verificar que embeddings são gerados assincronamente (não bloqueiam request path).

---

## Observação de Governança

Este documento é checkpoint de arquitetura para continuidade de decisões. Durante novas depurações ou evoluções, atualizar com:

- Data
- Hipótese investigada
- Evidências coletadas
- Decisão tomada
- Impacto em produção

### Histórico de versões

| Data | Versão | Alteração |
|------|--------|-----------|
| 25/02/2026 | 1.0 | Checkpoint inicial: estado atual, decisões aprovadas, serviços recomendados |
| 25/02/2026 | 2.0 | Adições: estratégia de chunking, modelo de embeddings, ponte IATR→RAG, busca híbrida RRF, feedback loop, schema vetorial de referência |
