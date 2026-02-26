# Análise: Renderização Markdown + PDF + Email — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Render AI analysis results as formatted Markdown, allow PDF preview/download, and send analysis via email through N8N.

**Architecture:** Fix the JS fallback chain to extract `resumo_executivo` from JSONB, use marked.js (already in base.php) for rich rendering, use mPDF (already installed) + Parsedown (to install) for server-side PDF generation, and create a new N8N workflow for email sending that the portal calls via server-side proxy.

**Tech Stack:** marked.js (CDN, already loaded), mPDF 8.2 (composer, already installed), Parsedown (composer, to install), N8N emailSend node, Tabler modals.

---

### Task 1: Install Parsedown via Composer

**Files:**
- Modify: `app/composer.json`

**Step 1: Install parsedown**

Run: `cd /home/iflitaiff/projetos/plataforma-sunyata-v2/app && composer require erusev/parsedown`

**Step 2: Verify installation**

Run: `php -r "require 'vendor/autoload.php'; echo (new Parsedown())->text('**bold**');"`
Expected: `<p><strong>bold</strong></p>`

**Step 3: Deploy to server**

Copy composer files and run `composer install --no-dev` on VM100.

**Step 4: Commit**

`git commit -m "feat(deps): add parsedown for server-side markdown rendering"`

---

### Task 2: Fix Markdown Rendering on Edital Page

**Files:**
- Modify: `app/public/areas/iatr/edital.php` (lines 304-348)

**Step 1: Fix the JSONB key fallback chain (line 305)**

Change:
```javascript
const texto = resultado.texto || resultado.markdown || JSON.stringify(resultado, null, 2);
```
To:
```javascript
const texto = resultado.resumo_executivo || resultado.texto || resultado.markdown || JSON.stringify(resultado, null, 2);
```

**Step 2: Replace artisanal renderMarkdown() with marked.js**

Replace the entire `renderMarkdown` function (lines 335-348) with:
```javascript
function renderMarkdown(text) {
    if (!text) return '';
    return marked.parse(text);
}
```

Note: `marked` is already globally available from `base.php` CDN include (`marked@15.0.0`).

**Step 3: Add CSS for rendered markdown tables and content**

In the style section, add styles for `.analise-resultado table`, `.analise-resultado th/td`,
`.analise-resultado h1/h2/h3`, `.analise-resultado hr`, `.analise-resultado blockquote`, etc.

**Step 4: Deploy and test**

Deploy `iatr/edital.php` to VM100. Open edital with completed analysis — should render rich markdown.

**Step 5: Commit**

`git commit -m "feat(iatr): render analysis as rich markdown with marked.js"`

---

### Task 3: Create PDF Export Endpoint

**Files:**
- Create: `app/public/api/pncp/export-pdf.php`

**Step 1: Create the PDF endpoint**

- GET `/api/pncp/export-pdf.php?id=N` — returns PDF inline (for iframe preview)
- GET `/api/pncp/export-pdf.php?id=N&download=1` — returns PDF as attachment (for download)
- Auth check (session) + validate ID
- Fetch edital from DB, extract `resumo_executivo` markdown
- Convert markdown to HTML with Parsedown
- Build HTML template with Sunyata header, meta info, content, footer
- Generate PDF with mPDF, output inline or download

**Step 2: Deploy and test**

Navigate to `/api/pncp/export-pdf.php?id=1` while logged in — should show PDF.

**Step 3: Commit**

`git commit -m "feat(iatr): add PDF export endpoint using mPDF"`

---

### Task 4: Add PDF Preview Modal and Action Buttons

**Files:**
- Modify: `app/public/areas/iatr/edital.php`

**Step 1: Add PDF preview modal (Tabler modal with iframe)**

Modal with:
- iframe src pointing to export-pdf.php
- Footer: [Fechar] [Baixar PDF] [Enviar por Email]

**Step 2: Add email modal**

Modal with:
- Email input field (default: logged-in user email)
- Status area for success/error messages
- Footer: [Cancelar] [Enviar]

**Step 3: Update action buttons in renderAnaliseResult()**

Replace single "Reanalisar" button with: [Reanalisar] [PDF] [Email]

**Step 4: Add JS functions**

- `openPdfPreview()` — loads PDF in iframe, shows modal
- `showEmailForm()` — shows email modal
- `sendAnaliseEmail()` — POST to `/api/pncp/email-analise.php`

**Step 5: Deploy and test**

Test: PDF button opens modal with preview. Download works. Email button opens form.

**Step 6: Commit**

`git commit -m "feat(iatr): add PDF preview modal and email action buttons"`

---

### Task 5: Create N8N Workflow "Portal - Send Email"

**Files:**
- N8N workflow via API

**Step 1: Create 5-node workflow**

1. Webhook (typeVersion 1.1) — POST `/webhook/portal/send-email`
2. Check Auth (IF, typeVersion 1) — validates X-Auth-Token
3. Respond 401 (Respond to Webhook, typeVersion 1) — unauthorized
4. Send Email (emailSend, typeVersion 2.1) — SMTP credential `TAZ8C6Oo3qLTak9d`, with attachment
5. Respond OK (Respond to Webhook, typeVersion 1.5) — success

Webhook body: `{to, subject, html, attachment_name, attachment_base64}`

**Step 2: Activate and test**

Send test email via curl to verify.

---

### Task 6: Create Email Sending Proxy Endpoint

**Files:**
- Create: `app/public/api/pncp/email-analise.php`

**Step 1: Create the endpoint**

- POST with `{edital_id, to}`
- Auth + CSRF checks
- Fetch edital, generate PDF in memory (mPDF + Parsedown)
- Base64-encode PDF
- POST to N8N webhook `/webhook/portal/send-email` via internal network
- Return success/error JSON

**Step 2: Deploy and test**

From edital page: click Email, enter address, send — verify email arrives with PDF attached.

**Step 3: Commit**

`git commit -m "feat(iatr): add email sending proxy with PDF via N8N"`

---

### Task 7: End-to-End Testing

**Step 1: Full flow test**

1. PNCP email → click Detalhes → edital page loads
2. Click Analisar com IA → analysis runs → rich markdown renders
3. Click PDF → modal with preview
4. Click Baixar → PDF downloads
5. Click Email → send → email arrives with PDF attached
6. PNCP email → click Analisar IA → auto-analysis triggered

**Step 2: Final deploy via git pull**

`tools/ssh-cmd.sh vm100 "cd /var/www/sunyata/app && git pull"`
