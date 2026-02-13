# Análise e Planejamento: Integração de APIs Jurídicas no v2

**De:** Copilot  
**Para:** Filipe, Claude  
**CC:** Manus, Gemini, Codex  
**Data:** 2026-02-12  
**Ref:** Análise dos relatórios de APIs jurídicas do Manus  
**Ação:** Planejamento Técnico + Roadmap de Implementação

---

## Contexto

Analisei dois relatórios do **Manus AI** sobre integração de APIs jurídicas:
1. **Proposta de Integração de APIs Jurídicas ao Portal Sunyata** (4 páginas)
2. **Relatório de Automação e Pesquisa Jurídica por Meios Digitais** (5 páginas)

**Objetivo:** Avaliar viabilidade técnica, custos e priorizar implementação no **v2**.

---

## 📊 RESUMO EXECUTIVO

### **APIs Identificadas**

| API | Tipo | Custo | Complexidade | Valor para Advogados | Prioridade |
|-----|------|-------|--------------|---------------------|-----------|
| **PNCP** (Licitações) | Pública | Grátis | ⭐ Baixa | ⭐⭐⭐⭐⭐ Muito Alto | **P0** |
| **DataJud** (Processos) | Pública | Grátis | ⭐⭐ Média | ⭐⭐⭐⭐⭐ Muito Alto | **P0** |
| **CGU** (Sanções) | Pública | Grátis | ⭐ Baixa | ⭐⭐⭐⭐ Alto | **P1** |
| **Conecta gov.br** | Pública | Grátis* | ⭐⭐⭐ Alta | ⭐⭐⭐ Médio | **P2** |
| **Escavador** | Comercial | Pago | ⭐⭐ Média | ⭐⭐⭐⭐ Alto | **P3** |
| **JUIT** | Comercial | Pago | ⭐⭐ Média | ⭐⭐⭐⭐⭐ Muito Alto | **P3** |

*Algumas APIs do Conecta são restritas a órgãos públicos.

### **Recomendação do Manus:**
✅ Iniciar por **PNCP** (licitações) — API mais simples e alta demanda.

### **Minha Análise:**
✅ **Concordo**, mas sugiro implementar **PNCP + DataJud em paralelo** (MVP em 2 semanas).

---

## 🔍 ANÁLISE DETALHADA POR API

### **1. PNCP - Portal Nacional de Contratações Públicas**

#### **Descrição:**
API para busca e monitoramento de editais de licitação em todo o Brasil.

#### **Especificações Técnicas:**

```http
GET https://pncp.gov.br/api/search/
```

**Parâmetros:**
| Parâmetro | Tipo | Descrição | Exemplo |
|-----------|------|-----------|---------|
| `q` | string | Palavra-chave de busca | "tecnologia da informação" |
| `tipos_documento` | string | Tipos de doc | "edital", "contrato", "termo_aditivo" |
| `ordenacao` | string | Ordenação | "-data" (mais recentes primeiro) |
| `pagina` | int | Página (paginação) | 1, 2, 3... |
| `status` | string | Status da licitação | "recebendo_proposta", "em_andamento" |

**Autenticação:** ❌ Nenhuma (API aberta)

**Rate Limiting:** Não especificado (testar em produção)

**Formato de Resposta:** JSON (ElasticSearch)

#### **Exemplo de Uso:**

```bash
curl "https://pncp.gov.br/api/search/?q=tecnologia%20da%20informacao&tipos_documento=edital&ordenacao=-data&pagina=1&status=recebendo_proposta"
```

#### **Integração com v2:**

```python
# services/ai/routes/juridico.py

@app.post("/api/juridico/licitacoes/buscar")
async def buscar_licitacoes(request: BuscarLicitacoesRequest):
    """
    Busca editais no PNCP via API interna.
    """
    params = {
        "q": request.palavras_chave,
        "tipos_documento": ",".join(request.tipos_documento),
        "ordenacao": "-data",
        "pagina": request.pagina or 1,
        "status": request.status or "recebendo_proposta"
    }
    
    async with httpx.AsyncClient() as client:
        response = await client.get(
            "https://pncp.gov.br/api/search/",
            params=params,
            timeout=30.0
        )
        response.raise_for_status()
        data = response.json()
    
    # Formatar resultados em Markdown para Claude analisar
    markdown = format_licitacoes_markdown(data)
    
    # Opcional: Usar Claude para resumir/filtrar
    analise = await claude_client.analyze(
        markdown,
        prompt="Resuma os editais mais relevantes considerando: " + request.criterios
    )
    
    return {
        "total": data["count"],
        "resultados": data["items"],
        "analise_claude": analise
    }
```

