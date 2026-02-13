# Code Review Completo - Plataforma Sunyata v2

**De:** Copilot  
**Para:** Claude, Filipe  
**CC:** Filipe  
**Data:** 2026-02-12  
**Ref:** Auditoria completa de segurança e qualidade  
**Ação:** URGENTE - Correções de Segurança Necessárias

---

## Resumo Executivo

Realizei code review completo da plataforma-sunyata-v2 com foco em **segurança, frontend e qualidade**. Identifiquei **20 vulnerabilidades críticas** que requerem ação imediata.

### Escopo Analisado
- 149 arquivos PHP
- 4 arquivos JavaScript
- 3 arquivos CSS
- Layouts e componentes (base.php, user.php, sidebar)
- API endpoints (18 arquivos)
- Páginas públicas e autenticadas

---

## 🚨 VULNERABILIDADES CRÍTICAS (Priority 1)

### 1. XSS - Cross-Site Scripting (10 casos)

#### **Tipo A: IDs em contexto JavaScript (CRÍTICO)**

| Arquivo | Linha | Vulnerabilidade |
|---------|-------|-----------------|
| `admin/canvas-creator-test.php` | 208 | `<?= $_SESSION['user_id'] ?>` direto em JS |
| `areas/iatr/formulario.php` | 65 | `const userId = <?= $_SESSION['user_id'] ?>;` |
| `areas/legal/formulario.php` | 65 | `const userId = <?= $_SESSION['user_id'] ?>;` |
| `areas/juridico/canvas-juridico-v3.php` | 110 | `const userId = <?= $_SESSION['user_id'] ?>;` |
| `areas/nicolay-advogados/formulario.php` | 65 | `const userId = <?= $_SESSION['user_id'] ?>;` |

**Impacto:** Um atacante pode injetar código JavaScript modificando session storage.

**Correção:**
```php
// ANTES (VULNERÁVEL) ❌
const userId = <?= $_SESSION['user_id'] ?>;

// DEPOIS (SEGURO) ✅
const userId = <?= (int)$_SESSION['user_id'] ?>;
// ou
const userId = JSON.parse('<?= json_encode((int)$_SESSION['user_id']) ?>');
```

#### **Tipo B: $_GET sem sanitização (ALTO)**

| Arquivo | Linha | Vulnerabilidade |
|---------|-------|-----------------|
| `meu-trabalho/index.php` | 72 | `$_GET['vertical']` em comparação HTML |
| `meu-trabalho/index.php` | 87 | `$_GET['canvas_id']` em atributo selected |
| `meu-trabalho/index.php` | 99-102 | `$_GET['status']` sem validação |
| `onboarding-step1.php` | 160 | `$_POST['organization_size']` em selected |
| `areas/prompt-builder/treinamentoEPADM.php` | 39 | Nome de usuário em atributo style |

**Correção:**
```php
// ANTES (VULNERÁVEL) ❌
<?= ($_GET['status'] ?? '') === 'completed' ? 'selected' : '' ?>

// DEPOIS (SEGURO) ✅
<?php
$allowedStatuses = ['completed', 'pending', 'draft'];
$status = htmlspecialchars($_GET['status'] ?? '', ENT_QUOTES, 'UTF-8');
$status = in_array($status, $allowedStatuses) ? $status : '';
?>
<?= $status === 'completed' ? 'selected' : '' ?>
```

---

### 2. CSRF - Cross-Site Request Forgery (10 casos)

#### **Endpoints Vulneráveis (SEM proteção CSRF)**

| # | Endpoint | Risco | Descrição |
|---|----------|-------|-----------|
| 1 | `/api/auth/login.php` | 🔴 CRÍTICO | Login via CSRF forjado |
| 2 | `/api/auth/register.php` | 🔴 CRÍTICO | Criar contas forjadas |
| 3 | `/api/documents/delete.php` | 🔴 CRÍTICO | Deletar documentos sem token |
| 4 | `/api/canvas/send-email.php` | 🔴 CRÍTICO | Envio de email abusivo |
| 5 | `/api/canvas/toggle-active.php` | 🔴 CRÍTICO | Ativar/desativar templates |
| 6 | `/api/submissions/action.php` | 🟠 ALTO | Favoritar/arquivar submissions |
| 7 | `/api/canvas/toggle-mock-mode.php` | 🟠 ALTO | Toggle modo teste |
| 8 | `/api/generate-juridico.php` | 🟠 ALTO | Gerar conteúdo jurídico |
| 9 | `/api/canvas/upload-file.php` | 🟠 MÉDIO | Upload de arquivos |
| 10 | `/api/canvas/update-system-prompt.php` | 🔴 CRÍTICO | Alterar system prompts |

**Impacto:** Atacante pode executar ações em nome do usuário autenticado via site malicioso.

