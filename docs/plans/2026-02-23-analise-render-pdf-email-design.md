# Análise: Renderização Markdown + PDF Preview + Email via N8N

**Data:** 2026-02-23
**Status:** Aprovado

## Problema
O resultado da análise IA aparece como JSON puro porque:
1. O JS procura `resultado.texto` mas o JSONB tem chave `resumo_executivo`
2. O renderMarkdown() artesanal não suporta tabelas, listas numeradas, HR

## Solução

### 1. Renderização Markdown
- Substituir renderMarkdown() por marked.js (CDN)
- Fix fallback chain: adicionar `resultado.resumo_executivo`

### 2. PDF (Dompdf server-side)
- `/api/pncp/export-pdf.php?id=N` — gera PDF com header Sunyata
- Parsedown converte markdown → HTML, Dompdf gera PDF
- Preview em modal Tabler com iframe

### 3. Email via N8N (sem duplicar SMTP)
- Novo workflow N8N: "Portal - Send Email" (webhook genérico)
- Recebe: {to, subject, html, attachment_name, attachment_base64}
- Portal gera PDF, envia base64 para N8N, N8N envia email
- Credenciais SMTP só no N8N (ponto único)

## Fluxo UX
1. Análise concluída → markdown renderizado bonito
2. [📄 PDF] → modal preview com iframe
3. No modal: [⬇️ Baixar] download direto, [📧 Email] → campo email (default: user logado)
4. [📧 Email] → portal gera PDF → POST para N8N → email enviado

## Dependências
- composer: dompdf/dompdf, erusev/parsedown
- CDN: marked.js
- N8N: novo workflow "Portal - Send Email"