**Frontend (SurveyJS):**

```json
{
  "title": "Monitor de Licitações (PNCP)",
  "pages": [
    {
      "elements": [
        {
          "type": "text",
          "name": "palavras_chave",
          "title": "Palavras-chave de busca",
          "isRequired": true,
          "placeholder": "Ex: tecnologia da informação, consultoria tributária"
        },
        {
          "type": "dropdown",
          "name": "status",
          "title": "Status da contratação",
          "choices": [
            "recebendo_proposta",
            "em_andamento",
            "finalizada"
          ],
          "defaultValue": "recebendo_proposta"
        },
        {
          "type": "checkbox",
          "name": "tipos_documento",
          "title": "Tipos de documento",
          "choices": ["edital", "contrato", "termo_aditivo"],
          "defaultValue": ["edital"]
        },
        {
          "type": "text",
          "name": "quantidade",
          "title": "Quantidade de resultados",
          "inputType": "number",
          "defaultValue": 20
        }
      ]
    }
  ]
}
```

#### **Valor para Advogados:**
- ⚡ **Monitoramento automático** de editais por palavra-chave
- 📧 **Alertas** quando novo edital relevante for publicado
- 📊 **Dashboard** de oportunidades (licitações ativas)
- 🔗 **Links diretos** para cada edital no portal oficial

#### **Casos de Uso:**
1. Escritório que atua em licitações quer monitorar "obras públicas" em determinado estado
2. Empresa que vende para governo quer ser alertada de editais de "tecnologia"
3. Advogado tributarista quer acompanhar licitações de "consultoria fiscal"

#### **Complexidade de Implementação:** ⭐ **Baixa** (2-3 dias)
- Endpoint simples (GET, sem auth)
- JSON bem estruturado
- Pode ser testado via curl

---

### **2. DataJud - CNJ (Processos Judiciais)**

#### **Descrição:**
API pública do CNJ para acesso a **metadados** de processos judiciais de **todos os tribunais do Brasil**.

#### **Especificações Técnicas:**

```http
POST https://api-publica.datajud.cnj.jus.br/api_publica_{TRIBUNAL}/_search
```

**Tribunais disponíveis:**
- `api_publica_stf` (Supremo Tribunal Federal)
- `api_publica_tjrj` (TJ Rio de Janeiro)
- `api_publica_tjsp` (TJ São Paulo)
- `api_publica_tst` (Tribunal Superior do Trabalho)
- ... (todos os tribunais brasileiros)

**Autenticação:** 
```http
Authorization: APIKey cDY2N2JhYzMtZTM5MS00YzZkLTlkNmMtYmQwZGQ0OWY0YjFjOmMwNGI0ODNlLTExZTQtNDY5NC1hNTE3LWYxNTJhZmYwMjM1NQ==
```
✅ **Chave pública e estática** (não precisa cadastro)

**Query:** ElasticSearch Query DSL (JSON complexo)

#### **Exemplo de Query:**

```json
{
  "query": {
    "match": {
      "numeroProcesso": "0000000-00.0000.0.00.0000"
    }
  }
}
```

**Ou busca por CPF/CNPJ:**
```json
{
  "query": {
    "match": {
      "dadosBasicos.assunto.codigoNacional": 1234
    }
  }
}
```

#### **Integração com v2:**

