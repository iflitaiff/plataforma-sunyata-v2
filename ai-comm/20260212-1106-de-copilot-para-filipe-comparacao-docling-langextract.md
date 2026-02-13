# Comparação: Docling vs LangExtract para Análise de Documentos Jurídicos

**De:** Copilot  
**Para:** Filipe  
**CC:** Claude  
**Data:** 2026-02-12  
**Ref:** Escolha de ferramenta de análise de documentos para v2  
**Ação:** Recomendação Técnica

---

## Contexto

**Necessidade:** Análise de documentos para advogados na vertical jurídica da Plataforma Sunyata v2.

**Candidatas:**
1. **Docling** (IBM Research / LF AI & Data)
2. **LangExtract** (Google Research)

**Arquitetura atual v2:**
- Backend: PHP 8.3 + PostgreSQL 16 + pgvector
- AI Service: Python 3.12 FastAPI (já existe)
- Frontend: HTMX + Tabler
- LLM: Claude via API

---

## 📊 COMPARAÇÃO RÁPIDA

| Critério | **Docling** | **LangExtract** | Vencedor |
|----------|-------------|-----------------|----------|
| **Foco Principal** | Parsing & conversão de docs | Extração estruturada com LLMs | - |
| **Melhor para** | Converter PDFs → Markdown/JSON | Extrair entidades específicas | - |
| **Integração v2** | ⭐⭐⭐⭐ Excelente | ⭐⭐⭐⭐⭐ Perfeita | **LangExtract** |
| **Uso com Claude** | ❌ Não nativo | ✅ Totalmente compatível | **LangExtract** |
| **Custo Computacional** | Alto (modelos VLM locais) | Baixo (usa LLM já pago) | **LangExtract** |
| **Maturidade** | Muito alta (IBM Research) | Alta (Google Research) | **Docling** |
| **Caso de Uso Jurídico** | ⭐⭐⭐ Bom | ⭐⭐⭐⭐⭐ Excelente | **LangExtract** |
| **Complexidade** | Alta (muitas features) | Baixa (foco em 1 coisa) | **LangExtract** |
| **Licença** | MIT | Apache 2.0 | Empate |

---

## 🔍 ANÁLISE DETALHADA

### 1. **Docling** (IBM Research)

#### **O que é:**
Framework completo de **parsing e conversão** de documentos com foco em entender a estrutura visual e lógica de PDFs complexos.

#### **Principais Features:**

✅ **Parsing multi-formato:**
- PDF (incluindo scanned com OCR)
- DOCX, PPTX, XLSX
- HTML, LaTeX
- Imagens (PNG, JPEG, TIFF)
- Áudio (WAV, MP3) com ASR
- WebVTT (legendas)

✅ **Entendimento avançado de PDF:**
- Page layout (colunas, seções)
- Reading order (ordem de leitura correta)
- Table structure (detecta e extrai tabelas)
- Code blocks, fórmulas matemáticas
- Image classification
- Chart understanding (coming soon)

✅ **Formatos de saída:**
- Markdown (limpo)
- HTML
- JSON (lossless)
- DocTags (formato próprio)

✅ **Integrações AI:**
- LangChain
- LlamaIndex
- Crew AI
- Haystack

✅ **Execução local:**
- Não precisa enviar docs para cloud
- Suporta Visual Language Models (VLM) local:
  - GraniteDocling (258M parameters)
  - Heron (novo modelo de layout)

#### **Exemplo de Uso:**

```python
from docling.document_converter import DocumentConverter

converter = DocumentConverter()
result = converter.convert("contrato.pdf")

# Exportar para Markdown limpo
markdown = result.document.export_to_markdown()

# Ou JSON estruturado
json_data = result.document.export_to_json()
```

#### **Arquitetura Interna:**

```
PDF → Layout Detection → Reading Order → Table Extraction → Markdown/JSON
       ↓                  ↓                ↓
    [VLM Model]      [OCR Engine]    [Structure Model]
```

---

### 2. **LangExtract** (Google Research)

#### **O que é:**
Framework especializado em **extração estruturada** de informações usando LLMs (como Claude, GPT, Gemini) com grounding preciso ao texto original.

#### **Principais Features:**

✅ **Extração guiada por exemplos (few-shot):**
- Você define o que quer extrair via prompt + exemplos
- LLM extrai entidades personalizadas
- Sem necessidade de fine-tuning

✅ **Grounding preciso:**
- Mapeia cada extração ao texto original (span exato)
- Permite highlight visual
- Rastreabilidade total

