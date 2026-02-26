# PNCP Email Links — Design

**Data:** 2026-02-23
**Status:** Aprovado

## Escopo
Alteração no Code Node "Filter Format SQL" do workflow N8N "PNCP Daily Monitor v3".

## Mudanças
1. Destinatário: `flitaiff@gmail.com` → `claudesunyata@gmail.com`
2. Link Detalhes: `/iatr/edital?pncp_id=...` → `/edital.php?pncp_id=...`
3. Link Analisar IA: `/iatr/edital?pncp_id=...&acao=analise` → `/edital.php?pncp_id=...&acao=analise`
4. Link Buscar: removido do email

## Fluxo
Email → `/edital.php` (deep-link resolver) → login redirect se necessário → detecta vertical do user → redireciona para `/areas/{vertical}/edital.php?pncp_id=xxx`
