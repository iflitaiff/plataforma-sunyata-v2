# Fase 3: Form Chaining MVP

**Status:** Planejamento
**Estimativa:** 3-5 dias
**Início previsto:** Após validação T7-T8 (segurança Drafts)

---

## Objetivo

Permitir que usuários **reutilizem outputs** de formulários anteriores como **contexto** em novos formulários, sem mapeamento automático.

**Caso de uso:**
1. Usuário preenche "Parecer IATR - Análise Preliminar" → gera artifact
2. Usuário inicia "Parecer IATR - Versão Final" → vê artifact anterior no context panel
3. Usuário **copia manualmente** trechos relevantes do artifact para o novo formulário

---

## Arquitetura

### Database Schema

#### Tabela: `form_artifacts`
```sql
CREATE TABLE form_artifacts (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    canvas_template_id INT NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    submission_id INT UNIQUE REFERENCES form_submissions(id) ON DELETE SET NULL,

    title VARCHAR(255) NOT NULL,
    artifact_type VARCHAR(50) NOT NULL, -- 'text', 'json', 'markdown'
    content TEXT NOT NULL,
    metadata JSONB DEFAULT '{}',

    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),

    CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_template FOREIGN KEY (canvas_template_id) REFERENCES canvas_templates(id),
    CONSTRAINT fk_submission FOREIGN KEY (submission_id) REFERENCES form_submissions(id)
);

CREATE INDEX idx_artifacts_user ON form_artifacts(user_id);
CREATE INDEX idx_artifacts_template ON form_artifacts(canvas_template_id);
CREATE INDEX idx_artifacts_created ON form_artifacts(created_at DESC);
```

#### Tabela: `artifact_relations`
```sql
CREATE TABLE artifact_relations (
    id SERIAL PRIMARY KEY,
    from_template_id INT NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    to_template_id INT NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    relation_type VARCHAR(50) DEFAULT 'suggested', -- 'suggested', 'manual'
    display_order INT DEFAULT 0,

    created_at TIMESTAMPTZ DEFAULT NOW(),

    CONSTRAINT fk_from_template FOREIGN KEY (from_template_id) REFERENCES canvas_templates(id),
    CONSTRAINT fk_to_template FOREIGN KEY (to_template_id) REFERENCES canvas_templates(id),
    CONSTRAINT unique_relation UNIQUE (from_template_id, to_template_id)
);

CREATE INDEX idx_relations_to ON artifact_relations(to_template_id);
```

**Nota:** `artifact_relations` define quais templates podem sugerir artifacts. Exemplo:
- `from_template_id=5` (Parecer Preliminar) → `to_template_id=7` (Parecer Final)
- Ao abrir template 7, o system busca artifacts do template 5 do usuário

---

### API Endpoints

#### 1. `POST /api/artifacts/create.php`
Criar artifact ao completar formulário.

**Request:**
```json
{
    "canvas_template_id": 5,
    "submission_id": 123,
    "title": "Parecer Preliminar - Caso XYZ",
    "artifact_type": "markdown",
    "content": "# Análise...",
    "metadata": {
        "case_id": "XYZ-2026",
        "tags": ["iatr", "preliminar"]
    }
}
```

**Response:**
```json
{
    "success": true,
    "artifact_id": 456,
    "message": "Artifact criado com sucesso"
}
```

**Trigger:** Automático após `form_submissions` INSERT (via trigger SQL ou PHP hook).

---

#### 2. `GET /api/artifacts/list.php?template_id=X`
Listar artifacts do usuário para um template.

**Response:**
```json
{
    "success": true,
    "artifacts": [
        {
            "id": 456,
            "title": "Parecer Preliminar - Caso XYZ",
            "artifact_type": "markdown",
            "created_at": "2026-02-13T10:30:00Z",
            "preview": "# Análise\n\nResumo executivo..." // Primeiros 200 chars
        }
    ],
    "count": 1
}
```

---

#### 3. `GET /api/artifacts/load.php?id=X`
Carregar conteúdo completo de um artifact.

**Response:**
```json
{
    "success": true,
    "artifact": {
        "id": 456,
        "title": "Parecer Preliminar - Caso XYZ",
        "artifact_type": "markdown",
        "content": "# Análise completa...",
        "metadata": {...},
        "created_at": "2026-02-13T10:30:00Z",
        "canvas_template_name": "Parecer IATR Preliminar"
    }
}
```

**Segurança:** Verificar `user_id` (IDOR protection).

---

#### 4. `GET /api/artifacts/related.php?template_id=X`
Buscar artifacts relacionados (de templates que têm relação configurada).