✅ **Otimizado para documentos longos:**
- Chunking inteligente
- Processamento paralelo (max_workers)
- Múltiplos passes (extraction_passes) para maior recall
- Configurável (max_char_buffer)

✅ **Visualização interativa:**
- Gera HTML self-contained
- Animações e filtros
- Review de centenas de entidades

✅ **Suporte multi-LLM:**
- **Gemini** (2.5-flash, 2.5-pro) — recomendado default
- **OpenAI** (GPT-4o, GPT-4, etc.)
- **Claude** (via custom provider — já tem suporte community)
- **Ollama** (modelos locais)
- **Vertex AI Batch** (reduz custo 50%)

✅ **Schema enforcement:**
- Output estruturado garantido (Pydantic)
- Controlled generation (Gemini)
- Validação automática

#### **Exemplo de Uso (Jurídico):**

```python
import langextract as lx

# Definir o que extrair
prompt = """
Extrair cláusulas, partes envolvidas, datas e valores de contratos.
Use texto exato. Não parafraseie.
"""

# Exemplo para guiar o modelo
examples = [
    lx.data.ExampleData(
        text="CONTRATANTE: Empresa XYZ LTDA, inscrita no CNPJ 12.345.678/0001-90...",
        extractions=[
            lx.data.Extraction(
                extraction_class="parte",
                extraction_text="Empresa XYZ LTDA",
                attributes={"tipo": "contratante", "cnpj": "12.345.678/0001-90"}
            ),
            # ... mais exemplos
        ]
    )
]

# Executar extração no contrato real
result = lx.extract(
    text_or_documents="contrato_completo.txt",
    prompt_description=prompt,
    examples=examples,
    model_id="claude-3-5-sonnet",  # Usar o Claude já pago
    extraction_passes=3,  # Múltiplas passadas (maior recall)
    max_workers=10,       # Paralelo
)

# Salvar resultados estruturados
lx.io.save_annotated_documents([result], "extractions.jsonl")

# Gerar visualização interativa
html = lx.visualize("extractions.jsonl")
with open("review.html", "w") as f:
    f.write(html)
```

#### **Arquitetura Interna:**

```
Documento → Chunking → LLM (Claude) → Extração com spans → JSONL + HTML
              ↓           ↓              ↓
         [Paralelo]  [Few-shot]     [Grounding]
```

---

## 🎯 INTEGRAÇÃO COM V2 DA PLATAFORMA SUNYATA

### **Cenário 1: Usar Docling**

```python
# services/ai/routes/document_analysis.py

from docling.document_converter import DocumentConverter
from fastapi import UploadFile

@app.post("/api/documents/parse")
async def parse_document(file: UploadFile):
    # 1. Converter PDF → Markdown
    converter = DocumentConverter()
    result = converter.convert(file.file)
    markdown = result.document.export_to_markdown()
    
    # 2. Enviar Markdown para Claude (análise adicional)
    claude_response = await claude_client.analyze(markdown)
    
    return {
        "markdown": markdown,
        "analysis": claude_response
    }
```

**Vantagens:**
✅ Parsing robusto de PDFs complexos (tabelas, colunas)
✅ Output limpo (Markdown ideal para Claude)
✅ OCR integrado (PDFs escaneados)

**Desvantagens:**
❌ **Dois passos**: Docling → Claude (latência)
❌ Não extrai entidades específicas sozinho
❌ Custo computacional alto (VLM local ou API)
❌ Não tem grounding visual (não sabe onde no PDF está cada informação)

---

### **Cenário 2: Usar LangExtract**

```python
# services/ai/routes/document_analysis.py

import langextract as lx
from fastapi import UploadFile

@app.post("/api/documents/extract")
async def extract_document(file: UploadFile):
    # 1. Definir schema de extração (cláusulas, partes, valores)
    prompt = load_extraction_prompt("juridico")
    examples = load_examples("juridico")
    
    # 2. Extrair entidades usando Claude
    result = lx.extract(
        text_or_documents=file.file.read().decode("utf-8"),
        prompt_description=prompt,
        examples=examples,
        model_id="claude-3-5-sonnet",  # Usar Claude API já configurado
        api_key=os.getenv("ANTHROPIC_API_KEY"),
        extraction_passes=2,
        max_workers=5
    )
    
    # 3. Salvar extrações no PostgreSQL
    await db.save_extractions(result.extractions)
    
    # 4. Gerar visualização HTML
    html_viz = lx.visualize_from_result(result)
    
    return {
        "extractions": result.extractions,
        "visualization_url": f"/documents/{doc_id}/review.html"
    }
```

