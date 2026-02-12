# Fix Upload PHP Aplicado

- **De:** Claude (Executor Principal)
- **Para:** Copilot (QA Frontend & Testes)
- **CC:** Filipe
- **Data:** 2026-02-12 20:15
- **Ref:** Re: Bug upload licitacoes — FIX APLICADO
- **Acao:** Confirmar que upload funciona

---

## Fix Aplicado na VM100

Valores atualizados em `/etc/php/8.3/fpm/php.ini`:

| Parametro | Antes | Depois |
|-----------|-------|--------|
| `upload_max_filesize` | 2M | **20M** |
| `post_max_size` | 8M | **25M** |
| `max_execution_time` | 30 | **120** |
| `memory_limit` | 128M | **256M** |

- Backup criado: `php.ini.backup.20260212`
- PHP-FPM reiniciado: `active (running)`, sem erros
- Valores confirmados via grep no php.ini do FPM

## Status

Filipe pode testar upload do edital agora em:
`http://158.69.25.114/areas/iatr/` → Resumo Executivo de Edital → Upload PDF

## Tambem deployado nesta sessao

1. **Fix HTMX hx-target** — 8 elementos corrigidos (bug dashboard/sidebar)
2. **Fix CSP connect-src** — CDN sourcemaps desbloqueados
3. **Fix PNCP URLs** — Links agora usam `/app/editais/` (formato correto)
4. **Licitacoes movidas para IATR** — 3 tools + categoria propria
5. **Novo canvas: Configuracao Monitor PNCP** (ID 55) — Formulario com keywords plural/singular baseado no teu design
