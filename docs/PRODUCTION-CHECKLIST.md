# Plataforma Sunyata v2 — Production Deployment Checklist

Este documento consolida os passos necessários para fazer deploy de novas versões do Portal Sunyata v2, garantindo a integridade dos 4 componentes da infraestrutura (Portal PHP, N8N, FastAPI e LiteLLM).

**Ambiente de Produção (VM100)**
- IP: `192.168.100.10`
- DB: PostgreSQL 16 (`sunyata_platform` / user: `sunyata_app`)
- Microserviços: N8N (CT104 `192.168.100.14:5678`), LiteLLM (CT103 `192.168.100.13:4000`), FastAPI (`127.0.0.1:8000`)

---

## 1. Pré-Deploy

Antes de atualizar o código na VM100, execute estes testes locais ou na branch de staging para garantir que as migrações estão prontas e a infraestrutura remota responde.

- [ ] Executar dry-run das migrations para ver o que será alterado no banco:
  ```bash
  ./tools/migrate.sh --dry-run
  ```
- [ ] Aplicar as migrations pendentes no banco de dados da VM100:
  ```bash
  ./tools/migrate.sh --yes
  ```
- [ ] Conectar ao host e garantir que os túneis de rede essenciais estão rodando e saudáveis:
  ```bash
  ./tools/ssh-cmd.sh vm100 "systemctl --user status sunyata-tunnels"
  ```
- [ ] Garantir que o microserviço FastAPI (Extração PDF/Enrichment) está ativo:
  ```bash
  ./tools/ssh-cmd.sh vm100 "curl -s --max-time 5 http://127.0.0.1:8000/health && echo 'FastAPI UP' || echo 'FastAPI DOWN'"
  ```
- [ ] Verificar integridade dos fluxos no N8N:
  - Workflow IATR (`4HJSmPLYTNTUnO8y`) ativado.
  - Workflow Monitor PNCP (`kWX9x3IteHYZehKC`) ativado.
  - Workflow Email Sender (`rWDYKMY0Wav5dMpH`) ativado.

---

## 2. Deploy (VM100)

O processo de deploy do código do portal se resume a puxar as atualizações da branch principal (`main` ou `production`) diretamente na raiz web do Nginx (`/var/www/sunyata` ou equivalente dependendo do setup).

- [ ] Acessar a VM100 via SSH ou via script e executar `git pull`:
  ```bash
  ./tools/ssh-cmd.sh vm100 "cd /var/www/sunyata/app && git pull"
  ```
- [ ] (Opcional, se houver pacotes composer novos) Atualizar dependências:
  ```bash
  ./tools/ssh-cmd.sh vm100 "cd /var/www/sunyata/app && composer install --no-dev --optimize-autoloader"
  ```

---

## 3. Verificação Pós-Deploy

Após a atualização do código, faça o QA (Garantia de Qualidade) ponta-a-ponta para certificar que nada quebrou na comunicação dos serviços.

- [ ] **Sessão:** Acessar o portal e garantir que o login funciona corretamente.
- [ ] **IATR Trigger:** Entrar no detalhe de um edital (`/areas/iatr/edital.php?id=X`) e disparar a IA. O proxy PHP deve retornar `200 OK` (sem erros `403` ou `502`).
- [ ] **System Events:** Acessar `/areas/admin/system-logs.php` (como Admin) e validar se o sistema logou as etapas corretamente (requested, dispatched, extracted, etc.) usando o `trace_id`.
- [ ] **Monitor:** Confirmar que os emails do Monitor PNCP estão sendo enviados e o cronjob do LiteLLM (CT104) roda sem travar.
- [ ] **Orfãos de Análise:** Verificar no DB se nenhum edital ficou travado com status incompleto durante o deploy:
  ```bash
  ./tools/ssh-cmd.sh vm100 "psql -U sunyata_app -d sunyata_platform -c \"SELECT count(*) FROM pncp_editais WHERE status_analise = 'em_analise';\""
  ```
  *(Se algum ficar permanentemente preso, rode o rollback abaixo).*

---

## 4. Rollback & Troubleshooting

Se algo falhar gravemente (ex: tela branca, N8N quebrando nas queries de retorno, erro de sintaxe nos includes), siga os passos abaixo para reversão.

### Reverter Migrations
Se uma migration de banco quebrou o funcionamento, use a string `-- ROLLBACK` documentada no final do `.sql` respectivo para desfazer os comandos manualmente.
```bash
./tools/ssh-cmd.sh vm100 "psql -U sunyata_app -d sunyata_platform -c 'DROP COLUMN X;'"
```

### Limpar Editais Presos ("Em Análise")
Se o deploy ou a queda de túnel travou a flag da IA em loop infinito num edital, libere-o via DB:
```bash
./tools/ssh-cmd.sh vm100 "psql -U sunyata_app -d sunyata_platform -c \"UPDATE pncp_editais SET status_analise = 'pendente' WHERE status_analise = 'em_analise';\""
```

### Cron Diário e Espaço em Disco
A tabela `system_events` cresce diariamente. Verifique se o cron de limpeza está na crontab do `postgres` ou do sistema (VM100) apontando para o script `/cleanup-system-events.sh` ou executando o expurgo diário `> 90 dias`.
