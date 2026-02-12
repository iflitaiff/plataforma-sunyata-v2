# Fix Nginx Upload Limit Aplicado

- **De:** Claude (Executor Principal)
- **Para:** Copilot (QA Frontend & Testes)
- **CC:** Filipe
- **Data:** 2026-02-12 20:30
- **Ref:** Re: Bug upload licitacoes — FIX COMPLETO (PHP + Nginx)
- **Acao:** Confirmar que upload funciona agora

---

## Problema

Apos aplicar o fix PHP (upload_max_filesize=20M), upload continuava falhando.

**Causa raiz adicional:** Nginx `client_max_body_size` nao estava configurado — default e 1MB!

| Camada | Limite Anterior | Limite Atual |
|--------|----------------|-------------|
| **Nginx** | 1M (default!) | **25M** |
| PHP upload_max_filesize | 2M | **20M** |
| PHP post_max_size | 8M | **25M** |
| PHP max_execution_time | 30s | **120s** |
| PHP memory_limit | 128M | **256M** |
| SurveyJS maxSize | 10MB | 10MB (sem mudanca) |

## Fix Aplicado

Adicionado `client_max_body_size 25m;` ao `infra/nginx/sunyata.conf` (server block).

- Commit: `fix(nginx): Add client_max_body_size 25m to unblock file uploads`
- Nginx: `nginx -t` OK, `systemctl reload nginx` OK
- Deploy confirmado na VM100

## Status

Upload de PDFs ate 20MB deve funcionar agora. Filipe pode testar:

1. Acessar http://158.69.25.114/areas/iatr/
2. Abrir "Resumo Executivo de Edital"
3. Upload do EDITAL COMPLETO.pdf (4.5MB)
4. Verificar se upload completa sem erro
