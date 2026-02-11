# Arquitetura v2 — Plataforma Sunyata (Servidor Dedicado)

**De:** Claude (Executor Principal)
**Para:** Manus, Gemini
**CC:** Filipe (sempre)
**Data:** 2026-02-10
**Acao esperada:** Avaliar — este documento e a referencia arquitetural para toda implementacao futura

---

## 1. Contexto Essencial

A Plataforma Sunyata esta migrando do Hostinger (shared hosting) para um servidor dedicado OVH com Proxmox. Esta migracao inclui mudancas arquiteturais significativas que elevam a plataforma de "ferramenta de formularios com IA" para "plataforma de IA com memoria institucional".

**Decisoes aprovadas pelo Filipe:**
- PostgreSQL + pgvector (substituindo MariaDB)
- Python microservice para camada de IA (substituindo PHP/cURL)
- Embeddings e busca semantica como prioridade alta
- Workspace do usuario com historico editavel e documentos reutilizaveis
- UI: Tabler + HTMX + SSE (consenso Claude + Manus)

**Timeline:** 1-2 meses para migracao. Testes no Hostinger continuam ate a migracao completa.

---

## 2. Infraestrutura

### Servidor OVH
- **Hardware:** Intel Xeon E3-1231v3 (4c/8t), 32GB RAM, 2x480GB SSD RAID1
- **Proxmox VE 9.1.4** no Debian 13
- **IP:** 158.69.25.114

### VMs Proxmox

| VMID | Nome | Funcao | Recursos Alvo |
|------|------|--------|---------------|
| 100 | portal-sunyata-dev | Producao | 2-4 cores, 16GB RAM, 64GB disco |
| 101 | kali-secmanager | Pentest (isolado) | 1 core, 2GB RAM, 32GB disco |

### Rede
- **vmbr0** (158.69.25.114) — publica, Proxmox host
- **vmbr1** (192.168.100.0/24) — interna, VMs
- VM 100 precisa de NAT ou IP adicional para acesso externo

---

## 3. Stack Tecnico (VM 100 — Ubuntu 24.04 LTS)

```
Nginx (reverse proxy, SSL, static files)
├── PHP 8.2+ FPM
│   ├── Auth, sessions, routing, views
│   ├── CRUD, admin panel
│   └── SurveyJS forms, Tabler UI
│
├── Python 3.12+ (FastAPI, uvicorn)
│   ├── Claude API (Anthropic SDK, streaming)
│   ├── Embeddings (geracao e busca)
│   ├── Document processing (PDF/DOCX)
│   └── Semantic search (pgvector)
│
├── PostgreSQL 16+ com pgvector
│   ├── Schema relacional (migrado do MariaDB)
│   ├── JSONB para configs e form_data
│   ├── vector(1536) para embeddings
│   └── Full-text search (pt-BR)
│
├── Redis 7+
│   ├── Sessions PHP
│   ├── Cache
│   └── Job queue (embeddings em background)
│
├── Certbot (Let's Encrypt SSL)
├── UFW + Fail2ban
└── unattended-upgrades
```

### Diagrama de Fluxo

```
                         ┌─────────────────────┐
                         │       Nginx          │
                         │  (reverse proxy/SSL) │
                         └──────┬───────────────┘
                                │
                    ┌───────────┴───────────┐
                    │                       │
             ┌──────┴──────┐      ┌────────┴────────┐
             │  PHP (FPM)  │      │  Python (FastAPI)│
             │             │ HTTP │                  │
             │ - Auth      │─────→│ - Claude API     │
             │ - Sessions  │ :8000│ - Streaming SSE  │
             │ - CRUD      │      │ - Embeddings     │
             │ - Views     │      │ - Semantic search │
             │ - Admin     │      │ - Doc processing │
             └──────┬──────┘      └────────┬────────┘
                    │                       │
                    └───────────┬───────────┘
                                │
                    ┌───────────┴───────────┐
                    │                       │
             ┌──────┴──────┐      ┌────────┴────────┐
             │ PostgreSQL  │      │     Redis        │
             │ + pgvector  │      │                  │
             │ :5432       │      │ :6379            │
             └─────────────┘      └─────────────────┘
                                          │
                                  ┌───────┴────────┐
                                  │  File Storage   │
                                  │  /var/lib/      │
                                  │  sunyata/files/ │
                                  └────────────────┘
```

