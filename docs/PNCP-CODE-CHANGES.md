# PNCP Monitor - Code Changes Required

## Overview
The N8N PNCP Daily Monitor workflow needs to be updated to include portal integration links in the email HTML.

---

## Workflow Details

**Workflow Name:** PNCP Daily Monitor  
**Workflow ID:** `2X9z2xw7jjl6vvJY`  
**Access:** N8N GUI (http://localhost:5678)

---

## Node to Modify

**Node Name:** Process and Format  
**Node ID:** `edddb287-bc0f-4219-a2cf-d6ebae44ac1d`  
**Node Type:** Code (n8n-nodes-base.code, v2)  
**Current Size:** 6,381 characters, 101 lines  

---

## Current Code Issues

1. **Missing Portal Links**: No references to `/iatr/edital` page
2. **Hardcoded Email**: Always sends to `flitaiff@gmail.com`
3. **Limited Action Buttons**: Only "Ver PNCP" and "Documentos"

---

## Required Changes

### Change 1: Add Portal Link Generation (Lines 20-30)

**Location:** In the edital object creation loop

**Current Code:**
```javascript
allEditais.push({
  id_pncp: item.numero_controle_pncp || '',
  titulo: item.title || '',
  descricao: (item.description || '').substring(0, 500),
  orgao_nome: item.orgao_nome || '',
  uf: item.uf || '',
  municipio: item.municipio_nome || '',
  modalidade: item.modalidade_licitacao_nome || '',
  data_publicacao: item.data_publicacao_pncp || '',
  data_encerramento: item.data_fim_vigencia || '',
  link_pncp: `https://pncp.gov.br/app/editais/${item.id || ''}`,
  link_arquivos_api: cnpj ? `https://pncp.gov.br/pncp-api/v1/orgaos/${cnpj}/compras/${ano}/${seq}/arquivos` : '',
  cnpj_orgao: cnpj, ano_compra: ano, seq_compra: seq,
  valor_global: item.valor_global || null
});
```

**Updated Code:**
```javascript
allEditais.push({
  id_pncp: item.numero_controle_pncp || '',
  titulo: item.title || '',
  descricao: (item.description || '').substring(0, 500),
  orgao_nome: item.orgao_nome || '',
  uf: item.uf || '',
  municipio: item.municipio_nome || '',
  modalidade: item.modalidade_licitacao_nome || '',
  data_publicacao: item.data_publicacao_pncp || '',
  data_encerramento: item.data_fim_vigencia || '',
  link_pncp: `https://pncp.gov.br/app/editais/${item.id || ''}`,
  link_arquivos_api: cnpj ? `https://pncp.gov.br/pncp-api/v1/orgaos/${cnpj}/compras/${ano}/${seq}/arquivos` : '',
  // NEW: Portal analysis link
  link_portal_edital: item.numero_controle_pncp ? `https://sunyataconsulting.com/plataforma-sunyata/public/areas/iatr/edital.php?id=${encodeURIComponent(item.numero_controle_pncp)}` : '',
  cnpj_orgao: cnpj, ano_compra: ano, seq_compra: seq,
  valor_global: item.valor_global || null
});
```

**Alternative (localhost for testing):**
```javascript
link_portal_edital: item.numero_controle_pncp ? `/areas/iatr/edital.php?id=${encodeURIComponent(item.numero_controle_pncp)}` : '',
```

---

### Change 2: Update HTML Action Buttons (Lines 84-85)

**Location:** Inside the table row generation loop, in the `<td>` for "Ações" column

**Current Code:**
```javascript
html += `<td style="padding:10px;text-align:center;vertical-align:top"><a href="${item.link_pncp}" style="display:inline-block;background:#2980b9;color:#fff;padding:5px 12px;border-radius:4px;text-decoration:none;font-size:11px;margin:2px">Ver PNCP</a>`;
if (item.link_arquivos_api) html += `<br/><a href="${item.link_arquivos_api}" style="display:inline-block;background:#27ae60;color:#fff;padding:5px 12px;border-radius:4px;text-decoration:none;font-size:11px;margin:2px">Documentos</a>`;
html += `</td></tr>`;
```

**Updated Code:**
```javascript
html += `<td style="padding:10px;text-align:center;vertical-align:top">`;
html += `<a href="${item.link_pncp}" style="display:inline-block;background:#2980b9;color:#fff;padding:5px 12px;border-radius:4px;text-decoration:none;font-size:11px;margin:2px">Ver PNCP</a>`;
// NEW: Add portal analysis button if link available
if (item.link_portal_edital) {
  html += `<br/><a href="${item.link_portal_edital}" style="display:inline-block;background:#8e44ad;color:#fff;padding:5px 12px;border-radius:4px;text-decoration:none;font-size:11px;margin:2px;font-weight:bold">📊 Analisar</a>`;
}
if (item.link_arquivos_api) {
  html += `<br/><a href="${item.link_arquivos_api}" style="display:inline-block;background:#27ae60;color:#fff;padding:5px 12px;border-radius:4px;text-decoration:none;font-size:11px;margin:2px">📄 Docs</a>`;
}
html += `</td></tr>`;
```

---

### Change 3: Parametrize Email Address (Line 97)

**Location:** In the return statement, the email object

**Current Code:**
```javascript
return [{ json: {
  success: true,
  stats: { total_buscas: allResponses.length, total_bruto: totalAPI, total_deduplicado: unique.length, duplicatas_removidas: totalAPI-unique.length, erros_api: erros, por_uf: Object.fromEntries(Object.entries(byUF).map(([k,v])=>[k,v.length])) },
  editais: unique,
  email: { to: 'flitaiff@gmail.com', subject: `[PNCP] ${unique.length} editais abertos — ${hojeStr}`, html: html },
  timestamp: new Date().toISOString()
}}];
```

**Updated Code (Option A - Environment Variable):**
```javascript
const emailTo = (process.env.PNCP_EMAIL_TO || $env.PNCP_EMAIL_TO || 'flitaiff@gmail.com');
return [{ json: {
  success: true,
  stats: { total_buscas: allResponses.length, total_bruto: totalAPI, total_deduplicado: unique.length, duplicatas_removidas: totalAPI-unique.length, erros_api: erros, por_uf: Object.fromEntries(Object.entries(byUF).map(([k,v])=>[k,v.length])) },
  editais: unique,
  email: { to: emailTo, subject: `[PNCP] ${unique.length} editais abertos — ${hojeStr}`, html: html },
  timestamp: new Date().toISOString()
}}];
```

**Updated Code (Option B - N8N Variable):**
```javascript
return [{ json: {
  success: true,
  stats: { total_buscas: allResponses.length, total_bruto: totalAPI, total_deduplicado: unique.length, duplicatas_removidas: totalAPI-unique.length, erros_api: erros, por_uf: Object.fromEntries(Object.entries(byUF).map(([k,v])=>[k,v.length])) },
  editais: unique,
  email: { to: $env.PNCP_MONITOR_EMAIL || 'flitaiff@gmail.com', subject: `[PNCP] ${unique.length} editais abertos — ${hojeStr}`, html: html },
  timestamp: new Date().toISOString()
}}];
```

---

## Implementation Steps

### Via N8N GUI:

1. **Open N8N:** http://localhost:5678
2. **Find Workflow:** "PNCP Daily Monitor"
3. **Edit Workflow:** Click the edit button
4. **Double-click** on "Process and Format" node
5. **Replace entire code** with the updated version
6. **Click Save** at the bottom of the code editor
7. **Save Workflow** (Ctrl+S or click Save)
8. **Test:** Click "Execute Workflow" to verify

### Code Editor Tips:
- Ctrl+A to select all
- Ctrl+H for Find & Replace
- Ctrl+/ to comment/uncomment lines

---

## Testing Checklist

After applying changes:

- [ ] Workflow saves without syntax errors
- [ ] Code node shows green checkmark
- [ ] Email contains new "📊 Analisar" button
- [ ] Portal links have correct format
- [ ] Email still sends successfully
- [ ] HTML renders correctly in email client

---

## Rollback Plan

If issues occur:

1. Click "Undo" in workflow editor
2. Or reload the page without saving
3. Original code remains intact until saved

---

## Files Summary

| File | Location | Purpose |
|------|----------|---------|
| N8N Workflow | CT104 (192.168.100.14) | Stores workflow definition |
| Code Node | Process and Format | Contains email HTML generation |
| Configuration | N8N Database | Workflow metadata |

---

## Related Documentation

- PNCP API: https://pncp.gov.br/api
- N8N Workflow Reference: https://docs.n8n.io
- Portal IATR: `/areas/iatr/edital.php`
- Database Schema: `/docs/DATABASE.md`