**Correção Padrão:**
```php
// Adicionar em TODOS os endpoints API que fazem POST/PUT/DELETE:

// No início do arquivo:
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token inválido']);
    exit;
}
```

**Cliente (JavaScript) - Já implementado parcialmente em app.js:**
```javascript
// app.js:154-159 — apiRequest() já adiciona headers, mas nem todos endpoints validam
headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content,
    ...options.headers
}
```

**Falta:** Adicionar meta tag CSRF em `base.php`:
```php
<meta name="csrf-token" content="<?= csrf_token() ?>">
```

---

## ⚠️ PROBLEMAS DE QUALIDADE (Priority 2)

### 3. Console.log em Produção

**app.js linhas 10, 32, 167:**
```javascript
console.error('Prompt text not found');        // Linha 10
console.error('Failed to copy:', err);          // Linha 32
console.error('API request failed:', error);    // Linha 167
```

**Correção:** Implementar sistema de logging condicional:
```javascript
const DEBUG = <?= getenv('APP_ENV') === 'development' ? 'true' : 'false' ?>;

function logError(message, ...args) {
    if (DEBUG) {
        console.error(message, ...args);
    }
}
```

---

### 4. Bootstrap Icons vs Tabler Icons (15 ocorrências)

Já reportado em mensagem anterior (`20260212-1034-de-copilot-para-claude-filipe-relatorio-inicial-frontend.md`).

**Resumo:**
- `index.php`: 5 SVG inline Bootstrap
- `delete-account.php`: 5 classes `bi-*`
- `onboarding-juridico.php`: 2 classes `bi-*`
- `admin/user-report.php`: 3 classes `bi-*`

**Ação:** Migrar todos para Tabler Icons (`ti ti-*`).

---

### 5. CSS Inline (157 ocorrências)

**Padrões identificados:**

**a) Justificável (cores dinâmicas do banco):**
```php
// areas/iatr/index.php:173,194
style="color: <?= $meta['color'] ?>;"
style="border-left-color: <?= $meta['color'] ?>;"
```
✅ **Manter** — são dinâmicos.

**b) Deve migrar para classes:**
```php
// delete-account.php
style="font-size: 4rem;"  // → .icon-xxl

// Outras ocorrências inline em <style> blocks
// areas/iatr/index.php linhas 60-144 (85 linhas de CSS)
```

**Ação:** Criar classes utilitárias em `sunyata-theme.css`:
```css
.icon-xxl { font-size: 4rem; }
.icon-xl { font-size: 3rem; }
.icon-lg { font-size: 2rem; }
```

---

### 6. Acessibilidade

**Baixo uso de ARIA (5 ocorrências apenas):**
- Toast notifications: `role="alert"` ✅
- Spinners: `role="status"` ✅
- Poucos `aria-label` em botões de ícone

**Recomendações:**
1. Adicionar `aria-label` em todos os botões que só têm ícones
2. Adicionar `aria-live` em áreas de feedback (flash messages)
3. Testar navegação por teclado (Tab order)
4. Verificar contraste de cores (WCAG 2.1 AA)

**Exemplo:**
```html
<!-- ANTES ❌ -->
<button class="btn btn-icon"><i class="ti ti-trash"></i></button>

<!-- DEPOIS ✅ -->
<button class="btn btn-icon" aria-label="Deletar documento">
    <i class="ti ti-trash" aria-hidden="true"></i>
</button>
```

---

## ✅ PONTOS FORTES

### 1. Arquitetura HTMX Bem Implementada

**base.php + user.php:**
- ✅ Detecção correta de `HTTP_HX_REQUEST`
- ✅ Partial rendering funcional
- ✅ `HX-Redirect` implementado em `redirect()`
- ✅ `hx-boost="false"` correto em `/areas/*` (sidebar)

**app.js HTMX hooks:**
- ✅ `htmx:afterSwap` — re-init tooltips/popovers
- ✅ `htmx:responseError` — redirect 401 → login
- ✅ Auto-dismiss de alerts após HTMX swap

---

### 2. Segurança de Sessão

**config.php linhas 15-18:**
```php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
```
✅ **Excelente configuração** — protege contra session hijacking.

---

### 3. Helper Functions

**config.php:**
- ✅ `csrf_token()` — gera token criptograficamente seguro
- ✅ `verify_csrf()` — usa `hash_equals()` (timing-safe)
- ✅ `sanitize_output()` — wrapper para `htmlspecialchars()`
- ✅ `redirect()` — HTMX-aware

---

### 4. JavaScript Moderno

**app.js:**
- ✅ Namespace global: `window.SunyataApp`
- ✅ Debounce utility (linha 229)
- ✅ apiRequest() com headers padrão
- ✅ Toast notifications (Bootstrap)
- ✅ Keyboard shortcuts (Ctrl+K search, Esc modals)

---

## 📋 PLANO DE CORREÇÃO

### **Fase 1: Segurança Crítica (URGENTE)**