---

## 4. PostgreSQL — Schema

### Tabelas migradas do MariaDB

Todas as tabelas existentes migram com ajustes:
- ENUM → CHECK constraints ou tabela de lookup `verticals`
- JSON strings → JSONB (com indices)
- charset/collation → UTF-8 nativo do PostgreSQL

Tabelas: `users`, `user_profiles`, `verticals`, `canvas`, `canvas_templates`, `prompt_history`, `vertical_access_requests`, `settings`, `tool_access_logs`, `audit_logs`

### Tabela de lookup para verticais (substitui ENUM)

```sql
-- Nunca mais ALTER TABLE para nova vertical
CREATE TABLE verticals (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    config JSONB,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- users.selected_vertical vira FK
ALTER TABLE users ADD CONSTRAINT fk_vertical
    FOREIGN KEY (selected_vertical_id) REFERENCES verticals(id);
```

### Novas tabelas — Workspace do Usuario

```sql
-- Submissoes editaveis com historico
CREATE TABLE user_submissions (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    canvas_template_id INT REFERENCES canvas_templates(id),
    vertical_slug VARCHAR(50),
    title VARCHAR(255),
    form_data JSONB NOT NULL,
    result_markdown TEXT,
    result_metadata JSONB,
    embedding vector(1536),
    status VARCHAR(20) DEFAULT 'completed'
        CHECK (status IN ('draft','processing','completed','archived','error')),
    parent_id INT REFERENCES user_submissions(id),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Documentos reutilizaveis
CREATE TABLE user_documents (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255),
    mime_type VARCHAR(100),
    file_size INT,
    storage_path VARCHAR(500) NOT NULL,
    extracted_text TEXT,
    embedding vector(1536),
    metadata JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indices para busca semantica (HNSW — rapido para queries)
CREATE INDEX idx_submissions_embedding
    ON user_submissions USING hnsw (embedding vector_cosine_ops);
CREATE INDEX idx_documents_embedding
    ON user_documents USING hnsw (embedding vector_cosine_ops);

-- Indices JSONB
CREATE INDEX idx_submissions_form_data
    ON user_submissions USING gin (form_data);
CREATE INDEX idx_templates_form_config
    ON canvas_templates USING gin (form_config);
```

---

## 5. Python Microservice (FastAPI)

### Endpoints

| Metodo | Path | Funcao |
|--------|------|--------|
| POST | `/api/ai/generate` | Chamada Claude API (sync, retorna JSON) |
| GET | `/api/ai/stream` | Streaming SSE (token por token) |
| POST | `/api/ai/embed` | Gerar embedding de texto |
| POST | `/api/ai/search` | Busca semantica (query → pgvector similarity) |
| POST | `/api/ai/process-document` | Extrair texto de PDF/DOCX |
| GET | `/api/ai/health` | Health check |

### Dependencias Python

```
anthropic          # SDK oficial Claude
fastapi            # Web framework async
uvicorn            # ASGI server
asyncpg            # PostgreSQL async driver
pgvector           # pgvector Python client
redis              # Redis client
python-multipart   # Upload de arquivos
PyPDF2             # Extração de texto PDF
python-docx        # Extração de texto DOCX
sentence-transformers  # Embeddings locais (opcional, backup)
```

### Streaming SSE — Fluxo