**Vantagens:**
✅ **Um passo só**: LangExtract usa Claude diretamente
✅ Extração estruturada automática (já vem em formato SQL-ready)
✅ **Grounding visual**: sabe exatamente onde no texto está cada extração
✅ Visualização HTML incluída (review pelos advogados)
✅ **Usa o Claude que já está pago** (não adiciona custo novo)
✅ Processamento paralelo (rápido em docs longos)
✅ Schema enforcement (dados sempre consistentes)

**Desvantagens:**
❌ Precisa de texto limpo (se PDF for imagem, precisa OCR antes)
❌ Menos robusto que Docling para parsing visual de PDFs

---

### **Cenário 3: Híbrido (Docling + LangExtract)**

```python
@app.post("/api/documents/analyze-complete")
async def analyze_complete(file: UploadFile):
    # 1. Docling: Converter PDF complexo → Markdown
    converter = DocumentConverter()
    doc_result = converter.convert(file.file)
    clean_text = doc_result.document.export_to_markdown()
    
    # 2. LangExtract: Extrair entidades estruturadas do Markdown
    extraction_result = lx.extract(
        text_or_documents=clean_text,
        prompt_description=extraction_prompt,
        examples=extraction_examples,
        model_id="claude-3-5-sonnet"
    )
    
    return {
        "markdown": clean_text,
        "extractions": extraction_result.extractions,
        "visualization": lx.visualize_from_result(extraction_result)
    }
```

**Quando usar:**
- PDFs escaneados (Docling OCR + LangExtract estrutura)
- PDFs com tabelas complexas (Docling extrai, LangExtract analisa)
- Contratos com layout difícil (colunas, notas de rodapé)

**Trade-off:**
- ✅ Melhor dos dois mundos
- ❌ Mais complexo
- ❌ Mais lento (dois processamentos)
- ❌ Custo maior (Docling VLM + Claude API)

---

## 💼 CASO DE USO: ADVOGADOS ANALISANDO CONTRATOS

### **Workflow Ideal (LangExtract solo):**

```
1. Upload de contrato.pdf
   ↓
2. Extração automática:
   - Partes (CONTRATANTE, CONTRATADA)
   - Cláusulas (rescisão, multa, prazo)
   - Valores monetários
   - Datas importantes
   - Obrigações de cada parte
   ↓
3. Armazenar no PostgreSQL:
   - contracts.id
   - contract_clauses.clause_type, text, page_number
   - contract_parties.name, role, cnpj
   ↓
4. Advogado revisa via HTML interativo:
   - Highlight de cada cláusula
   - Filtros (mostrar só "multas", só "prazos")
   - Exportar para Word/Excel
   ↓
5. Busca semântica (pgvector):
   - "Encontre contratos com cláusula de não-concorrência"
   - Embeddings das cláusulas extraídas
```

**Vantagem competitiva:**
- ⚡ Análise em **segundos** vs horas manual
- 📊 **Dados estruturados** (pode gerar relatórios, dashboards)
- 🔍 **Busca semântica** (acha contratos similares)
- ✅ **Auditável** (grounding visual, rastreabilidade)

---

## 📊 COMPARAÇÃO POR CRITÉRIOS

### **1. Facilidade de Integração com v2**

| Critério | Docling | LangExtract | Vencedor |
|----------|---------|-------------|----------|
| Compatibilidade Python FastAPI | ✅ Sim | ✅ Sim | Empate |
| Usa Claude API já pago | ❌ Não (VLM próprio) | ✅ Sim | **LangExtract** |
| Integração com PostgreSQL | ⚠️ Manual | ✅ Direto (JSONL → SQL) | **LangExtract** |
| Integração com pgvector | ⚠️ Manual | ✅ Fácil (extractions → embeddings) | **LangExtract** |
| Curva de aprendizado | Média | Baixa | **LangExtract** |

---

### **2. Performance e Custo**

| Critério | Docling | LangExtract | Vencedor |
|----------|---------|-------------|----------|
| Latência (1 contrato 10 pgs) | ~10-20s (VLM local) | ~5-15s (Claude API) | **LangExtract** |
| Custo computacional | Alto (GPU recomendada) | Baixo (usa Claude) | **LangExtract** |
| Escalabilidade horizontal | Difícil (GPU) | Fácil (stateless) | **LangExtract** |
| Processamento paralelo | ⚠️ Limitado | ✅ Built-in (max_workers) | **LangExtract** |
| Batch processing | ❌ Não | ✅ Vertex AI Batch (-50% custo) | **LangExtract** |

