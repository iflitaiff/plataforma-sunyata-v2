# DataJud Integration — Design Document

**Data:** 2026-02-24
**Status:** Aprovado
**Autor:** Claude (Code) + Filipe

---

## Objetivo

Integrar a API pública do DataJud (CNJ) ao sistema de análise de editais PNCP/IATR para enriquecer a plataforma com dados judiciais. Três features:

1. **Due Diligence do Órgão** — Exibir histórico judicial do órgão licitante na página do edital
2. **Risco Judicial no Resumo Executivo** — Injetar contexto DataJud no prompt de IA para análise de risco enriquecida
3. **Verificação de Idoneidade** — Consultar processos judiciais de uma empresa (CNPJ informado pelo usuário) para avaliar riscos de habilitação

---

## API DataJud — Resumo Técnico

- **Base:** `POST https://api-publica.datajud.cnj.jus.br/api_publica_{tribunal}/_search`
- **Auth:** `Authorization: APIKey cDZHYzlZa0JadVREZDJCendQbXY6SkJlTzNjLV9TRENyQk1RdnFKZGRQdw==` (pública, sem cadastro)
- **Query:** Elasticsearch DSL (bool, match, range, search_after)
- **Dados:** Metadados de processos judiciais públicos — número, classe, assuntos, tribunal, órgão julgador, data de ajuizamento, movimentações
- **Organização:** Um endpoint por tribunal (91 tribunais no total)
- **Sem rate limit documentado.** Chave pode mudar sem aviso.

---

## Decisões de Arquitetura

| Decisão | Escolha | Justificativa |
|---------|---------|---------------|
| Camada de integração | **FastAPI** (não N8N) | Python melhor para Elasticsearch DSL, queries paralelas com httpx/asyncio, parsing complexo |
| Cache | **JSONB no PostgreSQL, 24h** | DataJud não muda em tempo real; evita rate limit desconhecido |
| Resolução de CNPJ do órgão | **Extrair dos 14 primeiros dígitos do `pncp_id`** | Coluna `orgao_cnpj` está vazia em todos os registros; `pncp_id` formato `{CNPJ}-{esfera}-{seq}/{ano}` é confiável |
| CNPJ da empresa (Feature 3) | **Input manual do usuário** | Cliente é holding com múltiplas empresas; cadastro de perfil seria over-engineering agora |
| Tribunais consultados | **UF do edital + superiores (4-6 queries)** | Pragmático: cobre 95%+ dos casos sem consultar 91 tribunais |
| Classes processuais | **Pré-definidas + busca aberta limitada** | Captura o esperado e descobre o inesperado |
| Feature 2 (prompt) | **Injeta texto plano no user message** | Zero mudança na UI; IA decide o peso da informação |

---

## Modelo de Dados

### Migration 015: Novas colunas em `pncp_editais`

```sql
ALTER TABLE pncp_editais ADD COLUMN datajud_orgao JSONB;
ALTER TABLE pncp_editais ADD COLUMN datajud_consultado_em TIMESTAMPTZ;
```

### Migration 015: Nova tabela `datajud_consultas`

```sql
CREATE TABLE datajud_consultas (
    id SERIAL PRIMARY KEY,
    cnpj TEXT NOT NULL,
    user_id INTEGER REFERENCES users(id),
    edital_id INTEGER REFERENCES pncp_editais(id),
    resultado JSONB NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX idx_datajud_consultas_cnpj ON datajud_consultas(cnpj);
CREATE INDEX idx_datajud_consultas_user ON datajud_consultas(user_id);
```

### Estrutura JSONB `datajud_orgao` / `resultado`

```json
{
  "cnpj": "66831959000187",
  "total_processos": 47,
  "consulta_timestamp": "2026-02-24T14:30:00Z",
  "tribunais_consultados": ["TJSP", "TRF3", "TRT2"],
  "processos": [
    {
      "numero": "00012345620251234567",
      "classe": {"codigo": 1116, "nome": "Execução Fiscal"},
      "tribunal": "TJSP",
      "orgao_julgador": "1a Vara Civel de Sao Paulo",
      "data_ajuizamento": "2025-06-15",
      "assuntos": [{"codigo": 123, "nome": "Divida Ativa"}],
      "ultima_movimentacao": {"data": "2026-01-10", "nome": "Citacao"},
      "grau": "G1"
    }
  ],
  "resumo": {
    "por_classe": {"Execucao Fiscal": 12, "Mandado de Seguranca": 5},
    "por_tribunal": {"TJSP": 20, "TRF3": 15},
    "mais_recente": "2026-01-10",
    "mais_antigo": "2020-03-15"
  }
}
```

---

## Endpoints FastAPI

### `POST /api/ai/datajud/orgao-processos`

Busca processos judiciais do órgão licitante. Usado por Features 1 e 2.