```
Browser                    PHP                    Python               Claude API
  │                         │                       │                      │
  │──POST form data────────→│                       │                      │
  │                         │──POST /ai/stream─────→│                      │
  │                         │                       │──stream request─────→│
  │                         │                       │←─token 1────────────│
  │←─SSE: token 1──────────│←──SSE: token 1────────│                      │
  │                         │                       │←─token 2────────────│
  │←─SSE: token 2──────────│←──SSE: token 2────────│                      │
  │                         │                       │←─[DONE]─────────────│
  │←─SSE: [DONE]───────────│←──SSE: [DONE]─────────│                      │
  │                         │                       │                      │
  │                         │   (background job via Redis)                 │
  │                         │                       │──generate embedding──│
  │                         │                       │──save to pgvector────│
```

### Comunicacao PHP → Python

```php
// PHP chama o microservice via HTTP interno
$response = file_get_contents('http://localhost:8000/api/ai/generate', false,
    stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Internal-Key: {$internalKey}\r\n",
            'content' => json_encode($payload),
        ]
    ])
);
```

Autenticacao: token interno (nao exposto ao publico, apenas localhost).

---

## 6. Frontend — Tabler + HTMX + SSE

### Stack Frontend

| Lib | Versao | Funcao |
|-----|--------|--------|
| **Tabler** | 1.x | UI kit (Bootstrap 5), layout, componentes, dark mode |
| **HTMX** | 2.x | Navegacao sem page reload, interatividade |
| **htmx-ext-sse** | - | Extensao SSE para streaming |
| **marked.js** | - | Renderizacao Markdown das respostas IA |
| **highlight.js** | - | Syntax highlighting em blocos de codigo |
| **Apache ECharts** | - | Graficos (incluso no Tabler) |
| **SurveyJS** | - | Formularios (licenca comercial existente) |

### Tema SurveyJS

Criar tema customizado via SurveyJS Theme Editor alinhado com as cores e tipografia do Tabler. Encapsular formularios em containers Tabler (cards). Testar z-index de dropdowns e uploads.

### Streaming no Frontend (HTMX + SSE)

```html
<!-- Resultado com streaming -->
<div id="result"
     hx-ext="sse"
     sse-connect="/api/canvas/stream?id=123"
     sse-swap="message">
</div>
```

O servidor PHP faz proxy do SSE do Python e envia para o browser. O HTMX insere cada chunk no DOM automaticamente.

---

## 7. Workspace do Usuario — "Meu Trabalho"

### Funcionalidades

1. **Historico de Submissoes**
   - Lista com busca textual e semantica
   - Filtros: por vertical, template, data, status
   - Click → visualizar resultado (Markdown renderizado)
   - "Editar e Resubmeter" → formulario preenchido com dados anteriores
   - Versionamento: resubmissoes linkadas via `parent_id`

2. **Meus Documentos**
   - Upload de PDF, DOCX, TXT
   - Texto extraido automaticamente + embedding gerado em background
   - Busca semantica por conteudo ("encontre o contrato de locacao")
   - Ao preencher formulario: selecionar de "Meus Documentos" em vez de upload

3. **Sugestoes Inteligentes**
   - Ao iniciar nova submissao: "Voce analisou algo similar em [data]"
   - Sugestao de template baseada no conteudo do documento anexado
   - Contexto acumulado por organizacao (futuro)

### Fluxo Completo

```
1. Usuario abre formulario
   → Plataforma sugere documentos relevantes de "Meus Documentos"
   → Plataforma mostra submissoes similares anteriores

2. Usuario preenche e submete
   → PHP valida → envia para Python microservice
   → Python chama Claude API com streaming
   → SSE envia tokens em tempo real para o browser
   → Resultado salvo em user_submissions com status 'completed'

3. Background (Redis queue → Python worker)
   → Gera embedding do input + output concatenados
   → Salva embedding no pgvector (user_submissions.embedding)
   → Indexa para busca futura

4. Area "Meu Trabalho"
   → Lista submissoes com busca semantica e textual
   → Click → resultado completo (Markdown renderizado)
   → "Editar e Resubmeter" → novo registro com parent_id linkado
   → "Meus Documentos" → upload, busca por conteudo, reutilizar
```

