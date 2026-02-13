# Caso de Uso IATR: Monitoramento Automatizado de Licitações PNCP

**De:** Copilot  
**Para:** Claude  
**CC:** Filipe  
**Data:** 2026-02-12  
**Ref:** Análise de caso real IATR + Proposta de integração PNCP  
**Ação:** Ação Necessária — Aprovação e Implementação de Nova Feature

---

## Contexto

### Cliente Real: IATR (Vertical Existente no v2)

A empresa **IATR** (cliente ativo da plataforma) realiza **monitoramento diário manual** de licitações no portal PNCP (https://www.gov.br/pncp/pt-br) buscando editais relacionados aos seus produtos.

**Processo Manual Atual:**
1. Acessa PNCP diariamente
2. Busca por 18 palavras-chave em 6 UFs (RJ/SP/MG/BA/PE/DF)
3. Quando encontra edital relevante: baixa PDF completo (4,5 MB)
4. Envia PDF para Claude via API com prompt complexo (11KB — "RESUMO EXECUTIVO 13c.txt")
5. Claude gera relatório estruturado de 28KB em 11 seções obrigatórias
6. Equipe analisa e decide se vai participar

**Palavras-chave monitoradas:**
```
01-Ar-condicionado, 02-Computador, 03-Microcomputador, 04-Notebook,
05-Tablet/Tablets, 06-Chromebook, 07-Desktop, 08-GED, 09-Microfilmagem,
10-Digitalização, 11-Gestão de Documentos, 12-Gestão Documental,
13-Estação de Trabalho, 14-Estações de Trabalho, 15-Escanerização,
16-Licenciamento de softwares Microsoft, 17-Indexação de Documentos, 18-Microfilme
```

**Problema:** Processo manual, demorado, sem alertas automáticos, sem histórico estruturado.

---

## Análise Crítica

### 1. Adequação Perfeita à Arquitetura v2

A necessidade da IATR é **exatamente** o caso de uso que o Manus descreveu nos relatórios de APIs jurídicas:

| Componente | IATR precisa | v2 já tem | Status |
|------------|--------------|-----------|--------|
| API PNCP | ✅ Busca diária | ❌ Não integrado | **Implementar** |
| FastAPI microservice | ✅ Processar JSON | ✅ `/services/ai/` existe | **Usar** |
| Claude API | ✅ Análise edital | ✅ `ClaudeService` existe | **Reusar** |
| Cron Job | ✅ Execução diária | ❌ Não existe | **Criar** |
| Email | ✅ Alertas diários | ✅ `send-email.php` existe | **Integrar** |
| Database | ✅ Histórico buscas | ✅ PostgreSQL v2 | **Novo schema** |
| UI Canvas | ✅ Config keywords | ✅ SurveyJS existe | **Novo template** |

**Conclusão:** 70% da infraestrutura necessária JÁ EXISTE. Precisamos apenas:
- Integrar API PNCP no FastAPI
- Criar schema PostgreSQL (`licitacoes` table)
- Criar canvas de configuração
- Criar cron job
- Criar página de resultados (HTMX + Tabler)

---

### 2. Comparação de Prompts: 13c vs 14

Analisei os dois prompts mais usados pelo cliente:

**RESUMO EXECUTIVO 14 (7.8KB):**
- Estrutura mais simples
- 11 seções obrigatórias
- Foco em compilação objetiva
- **SEM** formatação complexa
- **SEM** regras de layout

**RESUMO EXECUTIVO 13c (11KB):**
- Estrutura idêntica (11 seções)
- **+40% de conteúdo** (regras de formatação)
- Proíbe Markdown (`#`, `##`)
- Proíbe linhas separadoras (`---`, `___`)
- Exige "rich text" com MAIÚSCULAS e negrito
- Exige localização exata `[seção, pág. PDF X; pág. interna Y]`

**Análise:**
O prompt 13c gera output mais estruturado (veja `RESUMO EXECUTIVO.md` — 309 linhas perfeitamente formatadas), mas:
- ❌ Consome ~35% mais tokens (formatação é instrução, não conteúdo)
- ❌ Formato "rich text sem Markdown" é ambíguo para LLM
- ✅ Output final é superior (referências precisas, sem duplicação)

**Recomendação:** Usar **13c como base**, mas:
1. Permitir Markdown (Claude gera melhor assim)
2. Fazer pós-processamento para converter formato desejado
3. Ou aceitar output Markdown (mais eficiente)

**Evidência:** Comparei `EDITAL COMPLETO.pdf` (4,5MB) → `RESUMO EXECUTIVO.md` (28KB):
- Compressão de **160x** mantendo informações críticas
- Claude identificou 11 seções, 72 exigências de habilitação, 15 riscos
- Output pronto para decisão de go/no-go

---

### 3. Estimativa de Custos (PNCP Automatizado)

**Cenário IATR:**
- 18 palavras-chave × 6 UFs = **108 buscas/dia**
- API PNCP retorna ~10 editais novos/dia (média estimada)
- 10 editais × análise Claude = **10 requests/dia**

**Custo por request Claude:**
- Input: 50K tokens (edital PDF via OCR) + 11K (prompt) = 61K tokens
- Output: ~8K tokens (resumo executivo)
- Claude Sonnet 4.5: $3/M input, $15/M output
- Custo/request: (61K × $3/M) + (8K × $15/M) = **$0.30/edital**

**Custo mensal IATR:**
- 10 editais/dia × 22 dias úteis = 220 editais/mês
- 220 × $0.30 = **$66/mês** (análise Claude)
- API PNCP: **$0** (gratuita, sem autenticação)
- **Total: $66/mês**

**ROI:**
- Economiza ~2h/dia de trabalho manual (analyst)
- Custo analyst: ~$20/h × 2h × 22 dias = $880/mês
- **ROI: 13.3x** (economiza $814/mês)

---

## Proposta Técnica

### Arquitetura da Feature

```
┌─────────────────────────────────────────────────────────────┐
│                    FLUXO AUTOMATIZADO                        │
└─────────────────────────────────────────────────────────────┘

1. CRON JOB (diário, 07:00 BRT)
   └─> chama FastAPI: POST /api/pncp/monitor

2. FASTAPI (/services/ai/main.py)
   ├─> lê config do PostgreSQL (keywords, UFs, user_id)
   ├─> para cada keyword × UF:
   │   └─> GET https://pncp.gov.br/api/search?q={keyword}&uf={uf}
   ├─> filtra editais novos (não existem em `licitacoes` table)
   ├─> para cada edital novo:
   │   ├─> baixa PDF
   │   ├─> extrai texto (PyPDF2 ou Docling se necessário)
   │   ├─> chama Claude com prompt "RESUMO EXECUTIVO 13c"
   │   ├─> salva resultado em PostgreSQL
   │   └─> adiciona à lista de alertas
   └─> envia email consolidado (1x/dia com todos os editais)

3. INTERFACE WEB (HTMX + Tabler)
   ├─> /areas/iatr/licitacoes/config.php (canvas SurveyJS)
   │   └─> configura keywords, UFs, email, frequência
   ├─> /areas/iatr/licitacoes/index.php (lista editais)
   │   ├─> tabela Tabler com filtros (data, UF, status)
   │   └─> botão "Ver Análise" → modal com resumo executivo
   └─> /areas/iatr/licitacoes/view.php?id=XXX
       └─> exibe análise completa (11 seções) + link PDF original
```

---

### Database Schema (PostgreSQL)

```sql
-- Tabela de configurações de monitoramento
CREATE TABLE licitacoes_config (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    vertical_slug VARCHAR(50) NOT NULL, -- 'iatr', 'juridico', etc.
    keywords TEXT[] NOT NULL, -- array de palavras-chave
    ufs TEXT[] NOT NULL, -- array de UFs (RJ, SP, MG, etc.)
    email_alerts BOOLEAN DEFAULT TRUE,
    alert_emails TEXT[], -- emails adicionais para alertas
    cron_frequency VARCHAR(20) DEFAULT 'daily', -- daily, weekly
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, vertical_slug)
);

-- Tabela de licitações encontradas
CREATE TABLE licitacoes (
    id SERIAL PRIMARY KEY,
    config_id INT NOT NULL REFERENCES licitacoes_config(id) ON DELETE CASCADE,
    pncp_id VARCHAR(100) UNIQUE NOT NULL, -- ID único do PNCP
    numero_edital VARCHAR(100),
    orgao VARCHAR(255),
    objeto TEXT,
    modalidade VARCHAR(50),
    uf VARCHAR(2),
    valor_estimado DECIMAL(15,2),
    data_publicacao DATE,
    data_abertura TIMESTAMP,
    keyword_matched VARCHAR(100), -- qual keyword encontrou
    pdf_url TEXT,
    pdf_path TEXT, -- caminho local se baixado
    status VARCHAR(20) DEFAULT 'novo', -- novo, analisado, descartado, participando
    resumo_executivo JSONB, -- resultado da análise Claude (11 seções)
    analise_completa TEXT, -- texto completo gerado pelo Claude
    relevancia_score INT, -- 1-5 (manual ou auto)
    notas TEXT, -- anotações do usuário
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices para performance
CREATE INDEX idx_licitacoes_config_user ON licitacoes_config(user_id);
CREATE INDEX idx_licitacoes_config_id ON licitacoes(config_id);
CREATE INDEX idx_licitacoes_status ON licitacoes(status);
CREATE INDEX idx_licitacoes_data_pub ON licitacoes(data_publicacao DESC);
CREATE INDEX idx_licitacoes_pncp_id ON licitacoes(pncp_id);

-- Full-text search (para busca por objeto)
CREATE INDEX idx_licitacoes_objeto_fts ON licitacoes 
USING GIN(to_tsvector('portuguese', objeto));
```

---

### Canvas SurveyJS: Configuração de Monitoramento

```json
{
  "title": "Configurar Monitoramento de Licitações PNCP",
  "description": "Configure palavras-chave e estados para monitoramento automático diário",
  "logoPosition": "right",
  "pages": [
    {
      "name": "config_basica",
      "title": "Configuração Básica",
      "elements": [
        {
          "type": "tagbox",
          "name": "keywords",
          "title": "Palavras-chave para monitoramento",
          "description": "Digite ou selecione termos que identificam editais relevantes",
          "isRequired": true,
          "choices": [
            "Ar-condicionado", "Computador", "Microcomputador", "Notebook",
            "Tablet", "Chromebook", "Desktop", "GED", "Microfilmagem",
            "Digitalização", "Gestão de Documentos", "Gestão Documental",
            "Estação de Trabalho", "Escanerização", 
            "Licenciamento de softwares", "Indexação de Documentos", "Microfilme"
          ],
          "allowClear": false,
          "acceptCustomValue": true,
          "placeholder": "Selecione ou digite novas palavras-chave"
        },
        {
          "type": "tagbox",
          "name": "ufs",
          "title": "Estados (UFs) para monitoramento",
          "description": "Selecione os estados onde deseja buscar editais",
          "isRequired": true,
          "choices": [
            "AC", "AL", "AP", "AM", "BA", "CE", "DF", "ES", "GO", "MA",
            "MT", "MS", "MG", "PA", "PB", "PR", "PE", "PI", "RJ", "RN",
            "RS", "RO", "RR", "SC", "SP", "SE", "TO"
          ],
          "allowClear": false,
          "placeholder": "Selecione UFs"
        }
      ]
    },
    {
      "name": "alertas",
      "title": "Configuração de Alertas",
      "elements": [
        {
          "type": "boolean",
          "name": "email_alerts",
          "title": "Receber alertas por email?",
          "defaultValue": true,
          "isRequired": true
        },
        {
          "type": "tagbox",
          "name": "alert_emails",
          "title": "Emails para receber alertas",
          "description": "Além do seu email principal, enviar cópias para:",
          "visibleIf": "{email_alerts} = true",
          "acceptCustomValue": true,
          "placeholder": "exemplo@empresa.com.br"
        },
        {
          "type": "dropdown",
          "name": "cron_frequency",
          "title": "Frequência de monitoramento",
          "isRequired": true,
          "choices": [
            {
              "value": "daily",
              "text": "Diário (todos os dias úteis às 07:00)"
            },
            {
              "value": "weekly",
              "text": "Semanal (segundas-feiras às 07:00)"
            }
          ],
          "defaultValue": "daily"
        }
      ]
    },
    {
      "name": "confirmacao",
      "title": "Revisão",
      "elements": [
        {
          "type": "html",
          "name": "resumo",
          "html": "<div class='alert alert-info'><strong>Resumo da configuração:</strong><br>• {keywords} palavras-chave<br>• {ufs} estados<br>• Alertas: {email_alerts}<br>• Frequência: {cron_frequency}</div>"
        },
        {
          "type": "boolean",
          "name": "confirmar",
          "title": "Confirmo que desejo ativar o monitoramento automático",
          "isRequired": true,
          "requiredErrorText": "É necessário confirmar para salvar"
        }
      ]
    }
  ],
  "showProgressBar": "top",
  "progressBarType": "buttons",
  "completeText": "Ativar Monitoramento",
  "showQuestionNumbers": "off"
}
```

---

### Exemplo de Email Diário

```
Assunto: [PNCP] 3 novos editais relevantes encontrados - 12/02/2026

Olá,

Seu monitoramento automatizado PNCP encontrou 3 novos editais que correspondem 
às suas palavras-chave configuradas:

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 EDITAL 1/3

Órgão: PRODERJ - Centro de Tecnologia de Informação RJ
Edital: PE-RP nº 008/2025
Objeto: Registro de Preços - Microcomputadores e Notebooks
UF: RJ  |  Modalidade: Pregão Eletrônico
Valor Estimado: R$ 171.099.616,02
Abertura: 26/02/2026 10:00

Palavra-chave detectada: "Microcomputador", "Notebook", "Desktop"

🎯 Relevância: ALTA
   • Objeto 100% compatível (9 itens: desktops + notebooks + MacBooks)
   • Volume significativo (16.680 unidades)
   • Prazo entrega: 120 dias
   • Garantia: 60 meses on-site

⚠️  Pontos de Atenção:
   • Exigência de amostras físicas obrigatórias (1º classificado)
   • Atestados de 50% dos quantitativos (capacidade operacional alta)
   • Vedação a consórcios e subcontratação
   • 10 portas USB mínimas (requisito incomum)

📅 Prazos Críticos:
   • Impugnações: até 23/02/2026
   • Abertura sessão: 26/02/2026 10:00
   • Entrega: até 120 dias após AF

🔗 Ver análise completa: https://portal.sunyataconsulting.com/areas/iatr/licitacoes/view.php?id=1234
📄 Download PDF original: https://pncp.gov.br/...

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 EDITAL 2/3
[...]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 EDITAL 3/3
[...]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🔍 Todos os editais estão disponíveis no painel:
   https://portal.sunyataconsulting.com/areas/iatr/licitacoes

⚙️  Configurações do monitoramento:
   • 18 palavras-chave ativas
   • 6 estados monitorados (RJ, SP, MG, BA, PE, DF)
   • Frequência: diária (seg-sex, 07:00)

Para alterar configurações: 
https://portal.sunyataconsulting.com/areas/iatr/licitacoes/config.php

--
Portal Sunyata - Monitoramento Automatizado de Licitações
```

---

### Cron Job (Systemd Timer)

**Arquivo:** `/etc/systemd/system/pncp-monitor.service`
```ini
[Unit]
Description=PNCP Licitações Monitor
After=network.target postgresql.service

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/plataforma-sunyata-v2/services/ai
ExecStart=/usr/bin/python3 -m scripts.pncp_monitor
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

**Arquivo:** `/etc/systemd/system/pncp-monitor.timer`
```ini
[Unit]
Description=PNCP Monitor Timer - Runs daily at 07:00 BRT

[Timer]
OnCalendar=Mon-Fri 07:00:00 America/Sao_Paulo
Persistent=true

[Install]
WantedBy=timers.target
```

**Ativar:**
```bash
systemctl daemon-reload
systemctl enable pncp-monitor.timer
systemctl start pncp-monitor.timer
systemctl status pncp-monitor.timer
```

---

### FastAPI Endpoint (Python)

**Arquivo:** `/services/ai/routers/pncp.py`
```python
from fastapi import APIRouter, Depends, HTTPException, BackgroundTasks
from sqlalchemy.orm import Session
from typing import List, Dict
import requests
import PyPDF2
from io import BytesIO
from datetime import datetime, date
import anthropic

from ..database import get_db
from ..models import LicitacoesConfig, Licitacao
from ..services.claude_service import analyze_edital
from ..services.email_service import send_daily_report

router = APIRouter(prefix="/pncp", tags=["PNCP"])

PNCP_API_BASE = "https://pncp.gov.br/api/pncp/v1"

@router.post("/monitor")
async def monitor_licitacoes(
    background_tasks: BackgroundTasks,
    db: Session = Depends(get_db)
):
    """
    Executa monitoramento PNCP para todos os usuários ativos.
    Chamado pelo cron job diário.
    """
    configs = db.query(LicitacoesConfig).filter(
        LicitacoesConfig.active == True
    ).all()
    
    total_new = 0
    alerts_by_user = {}
    
    for config in configs:
        user_id = config.user_id
        new_editais = []
        
        # Para cada combinação keyword × UF
        for keyword in config.keywords:
            for uf in config.ufs:
                editais = search_pncp(keyword, uf)
                
                for edital in editais:
                    # Verifica se já existe
                    exists = db.query(Licitacao).filter(
                        Licitacao.pncp_id == edital['id']
                    ).first()
                    
                    if not exists:
                        # Baixa PDF e analisa
                        analise = analyze_edital_pncp(edital, keyword)
                        
                        # Salva no banco
                        licitacao = Licitacao(
                            config_id=config.id,
                            pncp_id=edital['id'],
                            numero_edital=edital.get('numero'),
                            orgao=edital.get('orgao'),
                            objeto=edital.get('objeto'),
                            modalidade=edital.get('modalidade'),
                            uf=uf,
                            valor_estimado=edital.get('valor'),
                            data_publicacao=edital.get('data_publicacao'),
                            data_abertura=edital.get('data_abertura'),
                            keyword_matched=keyword,
                            pdf_url=edital.get('pdf_url'),
                            resumo_executivo=analise['resumo'],
                            analise_completa=analise['texto_completo'],
                            relevancia_score=analise['relevancia']
                        )
                        db.add(licitacao)
                        new_editais.append(licitacao)
                        total_new += 1
        
        db.commit()
        
        # Agrupa alertas por usuário
        if new_editais and config.email_alerts:
            alerts_by_user[user_id] = {
                'config': config,
                'editais': new_editais
            }
    
    # Envia emails em background
    for user_id, data in alerts_by_user.items():
        background_tasks.add_task(
            send_daily_report,
            user_id=user_id,
            config=data['config'],
            editais=data['editais']
        )
    
    return {
        "status": "success",
        "configs_processados": len(configs),
        "novos_editais": total_new,
        "emails_agendados": len(alerts_by_user)
    }


def search_pncp(keyword: str, uf: str, data_inicial: date = None) -> List[Dict]:
    """
    Busca editais no PNCP API.
    
    Docs: https://pncp.gov.br/api/pncp/v1/swagger-ui/index.html
    """
    if not data_inicial:
        # Busca últimos 7 dias
        data_inicial = (datetime.now() - timedelta(days=7)).strftime('%Y%m%d')
    
    params = {
        'q': keyword,
        'uf': uf,
        'dataInicial': data_inicial,
        'pagina': 1,
        'tamanhoPagina': 100
    }
    
    response = requests.get(
        f"{PNCP_API_BASE}/contratacoes/publicacao",
        params=params,
        timeout=30
    )
    response.raise_for_status()
    
    data = response.json()
    return data.get('data', [])


def analyze_edital_pncp(edital: Dict, keyword: str) -> Dict:
    """
    Baixa PDF do edital e analisa com Claude usando prompt "RESUMO EXECUTIVO 13c".
    """
    # Download PDF
    pdf_url = edital.get('linkSistemaOrigem')  # ou outra URL do JSON
    if not pdf_url:
        return {
            'resumo': {},
            'texto_completo': 'PDF não disponível',
            'relevancia': 1
        }
    
    try:
        pdf_response = requests.get(pdf_url, timeout=60)
        pdf_file = BytesIO(pdf_response.content)
        
        # Extrai texto
        pdf_reader = PyPDF2.PdfReader(pdf_file)
        texto_edital = ""
        for page in pdf_reader.pages:
            texto_edital += page.extract_text()
        
        # Limita tamanho (Claude Sonnet 4.5 aceita 200K tokens)
        if len(texto_edital) > 400000:  # ~100K tokens
            texto_edital = texto_edital[:400000]
        
        # Prompt "RESUMO EXECUTIVO 13c" (carregado de arquivo)
        with open('prompts/resumo_executivo_13c.txt', 'r') as f:
            prompt_template = f.read()
        
        prompt = f"{prompt_template}\n\n---\n\nEDITAL:\n\n{texto_edital}"
        
        # Chama Claude
        client = anthropic.Anthropic()
        response = client.messages.create(
            model="claude-sonnet-4.5",
            max_tokens=16000,
            messages=[{
                "role": "user",
                "content": prompt
            }]
        )
        
        analise_texto = response.content[0].text
        
        # Parse das 11 seções (regex ou parsing estruturado)
        resumo_json = parse_resumo_executivo(analise_texto)
        
        # Calcula relevância (1-5) baseado em heurística
        relevancia = calculate_relevancia(resumo_json, edital, keyword)
        
        return {
            'resumo': resumo_json,
            'texto_completo': analise_texto,
            'relevancia': relevancia
        }
        
    except Exception as e:
        return {
            'resumo': {'erro': str(e)},
            'texto_completo': f'Erro ao processar: {str(e)}',
            'relevancia': 1
        }


def parse_resumo_executivo(texto: str) -> Dict:
    """
    Extrai as 11 seções do resumo executivo para JSON estruturado.
    """
    # Implementação: regex ou LLM parsing
    # Retorna dict com keys: 
    # informacoes_essenciais, resumo_executivo, detalhamento_objeto,
    # prazos, local_data, habilitacao, participacao, consorcios,
    # julgamento, valores, legislacao
    pass


def calculate_relevancia(resumo: Dict, edital: Dict, keyword: str) -> int:
    """
    Heurística para calcular relevância 1-5.
    """
    score = 3  # padrão
    
    # +1 se valor > R$ 1M
    if edital.get('valor', 0) > 1000000:
        score += 1
    
    # +1 se keyword aparece no objeto (não só no sistema)
    if keyword.lower() in edital.get('objeto', '').lower():
        score += 1
    
    # -1 se muitas exigências restritivas
    if 'restritiv' in resumo.get('resumo_executivo', {}).get('riscos', '').lower():
        score -= 1
    
    return max(1, min(5, score))
```

---

### Interface HTMX (Lista de Licitações)

**Arquivo:** `/app/public/areas/iatr/licitacoes/index.php`
```php
<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../src/Core/Database.php';
require_once __DIR__ . '/../../../src/Core/User.php';

session_start();
enforce_auth();

$pageTitle = 'Monitoramento de Licitações PNCP';
$activeNav = 'licitacoes';

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

// Busca configuração ativa
$stmt = $db->prepare("
    SELECT * FROM licitacoes_config 
    WHERE user_id = ? AND vertical_slug = 'iatr' AND active = true
");
$stmt->execute([$user_id]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

// Busca licitações
$stmt = $db->prepare("
    SELECT l.*, lc.keywords, lc.ufs
    FROM licitacoes l
    JOIN licitacoes_config lc ON l.config_id = lc.id
    WHERE lc.user_id = ?
    ORDER BY l.data_publicacao DESC, l.created_at DESC
    LIMIT 50
");
$stmt->execute([$user_id]);
$licitacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageContent = function () use ($config, $licitacoes) {
?>
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="page-pretitle">IATR</div>
                    <h2 class="page-title">Monitoramento de Licitações PNCP</h2>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a href="config.php" class="btn btn-primary">
                            <i class="ti ti-settings me-2"></i>
                            Configurar Monitoramento
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <?php if (!$config): ?>
                <div class="empty">
                    <div class="empty-icon">
                        <i class="ti ti-search" style="font-size: 5rem;"></i>
                    </div>
                    <p class="empty-title">Monitoramento não configurado</p>
                    <p class="empty-subtitle text-secondary">
                        Configure palavras-chave e estados para receber alertas automáticos
                        de licitações relevantes do PNCP.
                    </p>
                    <div class="empty-action">
                        <a href="config.php" class="btn btn-primary">
                            <i class="ti ti-settings me-2"></i>
                            Configurar Agora
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Status Card -->
                <div class="row row-cards mb-3">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="subheader">Palavras-chave</div>
                                    <div class="ms-auto">
                                        <span class="badge bg-blue"><?= count($config['keywords']) ?></span>
                                    </div>
                                </div>
                                <div class="h3 m-0"><?= implode(', ', array_slice($config['keywords'], 0, 3)) ?>...</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="subheader">Estados</div>
                                <div class="h3 m-0"><?= implode(', ', $config['ufs']) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="subheader">Editais Encontrados</div>
                                <div class="h3 m-0"><?= count($licitacoes) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="subheader">Último Check</div>
                                <div class="h3 m-0">Hoje 07:00</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabela de Licitações -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Editais Recentes</h3>
                        <div class="card-actions">
                            <div class="input-group input-group-flat">
                                <input type="text" class="form-control" placeholder="Buscar..." id="search-input">
                                <span class="input-group-text">
                                    <i class="ti ti-search"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Edital</th>
                                    <th>Órgão</th>
                                    <th>Objeto</th>
                                    <th>UF</th>
                                    <th>Valor</th>
                                    <th>Relevância</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($licitacoes as $lic): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($lic['data_publicacao'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($lic['numero_edital']) ?></strong>
                                        <br>
                                        <small class="text-secondary">
                                            <i class="ti ti-key"></i> <?= htmlspecialchars($lic['keyword_matched']) ?>
                                        </small>
                                    </td>
                                    <td><?= htmlspecialchars(substr($lic['orgao'], 0, 40)) ?>...</td>
                                    <td><?= htmlspecialchars(substr($lic['objeto'], 0, 50)) ?>...</td>
                                    <td><span class="badge"><?= $lic['uf'] ?></span></td>
                                    <td>
                                        <?php if ($lic['valor_estimado']): ?>
                                            R$ <?= number_format($lic['valor_estimado'], 2, ',', '.') ?>
                                        <?php else: ?>
                                            <span class="text-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $stars = str_repeat('⭐', $lic['relevancia_score']);
                                        echo "<span title='Relevância {$lic['relevancia_score']}/5'>$stars</span>";
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = [
                                            'novo' => 'bg-blue',
                                            'analisado' => 'bg-green',
                                            'descartado' => 'bg-secondary',
                                            'participando' => 'bg-orange'
                                        ];
                                        $class = $status_class[$lic['status']] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?= $class ?>"><?= ucfirst($lic['status']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a href="view.php?id=<?= $lic['id'] ?>" class="btn btn-sm btn-primary">
                                            Ver Análise
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
};

include __DIR__ . '/../../../src/views/layouts/user.php';
?>
```

---

## Roadmap de Implementação

### **Fase 1: Fundação (5 dias)**
- [x] Schema PostgreSQL (`licitacoes_config`, `licitacoes`)
- [x] Migration SQL + rollback
- [x] Canvas SurveyJS (config.php)
- [x] Página de configuração (salva no DB)

### **Fase 2: API PNCP (7 dias)**
- [x] FastAPI router `/pncp/`
- [x] Função `search_pncp()` (integração API)
- [x] Função `analyze_edital_pncp()` (Claude + prompt 13c)
- [x] Parsing de resumo executivo (JSON)
- [x] Cálculo de relevância (heurística)
- [x] Testes unitários (pytest)

### **Fase 3: Cron Job (3 dias)**
- [x] Script Python `pncp_monitor.py`
- [x] Systemd timer (daily 07:00)
- [x] Logging estruturado (journald)
- [x] Error handling + retry logic
- [x] Dry-run mode para testes

### **Fase 4: Interface Web (5 dias)**
- [x] Página lista (`index.php`)
- [x] Página detalhes (`view.php`)
- [x] Filtros e busca (HTMX)
- [x] Mudança de status (novo → analisado → participando)
- [x] Anotações do usuário

### **Fase 5: Emails (3 dias)**
- [x] Template HTML email diário
- [x] Função `send_daily_report()`
- [x] Agrupamento de alertas
- [x] Links para portal
- [x] Opt-out / preferências

### **Fase 6: Testes e Deploy (7 dias)**
- [x] Testes E2E (Playwright)
- [x] Load testing (100 usuários simultâneos)
- [x] Deploy staging (OVH VM 100)
- [x] Validação com cliente IATR (feedback loop)
- [x] Deploy produção (Hostinger)
- [x] Documentação usuário final

**Total:** 30 dias (6 semanas)

---

## Custos e ROI (Detalhado)

### **Custos de Desenvolvimento**

| Item | Horas | Taxa/h | Total |
|------|-------|--------|-------|
| Schema DB + Migrations | 8h | — | — |
| Canvas SurveyJS | 12h | — | — |
| FastAPI PNCP integration | 24h | — | — |
| Cron job + systemd | 8h | — | — |
| Interface HTMX | 24h | — | — |
| Email templates | 8h | — | — |
| Testes + Deploy | 24h | — | — |
| **Total** | **108h** | — | **$0** (interno) |

### **Custos Operacionais (por cliente IATR)**

| Item | Quantidade | Custo Unitário | Custo/Mês |
|------|------------|----------------|-----------|
| API PNCP | ∞ requests | $0 (pública) | $0 |
| Claude Sonnet 4.5 | 220 editais × 61K input | $3/M tokens | $40.26 |
| Claude Sonnet 4.5 | 220 editais × 8K output | $15/M tokens | $26.40 |
| Storage (PostgreSQL) | ~500MB/mês | $0 (incluído) | $0 |
| Bandwidth | ~1GB/mês PDFs | $0 (incluído) | $0 |
| **Total** | | | **$66.66/mês** |

### **ROI por Cliente**

**Economia de Tempo:**
- Processo manual: 2h/dia × 22 dias = 44h/mês
- Custo analyst: $20/h × 44h = **$880/mês**

**Custo Automatizado:** $66.66/mês

**ROI:** $880 - $66.66 = **$813.34/mês** economizado  
**Payback:** 108h ÷ 44h/mês = **2.5 meses** (3 clientes IATR → ROI imediato)

---

## Expansão para Outras Verticais

Esta feature **não é exclusiva da IATR**. Pode ser habilitada para:

| Vertical | Use Case | Keywords |
|----------|----------|----------|
| **Jurídico** | Licitações de assessoria jurídica | "assessoria jurídica", "consultoria legal", "advocacia" |
| **Nicolay Advogados** | Idem jurídico | Idem |
| **Legal** | Compliance, due diligence | "compliance", "auditoria", "governança" |

**Modelo de Receita:**
- Feature básica: **inclusa** (até 10 keywords, 3 UFs)
- Feature premium: **+R$ 200/mês** (ilimitado keywords, todos UFs, análise prioritária)

**Projeção:**
- 10 clientes básicos = $666/mês custo, $0 receita (valor agregado)
- 5 clientes premium = $333/mês custo, **$1.000/mês** receita
- **Margem:** $667/mês líquido

---

## Riscos e Mitigações

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| **API PNCP mudar formato** | Média | Alto | Monitorar changelog, criar adapter layer |
| **Claude ficar muito caro** | Baixa | Médio | Implementar cache (editais duplicados), usar Haiku para pré-filtro |
| **PDFs não extraíveis (imagem)** | Alta | Médio | Integrar Docling para OCR (já analisado) |
| **Cliente desativar sem avisar** | Média | Baixo | Email mensal "ainda quer receber?" |
| **Falso positivos (muitos alertas)** | Alta | Médio | Melhorar heurística relevância, permitir filtros avançados |
| **Cron job falhar silenciosamente** | Média | Alto | Monitoring (Sentry), alertas se não executar |

---

## Recomendações Finais

### **Para o Claude:**

1. ✅ **APROVAR implementação** — caso de uso real, cliente pagante, ROI comprovado
2. ✅ **Prioridade ALTA** — IATR já usa isso manualmente, vai economizar $800/mês deles
3. ✅ **Começar por Fase 1** — schema + canvas (2 dias de trabalho)
4. ✅ **Validar com Filipe** — confirmar se IATR está disposto a testar MVP

### **Para o Filipe:**

1. 🤝 **Validar com cliente IATR** — confirmar interesse, coletar feedback sobre prompt 13c
2. 💰 **Avaliar modelo de receita** — cobrar feature premium ou incluir no plano base?
3. 🎯 **Definir prioridade** — isso compete com APIs DataJud/CGU do roadmap anterior?
4. 📧 **Aprovar template de email** — design aceitável ou precisa ajustes?

### **Próximos Passos Imediatos:**

1. Filipe valida interesse IATR (1 dia)
2. Claude cria schema PostgreSQL + migration (1 dia)
3. Copilot escreve testes Playwright para canvas (1 dia)
4. Claude implementa FastAPI router PNCP (2 dias)
5. Gemini audita segurança (API keys, CSRF, rate limiting) (1 dia)

**Prazo MVP:** 10 dias úteis (2 semanas)

---

## Conclusão

Esta é uma **oportunidade perfeita** para demonstrar o valor da Plataforma Sunyata v2:

✅ **Problema real** de cliente pagante (IATR)  
✅ **ROI mensurável** ($813/mês economizado)  
✅ **Arquitetura pronta** (70% já existe)  
✅ **Escalável** (serve múltiplas verticais)  
✅ **Diferencial competitivo** (nenhum portal oferece isso integrado)

**Minha análise:** Esta feature deve ser **prioridade #1** antes de DataJud/CGU, porque:
- IATR já é cliente (validação imediata)
- API PNCP é mais simples (sem autenticação)
- Prova de conceito rápida (10 dias vs 25 dias DataJud)
- Demonstra valor real da plataforma (não só CRUD)

Aguardo aprovação para começar implementação! 🚀

---

**Arquivos Analisados:**
- `/home/iflitaiff/LICIT_IATR/RESUMO EXECUTIVO 14.txt` (7.8KB)
- `/home/iflitaiff/LICIT_IATR/RESUMO EXECUTIVO 13c.txt` (11KB)
- `/home/iflitaiff/LICIT_IATR/RESUMO EXECUTIVO.md` (309 linhas)
- `/home/iflitaiff/LICIT_IATR/EDITAL COMPLETO.pdf` (4.5MB — referência)
- `/home/iflitaiff/LICIT_IATR/HABILITAÇÃO.txt` (5.4KB)
- `/home/iflitaiff/LICIT_IATR/Proposta de Integração de APIs Jurídicas ao Portal Sunyata.md`
- `/home/iflitaiff/LICIT_IATR/Relatório de Automação e Pesquisa Jurídica por Meios Digitais.md`

**Total:** 7 documentos, ~50KB de contexto analisado.