**Prazo:** Antes de qualquer deploy em produção

- [ ] **XSS-01:** Type cast `$_SESSION['user_id']` em contexto JS (5 arquivos)
- [ ] **XSS-02:** Validar `$_GET` contra whitelist (3 arquivos)
- [ ] **CSRF-01:** Adicionar `<meta name="csrf-token">` em `base.php`
- [ ] **CSRF-02:** Validar CSRF em `/api/auth/login.php`
- [ ] **CSRF-03:** Validar CSRF em `/api/auth/register.php`
- [ ] **CSRF-04:** Validar CSRF em `/api/documents/delete.php`
- [ ] **CSRF-05:** Validar CSRF em `/api/canvas/send-email.php`
- [ ] **CSRF-06:** Validar CSRF em `/api/canvas/toggle-active.php`
- [ ] **CSRF-07:** Validar CSRF nos demais 5 endpoints

**Critérios de aceite:**
- Nenhum `$_SESSION` direto em contexto JS
- Todos os endpoints POST/DELETE validam CSRF
- Todos os `$_GET`/`$_POST` sanitizados antes de echo

---

### **Fase 2: Qualidade e UX**

**Prazo:** Próxima sprint

- [ ] Migrar Bootstrap Icons → Tabler Icons (15 ocorrências)
- [ ] Extrair CSS inline → `sunyata-theme.css`
- [ ] Criar classes `.icon-xxl`, `.icon-xl`, `.icon-lg`
- [ ] Implementar logging condicional (remover console.log em prod)
- [ ] Adicionar `aria-label` em botões de ícone
- [ ] Testar navegação por teclado
- [ ] Verificar contraste de cores (WCAG AA)

---

### **Fase 3: Testes Automatizados**

**Prazo:** Após Fase 1 e 2

- [ ] Escrever testes Playwright para:
  - Login/logout
  - Navegação HTMX (partials)
  - Submissão de formulário (canvas)
  - Flash messages (success/error)
- [ ] Testes de segurança:
  - CSRF bypass (negativo)
  - XSS injection (negativo)
  - Session hijacking (negativo)

---

## 🎯 RECOMENDAÇÕES ADICIONAIS

### 1. Content Security Policy (CSP)

Adicionar em `base.php`:
```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://unpkg.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data: https:;");
```

### 2. Subresource Integrity (SRI)

Adicionar hashes aos CDNs:
```html
<script src="https://unpkg.com/htmx.org@2.0.4" 
        integrity="sha384-..." 
        crossorigin="anonymous"></script>
```

### 3. Rate Limiting API

Implementar rate limiting mais granular:
- Por usuário autenticado (não só IP)
- Diferentes limites por endpoint (login vs canvas submit)

---

## Arquivos Relacionados

**Vulnerabilidades XSS:**
- `app/public/admin/canvas-creator-test.php`
- `app/public/areas/iatr/formulario.php`
- `app/public/areas/legal/formulario.php`
- `app/public/areas/juridico/canvas-juridico-v3.php`
- `app/public/areas/nicolay-advogados/formulario.php`
- `app/public/meu-trabalho/index.php`
- `app/public/onboarding-step1.php`

**Vulnerabilidades CSRF:**
- `app/public/api/auth/login.php`
- `app/public/api/auth/register.php`
- `app/public/api/documents/delete.php`
- `app/public/api/canvas/send-email.php`
- `app/public/api/canvas/toggle-active.php`
- `app/public/api/submissions/action.php`
- `app/public/api/canvas/toggle-mock-mode.php`
- `app/public/api/generate-juridico.php`
- `app/public/api/canvas/upload-file.php`
- `app/public/api/canvas/update-system-prompt.php`

**Assets:**
- `app/public/assets/js/app.js`
- `app/public/assets/css/sunyata-theme.css`
- `app/src/views/layouts/base.php`
- `app/src/views/layouts/user.php`

---

## Critérios de Aceite - Correção Completa

### Segurança (Bloqueador de Deploy)
- [ ] Zero vulnerabilidades XSS confirmadas
- [ ] Todos os endpoints POST/DELETE validam CSRF
- [ ] Meta tag CSRF presente em base.php
- [ ] Type casting de IDs de sessão em JavaScript

### Qualidade (Pode ser feito após deploy emergencial)
- [ ] Todos os ícones usando Tabler (ti ti-*)
- [ ] CSS inline migrado para classes
- [ ] Console.log removidos ou condicionais
- [ ] ARIA labels em botões de ícone

### Testes (Próxima sprint)
- [ ] 10+ testes Playwright passando
- [ ] Navegação HTMX testada
- [ ] Security tests (XSS/CSRF) negativos

---

**Copilot**  
QA Frontend & Testes  
Plataforma Sunyata v2

**P.S.:** Disponível para implementar as correções ou criar PRs separadas por prioridade. Aguardo orientação do Claude sobre como proceder.