```python
# services/ai/routes/juridico.py

DATAJUD_API_KEY = "cDY2N2JhYzMtZTM5MS00YzZkLTlkNmMtYmQwZGQ0OWY0YjFjOmMwNGI0ODNlLTExZTQtNDY5NC1hNTE3LWYxNTJhZmYwMjM1NQ=="

@app.post("/api/juridico/processos/consultar")
async def consultar_processo(request: ConsultarProcessoRequest):
    """
    Consulta metadados de processo no DataJud.
    """
    # Construir query ElasticSearch
    query = {
        "query": {
            "bool": {
                "must": []
            }
        }
    }
    
    if request.numero_processo:
        query["query"]["bool"]["must"].append({
            "match": {"numeroProcesso": request.numero_processo}
        })
    
    if request.cpf_cnpj:
        query["query"]["bool"]["must"].append({
            "match": {"dadosBasicos.polo.cpfCnpj": request.cpf_cnpj}
        })
    
    # Selecionar tribunal
    tribunal_endpoint = f"https://api-publica.datajud.cnj.jus.br/api_publica_{request.tribunal}/_search"
    
    async with httpx.AsyncClient() as client:
        response = await client.post(
            tribunal_endpoint,
            json=query,
            headers={"Authorization": f"APIKey {DATAJUD_API_KEY}"},
            timeout=30.0
        )
        response.raise_for_status()
        data = response.json()
    
    # Formatar dados principais
    processos = []
    for hit in data.get("hits", {}).get("hits", []):
        processo = hit["_source"]
        processos.append({
            "numero": processo.get("numeroProcesso"),
            "classe": processo.get("classe", {}).get("nome"),
            "assunto": processo.get("assunto", {}).get("nome"),
            "orgao_julgador": processo.get("orgaoJulgador", {}).get("nome"),
            "movimentacoes": processo.get("movimentacoes", [])[:5]  # Últimas 5
        })
    
    # Claude pode resumir/analisar
    if request.analisar_com_claude:
        resumo = await claude_client.analyze(
            json.dumps(processos, indent=2, ensure_ascii=False),
            prompt="Resuma os processos encontrados destacando pontos críticos"
        )
        return {"processos": processos, "analise": resumo}
    
    return {"processos": processos}
```

#### **Valor para Advogados:**
- 🔍 **Consulta unificada** de processos em qualquer tribunal
- 📋 **Monitoramento** de processos por CPF/CNPJ da parte
- ⚖️ **Dashboard** de andamento processual
- 📊 **Alertas** de novas movimentações

#### **Casos de Uso:**
1. Advogado quer consultar todos os processos de um cliente (CPF/CNPJ)
2. Escritório quer monitorar processos de determinado assunto (ex: INSS)
3. Due diligence: verificar histórico processual de empresa antes de contratar

#### **Complexidade de Implementação:** ⭐⭐ **Média** (5-7 dias)
- Query ElasticSearch DSL (sintaxe complexa)
- Precisa entender estrutura de dados do CNJ
- Rate limiting não documentado (testar)
- Resposta JSON grande (pode ter centenas de movimentações)

**Desafio:** Obter **inteiro teor** das decisões (DataJud só retorna metadados).

**Solução:** Combinar com **APIs comerciais** (JUIT, Escavador) ou **scraping** dos portais.

---

### **3. CGU - Portal da Transparência (Sanções)**

#### **Descrição:**
APIs para consultar sanções aplicadas a pessoas físicas e jurídicas (CEIS, CNEP), além de licitações, contratos e servidores federais.

#### **Especificações Técnicas:**

**Base URL:** `https://api.portaldatransparencia.gov.br/`

**Endpoints principais:**
- `/api-de-dados/ceis` — Cadastro de Empresas Inidôneas e Suspensas
- `/api-de-dados/cnep` — Cadastro Nacional de Empresas Punidas
- `/api-de-dados/licitacoes`
- `/api-de-dados/contratos`
- `/api-de-dados/servidores`

**Autenticação:** 
```http
chave-api-dados: <sua-chave>
```
✅ **Grátis**, mas requer **cadastro de e-mail** para obter chave.

**Exemplo de Consulta:**

```http
GET https://api.portaldatransparencia.gov.br/api-de-dados/ceis?cnpjSancionado=12345678000190
```

#### **Integração com v2:**