**Exemplo:** Usuário abre template 7 (Parecer Final).
Sistema busca `artifact_relations WHERE to_template_id=7` → encontra template 5.
Retorna artifacts do usuário para template 5.

**Response:**
```json
{
    "success": true,
    "related_artifacts": [
        {
            "id": 456,
            "title": "Parecer Preliminar - Caso XYZ",
            "from_template": "Parecer IATR Preliminar",
            "created_at": "2026-02-13T10:30:00Z",
            "preview": "..."
        }
    ],
    "count": 1
}
```

---

#### 5. `DELETE /api/artifacts/delete.php?id=X`
Deletar artifact (opcional - futuro).

---

### Frontend (Context Panel)

#### Componente: Context Panel

**Localização:** Sidebar colapsável à direita do formulário.

**Estados:**
- **Collapsed (default):** Botão "📋 Contexto" flutuante no canto direito
- **Expanded:** Panel 300px com lista de artifacts relacionados

**HTML estrutura:**
```html
<div id="contextPanel" class="context-panel collapsed">
    <button id="toggleContext" class="btn btn-sm btn-outline-secondary">
        <i class="ti ti-file-text"></i> Contexto
    </button>

    <div class="context-content" style="display:none;">
        <div class="context-header">
            <h6>Documentos Relacionados</h6>
            <button class="btn-close" id="closeContext"></button>
        </div>

        <div class="context-list" id="contextList">
            <!-- Artifacts carregados via JS -->
        </div>
    </div>
</div>
```

**CSS:**
```css
.context-panel {
    position: fixed;
    right: 0;
    top: 100px;
    width: 50px;
    transition: width 0.3s;
}

.context-panel.expanded {
    width: 350px;
    background: white;
    box-shadow: -2px 0 10px rgba(0,0,0,0.1);
    border-left: 1px solid #ddd;
}

.context-content {
    padding: 1rem;
    height: calc(100vh - 120px);
    overflow-y: auto;
}

.artifact-card {
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    cursor: pointer;
}

.artifact-card:hover {
    background: #f5f5f5;
}
```

**JavaScript:**
```javascript
class ContextManager {
    constructor(templateId) {
        this.templateId = templateId;
        this.artifacts = [];
        this.init();
    }

    async init() {
        await this.loadRelatedArtifacts();
        this.bindEvents();
    }

    async loadRelatedArtifacts() {
        const response = await fetch(`/api/artifacts/related.php?template_id=${this.templateId}`);
        const data = await response.json();

        if (data.success) {
            this.artifacts = data.related_artifacts;
            this.render();
        }
    }

    render() {
        const list = document.getElementById('contextList');

        if (this.artifacts.length === 0) {
            list.innerHTML = '<p class="text-muted">Nenhum documento relacionado.</p>';
            return;
        }

        list.innerHTML = this.artifacts.map(artifact => `
            <div class="artifact-card" data-id="${artifact.id}">
                <div class="artifact-title">${artifact.title}</div>
                <div class="artifact-meta text-muted small">
                    ${artifact.from_template} • ${new Date(artifact.created_at).toLocaleDateString()}
                </div>
                <div class="artifact-preview text-muted small mt-1">
                    ${artifact.preview}
                </div>
                <button class="btn btn-sm btn-primary mt-2" onclick="contextManager.viewArtifact(${artifact.id})">
                    Ver Completo
                </button>
            </div>
        `).join('');
    }

    async viewArtifact(id) {
        const response = await fetch(`/api/artifacts/load.php?id=${id}`);
        const data = await response.json();

        if (data.success) {
            this.showModal(data.artifact);
        }
    }

    showModal(artifact) {
        // Criar modal Bootstrap com conteúdo do artifact
        const modal = `
            <div class="modal fade" id="artifactModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${artifact.title}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2 text-muted">
                                <small>${artifact.canvas_template_name} • ${new Date(artifact.created_at).toLocaleDateString()}</small>
                            </div>
                            <div class="artifact-content" style="white-space: pre-wrap;">
                                ${artifact.content}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                            <button class="btn btn-primary" onclick="contextManager.copyToClipboard('${artifact.id}')">
                                <i class="ti ti-copy"></i> Copiar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modal);
        const bsModal = new bootstrap.Modal(document.getElementById('artifactModal'));
        bsModal.show();
    }

    copyToClipboard(artifactId) {
        const artifact = this.artifacts.find(a => a.id == artifactId);
        navigator.clipboard.writeText(artifact.content);
        alert('Conteúdo copiado para a área de transferência!');
    }

    bindEvents() {
        document.getElementById('toggleContext').addEventListener('click', () => {
            const panel = document.getElementById('contextPanel');
            panel.classList.toggle('expanded');

            const content = document.querySelector('.context-content');
            content.style.display = panel.classList.contains('expanded') ? 'block' : 'none';
        });

        document.getElementById('closeContext').addEventListener('click', () => {
            document.getElementById('contextPanel').classList.remove('expanded');
            document.querySelector('.context-content').style.display = 'none';
        });
    }
}

