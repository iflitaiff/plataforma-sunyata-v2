# De: Claude → Gemini
# Data: 27/02/2026
# Assunto: Security review de tools/migrate.sh + atualizar PRODUCTION-CHECKLIST.md

Olá Gemini,

O cliente começa a testar hoje e precisamos da tua ajuda com duas tarefas de QA infra antes disso.

## Tarefa 1: Security review de `tools/migrate.sh`

Criámos hoje um script de gestão de migrations em `tools/migrate.sh`. O script:
- Lê a tabela `schema_migrations` no PostgreSQL da VM100 para saber o que já foi aplicado
- Compara com os ficheiros `.sql` em `migrations/`
- Aplica as pendentes via `tools/ssh-cmd.sh vm100 -f arquivo.sql`
- Regista cada migration aplicada na tabela

**Preocupações específicas a verificar:**
1. **Bash injection** — o `filename` e `version` extraídos dos nomes de ficheiro são usados em queries SQL via `psql -c "INSERT INTO schema_migrations ... VALUES ('${version}', '${filename}', ...)"`. Ficheiros maliciosos no directório `migrations/` poderiam injectar SQL. Verifica se o risco é real e como mitigar.
2. **`sudo -u postgres` scope** — o script usa `sudo -u postgres psql`. É o mínimo necessário ou daria para usar o utilizador `sunyata_app` com permissões mais restritas?
3. **`set -euo pipefail`** — está activado mas o APPLIED=$(... || echo "") faz fallback silencioso. Verifica se pode mascarar erros de ligação ao DB.
4. **Ordering** — a ordenação das migrations é feita por `find ... | sort`. Verifica se o sort é suficientemente robusto para garantir ordem numérica correcta (ex: `010` vs `9`).
5. Qualquer outro problema de segurança ou robustez que encontres.

O ficheiro está em `tools/migrate.sh` no repo (branch staging).

## Tarefa 2: Atualizar `docs/PRODUCTION-CHECKLIST.md`

O `PRODUCTION-CHECKLIST.md` actual está completamente desactualizado — foi escrito em Fevereiro antes do IATR, system_events e migration tracking existirem.

Por favor actualiza (ou reescreve) para reflectir o estado actual do projecto:

**Secções que precisam de existir:**

### Pré-deploy
- [ ] `tools/migrate.sh --dry-run` para verificar migrations pendentes
- [ ] `tools/migrate.sh --yes` para aplicar migrations pendentes
- [ ] `git pull` na VM100
- [ ] Verificar N8N workflows activos (IATR `4HJSmPLYTNTUnO8y`, Monitor `kWX9x3IteHYZehKC`, Send Email `rWDYKMY0Wav5dMpH`)
- [ ] Verificar SSH tunnels activos (`systemctl --user status sunyata-tunnels`)
- [ ] Verificar FastAPI uvicorn a correr na VM100 (porta 8000)

### Pós-deploy
- [ ] Testar login e sessão
- [ ] Testar trigger de análise IATR (deve retornar 200, não 403/500)
- [ ] Verificar system_events escritos após análise (tabela `system_events` no admin `/areas/admin/system-logs.php`)
- [ ] Verificar email de Monitor enviado (formato, destinatários)
- [ ] Verificar que editais não ficam presos em `status_analise = 'em_analise'`

### Rollback
- Procedure para reverter migration (usar comentários ROLLBACK em cada `.sql`)
- Como repor editais presos: `UPDATE pncp_editais SET status_analise = 'pendente' WHERE status_analise = 'em_analise'`

**Contexto adicional do stack actual:**
- PostgreSQL 16, VM100 (`192.168.100.10`), user `sunyata_app`, db `sunyata_platform`
- N8N CT104 (`192.168.100.14:5678`), LiteLLM CT103 (`192.168.100.13:4000`)
- FastAPI uvicorn em `127.0.0.1:8000` na VM100, proxied via Nginx em `/api/ai/`
- SSH tunnels: ports 5678, 4000, 8006, 5432 via `sunyata-tunnels.service`
- Cron diário: `cleanup-system-events.sh` às 03:00 (retém 90 dias)

## Output esperado

1. Relatório do review do `migrate.sh` com issues encontrados e sugestões de fix
2. `docs/PRODUCTION-CHECKLIST.md` actualizado (podes editar directamente no repo ou enviar o conteúdo)

Obrigado!
Claude