```python
# services/ai/routes/juridico.py

@app.post("/api/juridico/due-diligence/verificar-sancoes")
async def verificar_sancoes(cpf_cnpj: str):
    """
    Verifica sanções em CEIS e CNEP (CGU).
    """
    headers = {"chave-api-dados": settings.CGU_API_KEY}
    
    # Consultar CEIS
    async with httpx.AsyncClient() as client:
        ceis_response = await client.get(
            f"https://api.portaldatransparencia.gov.br/api-de-dados/ceis",
            params={"cnpjSancionado": cpf_cnpj} if len(cpf_cnpj) == 14 else {"cpfSancionado": cpf_cnpj},
            headers=headers
        )
        ceis_data = ceis_response.json() if ceis_response.status_code == 200 else []
        
        # Consultar CNEP
        cnep_response = await client.get(
            f"https://api.portaldatransparencia.gov.br/api-de-dados/cnep",
            params={"cnpjSancionado": cpf_cnpj} if len(cpf_cnpj) == 14 else {"cpfSancionado": cpf_cnpj},
            headers=headers
        )
        cnep_data = cnep_response.json() if cnep_response.status_code == 200 else []
    
    # Formatar resultado
    if not ceis_data and not cnep_data:
        return {
            "status": "clean",
            "mensagem": "✅ Nenhuma sanção encontrada",
            "detalhes": None
        }
    else:
        return {
            "status": "alerta",
            "mensagem": f"⚠️ Sanção encontrada: {len(ceis_data)} em CEIS, {len(cnep_data)} em CNEP",
            "detalhes": {
                "ceis": ceis_data,
                "cnep": cnep_data
            }
        }
```

**Frontend (HTMX integration no canvas de Due Diligence):**

```html
<!-- app/public/areas/juridico/due-diligence.php -->

<div class="form-group">
    <label>CPF/CNPJ da Empresa:</label>
    <input type="text" name="cpf_cnpj" id="cpf_cnpj" class="form-control">
    
    <button type="button" 
            class="btn btn-sm btn-primary mt-2"
            hx-post="/api/juridico/due-diligence/verificar-sancoes"
            hx-vals='{"cpf_cnpj": document.getElementById("cpf_cnpj").value}'
            hx-target="#resultado-sancoes"
            hx-indicator="#loading-sancoes">
        Verificar Sanções (CGU)
    </button>
    
    <div id="loading-sancoes" class="htmx-indicator">
        <div class="spinner-border spinner-border-sm" role="status"></div>
    </div>
</div>

<div id="resultado-sancoes" class="mt-3"></div>
```

#### **Valor para Advogados:**
- ✅ **Due Diligence automatizada** (sanções em segundos)
- 🔍 **Compliance** (verificar fornecedores antes de contratar)
- 📊 **Relatórios** de antecedentes empresariais

#### **Casos de Uso:**
1. Advogado fazendo due diligence de empresa antes de M&A
2. Departamento jurídico verificando fornecedor antes de contrato
3. Auditoria de compliance (listar todas sanções de grupo econômico)

#### **Complexidade de Implementação:** ⭐ **Baixa** (2-3 dias)
- API REST simples
- Apenas requer cadastro de e-mail para chave
- JSON bem estruturado

---

### **4. Conecta gov.br (Catálogo de APIs Governamentais)**

#### **Descrição:**
Catálogo de APIs do governo federal, incluindo:
- Consulta CPF/CNPJ (Receita Federal)
- Certidão de Antecedentes Criminais
- Dívida Ativa
- Publicação no DOU

#### **Restrições:**
⚠️ **Algumas APIs são restritas a órgãos públicos** (necessitam convênio).

#### **Exemplo: Publicação no DOU**
- API para publicar matérias no Diário Oficial da União
- **Não é útil** para advogados (somente para órgãos públicos)

#### **Exemplo: Consulta CPF (Receita Federal)**
- Pode ser útil para validação
- **Requer convênio** com a Receita

#### **Recomendação:**
🟡 **Prioridade P2** — Explorar apenas após implementar APIs prioritárias (PNCP, DataJud, CGU).

**Caso de uso viável:** Consulta de **Certidão de Antecedentes Criminais** pode ser útil para due diligence de pessoas físicas.

#### **Complexidade:** ⭐⭐⭐ **Alta** (burocracia de convênios, autenticação complexa)

---

### **5. APIs Comerciais (Pagas)**

#### **5.1. Escavador API**

**Foco:** Busca de processos por CPF/CNPJ e número único (CNJ).

**Vantagens sobre DataJud:**
- ✅ Interface mais simples
- ✅ Dados consolidados (menos processamento necessário)
- ✅ Inteiro teor de decisões (não só metadados)

**Desvantagens:**
- ❌ **Pago** (precisa consultar pricing)
- ❌ Dependência de terceiro (vendor lock-in)

**Quando usar:**
- Se DataJud for complexo demais para implementar
- Se precisar de **inteiro teor** das decisões facilmente

---

#### **5.2. JUIT API**

**Foco:** API exclusiva de jurisprudência + jurimetria.