**Custo estimado (1000 contratos/mês, 10 páginas cada):**

**Docling:**
- VLM local: GPU cloud ($200-500/mês) + energia
- VLM via API (se disponível): ~$50-100/mês

**LangExtract + Claude:**
- Claude API já pago pela plataforma: **$0 adicional**
- Ou, se uso dedicado: ~$30-50/mês (Claude Sonnet)

---

### **3. Funcionalidades para Advogados**

| Feature | Docling | LangExtract | Vencedor |
|---------|---------|-------------|----------|
| Extração de cláusulas | ⚠️ Precisa Claude depois | ✅ Direto | **LangExtract** |
| Identificação de partes | ⚠️ Manual | ✅ Automático | **LangExtract** |
| Extração de valores/datas | ⚠️ Manual | ✅ Automático | **LangExtract** |
| Grounding visual (highlight) | ❌ Não | ✅ Sim | **LangExtract** |
| Parsing de tabelas complexas | ✅ Excelente | ⚠️ Depende do texto | **Docling** |
| OCR de PDFs escaneados | ✅ Integrado | ❌ Precisa pré-processar | **Docling** |
| Layout de colunas/notas | ✅ Muito bom | ⚠️ Pode confundir | **Docling** |
| Busca semântica (embeddings) | ⚠️ Manual | ✅ Fácil (via pgvector) | **LangExtract** |
| Relatórios estruturados | ⚠️ Manual | ✅ Automático | **LangExtract** |

---

### **4. Manutenibilidade e Ecossistema**

| Critério | Docling | LangExtract | Vencedor |
|----------|---------|-------------|----------|
| Maturidade do projeto | Alta (IBM Research) | Média-Alta (Google Research) | **Docling** |
| Comunidade | Grande (LF AI & Data) | Crescente | **Docling** |
| Documentação | ⭐⭐⭐⭐⭐ Excelente | ⭐⭐⭐⭐ Muito boa | **Docling** |
| Atualizações | Frequentes | Frequentes | Empate |
| Breaking changes | Poucos | Poucos | Empate |
| Suporte comercial | ✅ IBM | ❌ Não oficial | **Docling** |
| Licença | MIT | Apache 2.0 | Empate |

---

## 🎯 RECOMENDAÇÃO FINAL

### **Para a Plataforma Sunyata v2, recomendo:**

## **🏆 LangExtract como escolha primária**

**Justificativas:**

1. ✅ **Integração perfeita com Claude** (já está pago)
2. ✅ **Zero custo adicional** de infraestrutura
3. ✅ **Grounding visual** (advogados podem revisar extrações)
4. ✅ **Extração estruturada** (SQL-ready, pgvector-ready)
5. ✅ **Processamento paralelo** (rápido em lotes)
6. ✅ **Baixa curva de aprendizado** (5 agentes IA conseguem usar)
7. ✅ **Visualização incluída** (HTML interativo para review)

**Casos onde Docling seria melhor:**
- PDFs 100% escaneados (OCR necessário)
- Contratos com tabelas extremamente complexas
- Layout multi-coluna complicado

**Solução:** Usar **Docling + LangExtract híbrido** nesses casos específicos:
```python
if is_scanned_pdf or has_complex_tables:
    text = docling_convert(pdf)  # Pré-processamento
    extractions = langextract_extract(text)  # Extração
else:
    extractions = langextract_extract(pdf)  # Direto
```

---

## 📋 PLANO DE IMPLEMENTAÇÃO

### **Fase 1: MVP (Semana 1-2)**

```bash
# services/ai/requirements.txt
langextract==0.2.0  # Versão estável atual
anthropic==0.50.0   # Claude API (já tem)
```

```python
# services/ai/routes/document_extraction.py

@app.post("/api/documents/extract-contract")
async def extract_contract(file: UploadFile):
    # 1. Carregar prompt e exemplos (pré-definidos)
    prompt = templates.get_extraction_prompt("contrato")
    examples = templates.get_examples("contrato")
    
    # 2. Extrair com LangExtract + Claude
    result = lx.extract(
        text_or_documents=await file.read(),
        prompt_description=prompt,
        examples=examples,
        model_id="claude-3-5-sonnet",
        api_key=settings.ANTHROPIC_API_KEY
    )
    
    # 3. Salvar no PostgreSQL
    doc_id = await db.save_document(file, result)
    
    # 4. Gerar embeddings (pgvector)
    embeddings = await generate_embeddings(result.extractions)
    await db.save_embeddings(doc_id, embeddings)
    
    return {
        "document_id": doc_id,
        "extractions": result.extractions,
        "review_url": f"/documents/{doc_id}/review"
    }
```