---

## 8. Fases de Implementacao

| Fase | Descricao | Dependencia | Entrega de Valor |
|------|-----------|-------------|-----------------|
| **0** | Repo privado + rotacao credenciais + limpeza git | Nenhuma | Seguranca |
| **1** | Setup servidor OVH (hardening, rede, Ubuntu VM) | Fase 0 | Infraestrutura |
| **2** | PostgreSQL + migracao schema MariaDB | Fase 1 | Fundacao |
| **3** | Python microservice basico (Claude API + streaming) | Fase 1 | Streaming SSE |
| **4** | Frontend: Tabler + HTMX + SSE | Fases 2+3 | UX moderna |
| **5** | `user_submissions` + "Meu Trabalho" | Fases 2+4 | Workspace |
| **6** | `user_documents` + file storage | Fase 5 | Docs reutilizaveis |
| **7** | pgvector + embeddings + busca semantica | Fases 2+3+5 | Diferencial |

Cada fase entrega valor independente. Hostinger continua rodando em paralelo ate migracao completa.

---

## 9. Migracao MariaDB → PostgreSQL

### Diferencas de sintaxe principais

| MariaDB | PostgreSQL | Acao |
|---------|-----------|------|
| `ENUM('a','b','c')` | `CHECK (col IN ('a','b','c'))` ou FK | Remover ENUMs |
| `AUTO_INCREMENT` | `SERIAL` ou `GENERATED ALWAYS` | Ajustar DDL |
| `GROUP_CONCAT()` | `STRING_AGG()` | Ajustar queries |
| `IFNULL()` | `COALESCE()` | Ajustar queries |
| `NOW()` | `NOW()` | Compativel |
| `DATE_FORMAT()` | `TO_CHAR()` | Ajustar queries |
| `LIMIT x,y` | `LIMIT y OFFSET x` | Ajustar queries |
| JSON functions | JSONB operators (`->`, `->>`, `@>`) | Melhorar |
| `PDO mysql:` | `PDO pgsql:` | Trocar driver |

### Estrategia de migracao

1. Exportar schema + dados do Hostinger (mysqldump)
2. Converter DDL para PostgreSQL (ferramenta pgloader ou manual)
3. Importar no PostgreSQL do novo servidor
4. Ajustar queries no codigo PHP (~15-20 queries)
5. Testar todas as verticais e funcionalidades
6. Rodar em paralelo ate estabilizar

---

## 10. Seguranca

### Servidor
- UFW: apenas portas 22, 80, 443 abertas ao publico
- Proxmox UI (8006): restrita ao IP do Filipe
- Fail2ban: SSH + nginx
- unattended-upgrades: patches automaticos
- Kali VM: rede isolada, ligada apenas durante testes

### Aplicacao
- Secrets em arquivo local (nao no git)
- HTTPS obrigatorio (redirect HTTP → HTTPS)
- CSRF tokens (ja implementado)
- Rate limiting via Redis
- Sanitizacao de input (ja implementado)
- Headers de seguranca (CSP, HSTS, X-Frame-Options)

### Python microservice
- Escuta apenas em localhost:8000 (nao exposto ao publico)
- Token interno para autenticacao PHP → Python
- Nao acessivel diretamente da internet

---

## 11. Responsabilidades dos Agentes

| Agente | Responsabilidades nesta migracao |
|--------|--------------------------------|
| **Claude** | Implementacao de codigo, deploy, migracao, configuracao do servidor |
| **Gemini** | Analise de seguranca do servidor, QA, code review, pentest com Kali |
| **Manus** | Arquitetura de conteudo, templates, UX dos formularios, tema SurveyJS |
| **Filipe** | Decisoes de produto, aprovacao, testes com usuarios, gestao |

---

**Versao:** 2.0
**Status:** Aprovado por Filipe (2026-02-10)
**Proxima revisao:** Apos Fase 1 concluida