**Funcionalidades:**
- Busca de julgados por palavra-chave
- Análise de tendências (jurimetria)
- Percentual de vitória por tese jurídica

**Vantagens:**
- ✅ **Especializada** em jurisprudência
- ✅ Dados já estruturados para análise
- ✅ Jurimetria (diferencial competitivo)

**Desvantagens:**
- ❌ **Pago** (provavelmente caro)

**Quando usar:**
- Se advogados precisarem de **análise estatística** de jurisprudência
- Para recursos (ex: "qual a chance de sucesso desta tese?")

---

#### **5.3. Turivius**

**Foco:** Plataforma completa de IA jurídica + jurimetria.

**Funcionalidades:**
- Pesquisa de jurisprudência com IA
- Análise preditiva de processos
- Dashboard de KPIs jurídicos

**Problema:**
- É uma **plataforma concorrente** (não uma API)
- Integrá-la seria fornecer tráfego para um concorrente

**Recomendação:**
❌ **Não integrar** — use as funcionalidades como **inspiração** para desenvolver features próprias.

---

## 🎯 ESTRATÉGIA DE RAG (RETRIEVAL-AUGMENTED GENERATION)

### **Problema: Alucinações de IA em Jurisprudência**

LLMs podem **inventar** julgados inexistentes quando perguntados sobre jurisprudência.

**Exemplo de alucinação:**
```
Usuário: "Existe jurisprudência sobre X?"
Claude: "Sim, veja o Processo nº 0000000-00.0000.0.00.0000 do STF..."
[Processo inventado]
```

### **Solução: RAG (Geração Aumentada por Recuperação)**

**Fluxo em 3 Etapas:**

```
1. RECUPERAÇÃO (Retrieval)
   ↓
   Buscar julgados REAIS via DataJud/JUIT/Escavador
   Obter INTEIRO TEOR das decisões
   ↓
2. AUGMENTED CONTEXT
   ↓
   Inserir textos completos no contexto do Claude
   ↓
3. GERAÇÃO (Generation)
   ↓
   Claude responde baseando-se APENAS nos docs fornecidos
   Exige citação de fonte completa (tribunal, nº processo, data)
```

### **Implementação no v2:**

```python
# services/ai/routes/juridico.py

@app.post("/api/juridico/jurisprudencia/pesquisar-rag")
async def pesquisar_jurisprudencia_rag(request: PesquisaJurisprudenciaRequest):
    """
    Pesquisa jurisprudência com RAG (anti-alucinação).
    """
    # ETAPA 1: Recuperação via DataJud
    processos = await buscar_processos_datajud(
        assunto=request.assunto,
        tribunal=request.tribunal,
        limite=10
    )
    
    # ETAPA 2: Obter inteiro teor (via API comercial ou scraping)
    decisoes_completas = []
    for processo in processos:
        inteiro_teor = await obter_inteiro_teor(processo["numero"])
        if inteiro_teor:
            decisoes_completas.append({
                "numero": processo["numero"],
                "texto": inteiro_teor,
                "tribunal": processo["tribunal"],
                "data": processo["data"]
            })
    
    # ETAPA 3: RAG com Claude
    prompt_rag = f"""
Você é um assistente jurídico especializado.

DOCUMENTOS FORNECIDOS (fonte da verdade):
{json.dumps(decisoes_completas, indent=2, ensure_ascii=False)}

PERGUNTA DO USUÁRIO:
{request.pergunta}

INSTRUÇÕES CRÍTICAS:
1. Baseie sua resposta EXCLUSIVAMENTE nos documentos fornecidos acima.
2. NÃO invente julgados ou números de processo.
3. Cite SEMPRE a fonte completa: Tribunal, Nº Processo, Data, Relator.
4. Se a pergunta não puder ser respondida com os docs fornecidos, diga explicitamente.

Responda de forma estruturada com:
- Tese jurídica
- Julgados que apoiam (com citações completas)
- Análise crítica
"""
    
    resposta_claude = await claude_client.generate(
        prompt=prompt_rag,
        max_tokens=4000
    )
    
    return {
        "documentos_recuperados": len(decisoes_completas),
        "resposta": resposta_claude,
        "fontes": [
            {
                "numero": d["numero"],
                "tribunal": d["tribunal"],
                "link": f"https://portal.{d['tribunal']}.jus.br/processo/{d['numero']}"
            }
            for d in decisoes_completas
        ]
    }
```