---

### **Fase 2: Refinamento (Semana 3-4)**

**2.1. Criar templates de extração por tipo de documento:**
```
templates/
├── contrato.yaml (cláusulas, partes, valores)
├── procuracao.yaml (outorgante, outorgado, poderes)
├── peticao.yaml (partes, pedidos, argumentos)
└── sentenca.yaml (dispositivo, fundamentação)
```

**2.2. Interface de Review (HTMX):**
```html
<!-- app/public/meus-documentos/review.php -->
<div id="extraction-viewer">
    <!-- Iframe com HTML gerado pelo LangExtract -->
    <iframe src="/api/documents/<?= $doc_id ?>/visualization.html"></iframe>
    
    <!-- Sidebar com ações -->
    <div class="sidebar">
        <button hx-post="/api/documents/<?= $doc_id ?>/approve">
            Aprovar Extrações
        </button>
        <button hx-post="/api/documents/<?= $doc_id ?>/edit">
            Editar Manualmente
        </button>
    </div>
</div>
```

---

### **Fase 3: Escala (Semana 5-6)**

**3.1. Batch Processing (Vertex AI):**
```python
# Para processar 100+ contratos de uma vez
result = lx.extract(
    text_or_documents=document_urls,  # Lista de URLs
    prompt_description=prompt,
    examples=examples,
    model_id="gemini-2.5-flash",
    language_model_params={
        "vertexai": True,
        "batch": {"enabled": True}  # 50% de desconto
    }
)
```

**3.2. Cache de Prompts:**
```python
# Redis cache para evitar reprocessamento
cache_key = f"extraction:{doc_hash}:{template_version}"
if cached := await redis.get(cache_key):
    return cached
```

---

## 💡 CASO DE USO COMPLETO: ESCRITÓRIO DE ADVOCACIA

### **Workflow Real:**

```
1. Advogado faz upload de 50 contratos (.zip)
   ↓
2. Sistema processa em paralelo:
   - LangExtract extrai: partes, cláusulas, valores, datas
   - Gera embeddings (pgvector)
   - Cria visualizações HTML
   ↓
3. Dashboard mostra:
   - "45 contratos processados, 5 com problemas"
   - "15 contratos têm cláusula de não-concorrência"
   - "3 contratos vencem em 30 dias"
   ↓
4. Advogado clica em contrato:
   - Vê HTML com highlights
   - Edita extrações se necessário
   - Aprova
   ↓
5. Sistema gera relatório:
   - Excel com todas as cláusulas
   - Alertas automáticos (vencimentos)
   - Busca semântica: "Achar contratos similares a este"
```

**Valor para o cliente:**
- ⏱️ 50 contratos em **10 minutos** vs **2 dias** manual
- 📊 **Dados estruturados** (pode fazer analytics)
- 🔍 **Busca inteligente** (acha padrões)
- ✅ **Auditável** (rastreabilidade de cada extração)

---

## 🚀 CONCLUSÃO

### **Escolha: LangExtract**

**Razão principal:** Integração perfeita com a arquitetura atual (Claude + Python FastAPI + PostgreSQL + pgvector).

**Plano B:** Adicionar Docling depois, **somente se** clientes tiverem muitos PDFs escaneados ou tabelas complexas demais para LangExtract.

**Analogia:** LangExtract é o "HTMX da análise de documentos" — simples, direto, usa o que já existe (Claude), e resolve 90% dos casos. Docling é o "React da análise de documentos" — poderoso, mas mais complexo, adiciona dependências.

**ROI estimado:**
- Implementação: 2 semanas
- Custo adicional: $0 (usa Claude já pago)
- Valor para advogados: 10-20x mais rápido que análise manual
- Diferencial competitivo: **Alto** (poucos SaaS jurídicos têm isso)

---

**Copilot**  
Engenheiro de Software  
Plataforma Sunyata v2

**Próximos passos sugeridos:**
1. Instalar LangExtract no ambiente de dev
2. Criar 3 exemplos de extração (contrato, procuração, petição)
3. Testar com 5 documentos reais
4. Medir latência e qualidade
5. Decidir se precisa híbrido Docling+LangExtract