// Inicializar ao carregar formulário
let contextManager;
document.addEventListener('DOMContentLoaded', () => {
    const templateId = new URLSearchParams(window.location.search).get('template');
    if (templateId) {
        contextManager = new ContextManager(templateId);
    }
});
```

---

### Admin: Configurar Relações

**Página:** `/admin/artifact-relations.php`

**Funcionalidade:**
- Listar relações existentes (tabela)
- Adicionar nova relação: dropdown de templates (FROM → TO)
- Deletar relação

**UI simples:**
```
+------------------------------------------+
| Relações de Artifacts                    |
+------------------------------------------+
| FROM Template          | TO Template     | Ações |
|------------------------|-----------------|-------|
| Parecer Preliminar     | Parecer Final   | [X]   |
| Análise de Risco       | Relatório Final | [X]   |
+------------------------------------------+
| [+] Adicionar Relação                    |
+------------------------------------------+
```

---

## Fluxo de Implementação

### Dia 1: Database + Backend Core
1. ✅ Migration: Criar tabelas `form_artifacts` e `artifact_relations`
2. ✅ Service: `ArtifactService.php` (CRUD básico)
3. ✅ API: Endpoints `create.php`, `list.php`, `load.php`
4. ✅ Hook: Criar artifact automaticamente após submission

### Dia 2: API Relations + Admin
1. ✅ API: Endpoint `related.php`
2. ✅ Admin: Página `artifact-relations.php` (CRUD relações)
3. ✅ Seeding: Popular `artifact_relations` com relações IATR (preliminar → final)

### Dia 3: Frontend Context Panel
1. ✅ CSS: Estilos do context panel
2. ✅ JS: `ContextManager` class
3. ✅ Integração: Adicionar context panel em `formulario.php`
4. ✅ Modal: Visualização de artifacts

### Dia 4: Testes + Refinamento
1. ✅ Testar fluxo completo: submit → artifact → context panel
2. ✅ IDOR protection (artifacts são privados por usuário)
3. ✅ UX refinements (loading states, empty states)

### Dia 5: Deploy + Documentação
1. ✅ Deploy na VM100
2. ✅ Documentação de uso (para usuários finais)
3. ✅ Testes Playwright (opcional - pode ser depois)

---

## Critérios de Sucesso

1. ✅ Usuário completa formulário → artifact criado automaticamente
2. ✅ Ao abrir formulário relacionado → context panel mostra artifacts
3. ✅ Usuário clica em artifact → modal exibe conteúdo completo
4. ✅ Usuário copia conteúdo → pode colar no formulário atual
5. ✅ Admin pode configurar relações entre templates
6. ✅ IDOR protection: artifacts privados por usuário

---

## Out of Scope (MVP)

❌ Mapeamento automático de campos
❌ Sugestões de IA para preencher campos
❌ Indicador de compatibilidade entre schemas
❌ Versionamento de artifacts
❌ Busca/filtro avançado de artifacts
❌ Tags/categorias de artifacts

**Motivo:** Over-engineering para MVP. Se houver demanda real, implementar na v2.

---

## Riscos e Mitigações

### Risco 1: Artifacts muito grandes (>1MB)
**Mitigação:** Limite de 1MB no backend. Se necessário mais, implementar storage em disco (fase 2).

### Risco 2: Usuário não entende o context panel
**Mitigação:** Tooltip explicativo + documentação inline ("📋 Documentos de formulários anteriores que podem ajudar").

### Risco 3: Baixa adoção (usuários não usam)
**Mitigação:** Monitorar uso via logs. Se <10% de adoção em 30 dias, reavaliar necessidade.

---

## Métricas de Sucesso

- **Adoção:** % de usuários que abrem context panel
- **Conversão:** % de artifacts que são visualizados
- **Reuso:** % de formulários que usam artifacts (medido por tempo no modal)

**Target inicial:** 20% de adoção em 30 dias.

---

## Notas Finais

Este MVP foca em **simplicidade e usabilidade**. O usuário tem controle total (copia o que precisa). Se houver demanda por automação (mapeamento automático), podemos avaliar IA-powered suggestions na v2.

**Aprovado por:** Claude (executor principal)
**Baseado em:** Análise do Copilot + consenso da reunião de equipe
**Data:** 2026-02-13