### **Protocolo de Verificação Anti-Alucinação**

**Frontend (Canvas de Jurisprudência):**

```json
{
  "type": "checkbox",
  "name": "verificacao_anti_alucinacao",
  "title": "Protocolo de Verificação",
  "defaultValue": ["exigir_fontes", "verificacao_cruzada"],
  "choices": [
    {
      "value": "exigir_fontes",
      "text": "✅ Exigir fonte completa de todos os julgados"
    },
    {
      "value": "verificacao_cruzada",
      "text": "🔍 Alerta: Sempre verificar nº processo no portal do tribunal"
    },
    {
      "value": "delimitacao_temporal",
      "text": "📅 Limitar busca aos últimos 5 anos"
    }
  ]
}
```

**Validação Humana (Essencial):**

Sempre exibir um **disclaimer**:
```
⚠️ IMPORTANTE: Esta resposta foi gerada com base nos documentos recuperados.
Sempre verifique os números de processo diretamente nos portais dos tribunais:
- STF: https://portal.stf.jus.br/processos/
- STJ: https://processo.stj.jus.br/SCON/
```

---

## 💰 ANÁLISE DE CUSTOS

### **APIs Públicas (Grátis)**

| API | Custo Mensal | Observações |
|-----|--------------|-------------|
| PNCP | **$0** | API aberta, sem limites conhecidos |
| DataJud | **$0** | Chave pública estática |
| CGU | **$0** | Requer cadastro de e-mail |
| **Total Público** | **$0/mês** | ✅ Zero custo adicional |

### **APIs Comerciais (Pagas)**

| API | Custo Estimado/mês | Quando Vale a Pena |
|-----|-------------------|-------------------|
| Escavador | $200-500 | Se DataJud for muito complexo |
| JUIT | $300-800 | Para jurimetria avançada |
| Turivius | $500-1500 | ❌ Não recomendo (concorrente) |

**Recomendação:** 
✅ **Iniciar com APIs públicas** (custo zero).
🟡 **Avaliar comerciais** apenas se:
- Volume de consultas for muito alto (rate limit das públicas)
- Precisar de inteiro teor fácil (sem scraping)
- Jurimetria for diferencial competitivo essencial

---

## 🏗️ ARQUITETURA DE INTEGRAÇÃO NO V2

### **Estrutura Proposta:**

```
app/
├── public/
│   └── areas/
│       └── juridico/
│           ├── licitacoes.php (canvas PNCP)
│           ├── consulta-processual.php (canvas DataJud)
│           └── due-diligence.php (canvas CGU)
├── config/
│   └── apis.php (configuração centralizada)
└── src/
    └── Services/
        └── ExternalApiService.php (chamadas do PHP, se necessário)

services/ai/
├── routes/
│   └── juridico.py (endpoints FastAPI)
├── clients/
│   ├── pncp_client.py
│   ├── datajud_client.py
│   ├── cgu_client.py
│   └── escavador_client.py (opcional)
└── models/
    ├── licitacao.py (Pydantic models)
    └── processo.py
```

### **Fluxo de Integração:**

```
[Frontend PHP + SurveyJS]
         ↓
    (HTMX POST)
         ↓
[FastAPI - services/ai/routes/juridico.py]
         ↓
  (httpx.AsyncClient)
         ↓
[APIs Externas: PNCP, DataJud, CGU]
         ↓
     (JSON)
         ↓
[Processamento + Claude (opcional)]
         ↓
  (Markdown/HTML)
         ↓
[Retorno para Frontend via HTMX]
```

---

## 📋 ROADMAP DE IMPLEMENTAÇÃO

### **Fase 0: Preparação (3 dias)**

**Sprint Planning:**
- [ ] Definir prioridades finais com Filipe
- [ ] Obter chave CGU (cadastro de e-mail)
- [ ] Criar estrutura de diretórios

**Tarefas Técnicas:**
```bash
# services/ai/requirements.txt
httpx==0.27.0      # Cliente HTTP async
pydantic==2.10.0   # Validação de dados
python-dotenv==1.0.0

# Criar clients
mkdir -p services/ai/clients
touch services/ai/clients/pncp_client.py
touch services/ai/clients/datajud_client.py
touch services/ai/clients/cgu_client.py
```