**Request:**
```json
{
  "edital_id": 84,
  "cnpj": "66831959000187",
  "force_refresh": false
}
```
- Ao menos um de `edital_id` ou `cnpj` obrigatório
- Se `edital_id`: extrai CNPJ do `pncp_id` e salva cache em `pncp_editais.datajud_orgao`

**Response:**
```json
{
  "cnpj": "66831959000187",
  "orgao": "MUNICIPIO DE SALTINHO",
  "total_processos": 47,
  "tribunais_consultados": ["TJSP", "TRF3", "TRT15"],
  "resumo": { "por_classe": {}, "por_tribunal": {}, "mais_recente": "", "mais_antigo": "" },
  "processos": [],
  "cached": false,
  "consultado_em": "2026-02-24T14:30:00Z"
}
```

### `POST /api/ai/datajud/empresa-idoneidade`

Verifica idoneidade judicial de uma empresa. Usado por Feature 3.

**Request:**
```json
{
  "cnpj": "12345678000199",
  "edital_id": 84,
  "uf_prioridade": "SP"
}
```

**Response:**
```json
{
  "cnpj": "12345678000199",
  "total_processos": 8,
  "alertas": [
    { "tipo": "CRITICO", "descricao": "Processo de Falencia encontrado", "processo": {} },
    { "tipo": "ATENCAO", "descricao": "3 Execucoes Fiscais ativas", "processos": [] }
  ],
  "resumo": {},
  "processos": [],
  "cached": false,
  "consultado_em": "2026-02-24T14:30:00Z"
}
```

### Mapeamento UF -> Tribunais

```python
UF_TRIBUNAIS = {
    "SP": ["TJSP", "TRF3", "TRT2", "TRT15"],
    "RJ": ["TJRJ", "TRF2", "TRT1"],
    "MG": ["TJMG", "TRF1", "TRT3"],
    "DF": ["TJDFT", "TRF1", "TRT10"],
    # ... 27 UFs
}
TRIBUNAIS_SUPERIORES = ["STJ", "TST"]
```

### Classes processuais

```python
CLASSES_ORGAO = [1116, 1117, 12078, 65, 12135]   # Due Diligence
CLASSES_EMPRESA = [1037, 1049, 1116, 1117]         # Idoneidade
```

---

## Frontend (edital.php)

### Feature 1: Historico Judicial do Orgao

- Carrega automaticamente ao abrir a pagina (fetch ao proxy)
- Cards com contadores por classe processual
- Secao expansivel com tabela de processos detalhados
- Botao "Atualizar" para forcar refresh
- Se zero processos: mensagem positiva

### Feature 3: Verificar Idoneidade de Empresa

- Campo CNPJ com mascara (XX.XXX.XXX/XXXX-XX)
- Validacao client-side do digito verificador
- Resultado com alertas visuais:
  - CRITICO (vermelho): Falencia, Recuperacao Judicial
  - ATENCAO (amarelo): Execucoes Fiscais, Trabalhistas
  - INFO (azul): Demais processos
- Cada alerta expansivel para detalhes

### Feature 2: Enriquecimento do Prompt (invisivel)

No N8N workflow "IATR Analise v2", node "Build Analysis Prompt":
- Se `datajud_orgao` existe no banco, adiciona bloco ao user message:
```
--- CONTEXTO JUDICIAL DO ORGAO (DataJud/CNJ) ---
O orgao licitante possui N processos judiciais registrados.
Distribuicao por tipo: ...
Considere estas informacoes ao avaliar riscos.
```

### Proxies PHP (same-origin)

- `app/public/api/datajud/orgao-processos.php` — session + CSRF -> FastAPI
- `app/public/api/datajud/empresa-idoneidade.php` — session + CSRF + CNPJ -> FastAPI

---

## Inventario de Mudancas

| Camada | Arquivo | Tipo |
|--------|---------|------|
| DB | Migration 015 | Novo |
| FastAPI | `routers/datajud.py` | Novo |
| FastAPI | `main.py` | Modificado |
| Portal | `api/datajud/orgao-processos.php` | Novo |
| Portal | `api/datajud/empresa-idoneidade.php` | Novo |
| Portal | `areas/iatr/edital.php` | Modificado |
| Portal | `areas/licitacoes/edital.php` | Modificado |
| N8N | Workflow IATR Analise v2 | Modificado (node Build Prompt) |
| Docs | `DATABASE.md` | Modificado |
| Docs | `CLAUDE.md` | Modificado |

## Fora do Escopo (YAGNI)

- Monitoramento continuo de processos
- Jurisprudencia para recursos
- Dashboard de processos judiciais
- Notificacoes de novos processos
- Busca por nome de parte (so CNPJ)
- Formatacao do numero do processo
- Cadastro de perfil de empresa