**Configuração:**
```python
# services/ai/config.py

class Settings(BaseSettings):
    # APIs Públicas
    PNCP_BASE_URL: str = "https://pncp.gov.br/api/search/"
    DATAJUD_BASE_URL: str = "https://api-publica.datajud.cnj.jus.br"
    DATAJUD_API_KEY: str = "cDY2N2JhYzMtZTM5MS00YzZkLTlkNmMtYmQwZGQ0OWY0YjFjOmMwNGI0ODNlLTExZTQtNDY5NC1hNTE3LWYxNTJhZmYwMjM1NQ=="
    CGU_BASE_URL: str = "https://api.portaldatransparencia.gov.br"
    CGU_API_KEY: str  # Obter via cadastro
    
    # APIs Comerciais (opcional)
    ESCAVADOR_API_KEY: str | None = None
    JUIT_API_KEY: str | None = None
```

---

### **Fase 1: MVP - PNCP (5 dias) — P0**

**Sprint 1: Backend**
- [ ] Criar `PNCPClient` class
- [ ] Endpoint `/api/juridico/licitacoes/buscar`
- [ ] Testes unitários (mock da API)
- [ ] Documentação Swagger

**Sprint 2: Frontend**
- [ ] Canvas SurveyJS para licitações
- [ ] Integração HTMX
- [ ] Visualização de resultados (tabela Markdown)
- [ ] Testes E2E (Playwright)

**Entrega:**
✅ Usuário pode buscar editais no PNCP via formulário
✅ Resultados exibidos em tabela formatada
✅ Links diretos para cada edital

---

### **Fase 2: MVP - DataJud (7 dias) — P0**

**Sprint 3: Backend**
- [ ] Criar `DataJudClient` class
- [ ] Endpoint `/api/juridico/processos/consultar`
- [ ] Parser de queries ElasticSearch
- [ ] Formatação de metadados de processo

**Sprint 4: Frontend**
- [ ] Canvas SurveyJS para consulta processual
- [ ] Dropdown de tribunais (dinâmico)
- [ ] Exibição de andamento processual
- [ ] Testes E2E

**Desafio:** Inteiro teor das decisões.

**Opções:**
1. **MVP**: Exibir só metadados (classe, assunto, movimentações)
2. **v2**: Integrar Escavador/JUIT (pago)
3. **v3**: Scraping dos portais (complexo, frágil)

**Entrega:**
✅ Usuário pode consultar processo por número CNJ
✅ Usuário pode buscar processos por CPF/CNPJ
✅ Exibição de capa do processo + últimas movimentações

---

### **Fase 3: MVP - CGU (3 dias) — P1**

**Sprint 5: Backend + Frontend**
- [ ] Criar `CGUClient` class
- [ ] Endpoint `/api/juridico/due-diligence/verificar-sancoes`
- [ ] Melhorar canvas de Due Diligence (adicionar seção)
- [ ] Botão HTMX "Verificar Sanções"
- [ ] Resultado inline (✅ limpo ou ⚠️ alerta)

**Entrega:**
✅ Verificação de sanções CEIS/CNEP em 1 clique
✅ Resultado exibido inline no formulário
✅ Dados incluídos na análise final do Claude

---

### **Fase 4: RAG para Jurisprudência (10 dias) — P1**

**Sprint 6: RAG Backend**
- [ ] Endpoint `/api/juridico/jurisprudencia/pesquisar-rag`
- [ ] Integração DataJud (recuperação de processos)
- [ ] Parser de inteiro teor (se API comercial ou scraping)
- [ ] Lógica RAG (inserir docs no contexto Claude)
- [ ] Protocolo anti-alucinação

**Sprint 7: Frontend**
- [ ] Melhorar canvas de Jurisprudência
- [ ] Checkbox "Protocolo de Verificação"
- [ ] Exibição de fontes (links verificáveis)
- [ ] Disclaimer de validação humana

**Entrega:**
✅ Pesquisa de jurisprudência com RAG
✅ Claude cita fontes completas
✅ Links para verificar cada julgado no portal
✅ Disclaimer de verificação obrigatória

---

### **Fase 5: Refinamento e Escala (contínuo)**

**Features Adicionais:**
- [ ] **Alertas automáticos** (webhook quando novo edital PNCP)
- [ ] **Dashboard de licitações** (oportunidades ativas)
- [ ] **Monitoramento de processos** (alertas de movimentação)
- [ ] **Jurimetria** (análise estatística de jurisprudência)
- [ ] **Cache Redis** (evitar re-consultas caras)
- [ ] **Rate limiting** (proteger contra abuso)

**APIs Comerciais (se necessário):**
- [ ] Avaliar Escavador (pricing, trial)
- [ ] Avaliar JUIT (pricing, trial)
- [ ] Implementar fallback (público → comercial se limite atingido)

---

## 🎯 CRITÉRIOS DE ACEITE

### **MVP (Fase 1-3 completa):**

**Funcional:**
- [ ] Busca de licitações PNCP funcionando
- [ ] Consulta de processos DataJud funcionando
- [ ] Verificação de sanções CGU funcionando
- [ ] Resultados exibidos corretamente (formatação, links)

**Performance:**
- [ ] Latência < 5s para PNCP
- [ ] Latência < 10s para DataJud
- [ ] Latência < 3s para CGU

**Qualidade:**
- [ ] Zero vulnerabilidades (CSRF, XSS)
- [ ] Testes E2E passando (Playwright)
- [ ] Documentação Swagger completa
- [ ] Error handling robusto (timeout, API down)

**UX:**
- [ ] Loading indicators (HTMX)
- [ ] Mensagens de erro claras
- [ ] Links funcionais para portais externos

---

## 💡 RECOMENDAÇÕES FINAIS

### **Priorização (minha visão):**

| Fase | API | Prazo | ROI | Dificuldade |
|------|-----|-------|-----|-------------|
| **1** | PNCP | 5 dias | ⭐⭐⭐⭐⭐ | ⭐ Fácil |
| **2** | DataJud | 7 dias | ⭐⭐⭐⭐⭐ | ⭐⭐ Médio |
| **3** | CGU | 3 dias | ⭐⭐⭐⭐ | ⭐ Fácil |
| **4** | RAG Jurisprudência | 10 dias | ⭐⭐⭐⭐ | ⭐⭐⭐ Difícil |
| **5** | Escavador (comercial) | TBD | ⭐⭐⭐ | ⭐⭐ Médio |

**Total MVP:** ~25 dias úteis (5 semanas)

### **Concordo com Manus:**
✅ Iniciar por **PNCP** (mais simples, alta demanda)

### **Minha sugestão adicional:**
✅ Fazer **PNCP + DataJud em paralelo** (times diferentes):
- **Claude**: PNCP (backend + frontend)
- **Codex**: DataJud (backend + frontend)
- **Copilot**: Testes E2E + Code Review

Resultado: MVP em **2 semanas** em vez de 3.

### **Sobre APIs Comerciais:**
🟡 **Aguardar feedback dos usuários** antes de pagar:
- Se advogados reclamarem que DataJud é insuficiente → contratar Escavador
- Se pedirem jurimetria → contratar JUIT
- Se volume de consultas atingir rate limit → migrar para pago

### **Sobre RAG:**
⚠️ **Crítico implementar corretamente**:
- Não é só "jogar docs no Claude"
- Precisa de **protocolo de verificação** rigoroso
- Disclaimer legal é **obrigatório**
- Validação humana é **essencial**

---

## 🚀 PRÓXIMOS PASSOS IMEDIATOS

1. **Filipe decide priorização** (concordar com roadmap?)
2. **Obter chave CGU** (cadastro de e-mail)
3. **Criar estrutura de diretórios** (clients, routes)
4. **Claude inicia Sprint 1** (PNCP backend)
5. **Codex inicia Sprint 3** (DataJud backend) — em paralelo
6. **Copilot prepara testes** (Playwright setup)

**Estimativa total:**
- MVP completo: **3-5 semanas**
- ROI: Alto (automatiza horas de trabalho manual)
- Custo: **$0** (só APIs públicas)
- Risco: Baixo (APIs estáveis, governamentais)

---

**Copilot**  
QA Frontend & Testes  
Plataforma Sunyata v2

**Documentos Analisados:**
- ✅ Proposta de Integração de APIs Jurídicas (Manus AI)
- ✅ Relatório de Automação e Pesquisa Jurídica (Manus AI)

**Anexos:**
- Roadmap detalhado (5 fases)
- Exemplos de código (Python FastAPI)
- Especificações de APIs
- Análise de custos
