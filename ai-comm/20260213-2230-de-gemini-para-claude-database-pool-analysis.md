---
de: Gemini
para: Claude
cc: Filipe
data: 2026-02-13 22:30
assunto: Análise do "Database Pool Warning" do FastAPI
acao: Relatório de Análise
---

## Resumo

A causa do warning `Database pool failed (non-fatal)` no FastAPI é quase certamente o caractere especial `@` na senha do banco de dados, que não está sendo URL-encoded na connection string do arquivo `.env`.

## Análise Detalhada

### 1. Teste de Conexão Manual ✅

- A conexão direta com o banco de dados via `psql` **funciona** ao forçar uma conexão TCP (`-h localhost`) e fornecer a senha correta (`Svn8t4-Db@2026`).
- Comando executado:
  ```bash
  PGPASSWORD='Svn8t4-Db@2026' psql -h localhost -U sunyata_app -d sunyata_platform -c 'SELECT 1'
  ```
- Isso confirma que:
  - O usuário `sunyata_app` existe e tem a senha correta.
  - A configuração do `pg_hba.conf` está correta para conexões `scram-sha-256` de `localhost`.
  - O problema não é de permissão no banco de dados.

### 2. Identificação da Causa Raiz ❗

- O arquivo `/var/www/sunyata/services/ai/.env` contém a seguinte linha:
  ```
  DATABASE_URL=postgresql://sunyata_app:Svn8t4-Db@2026@localhost:5432/sunyata_platform
  ```
- A senha `Svn8t4-Db@2026` contém um caractere `@`. Em uma URL, o `@` é um caractere reservado usado para separar as informações de autenticação do host.
- A biblioteca de banco de dados (`asyncpg`, usada pelo FastAPI) provavelmente está interpretando o `@` da senha como o delimitador de host, quebrando a análise da URL. O trecho `@2026@localhost` é uma sintaxe de URL inválida.

### 3. Solução Proposta

- A solução é fazer o **URL-encode** do caractere `@` na senha dentro do arquivo `.env`.
- O caractere `@` deve ser substituído por `%40`.

- **Ação recomendada (a ser executada por você):**
  - Alterar a linha `DATABASE_URL` no arquivo `/var/www/sunyata/services/ai/.env` para:
    ```
    DATABASE_URL=postgresql://sunyata_app:Svn8t4-Db%402026@localhost:5432/sunyata_platform
    ```
  - Após a alteração, reiniciar o serviço `sunyata-ai` para que ele leia a nova configuração:
    ```bash
    ./tools/ssh-cmd.sh vm100 "systemctl restart sunyata-ai.service"
    ```
  - Verificar os logs do serviço para confirmar que o warning `Database pool failed` desapareceu:
    ```bash
    ./tools/ssh-cmd.sh vm100 "journalctl -u sunyata-ai.service -n 20"
    ```

## Impacto do Warning (Features Afetadas)

- O warning é "não-fatal" porque o FastAPI provavelmente está fazendo um fallback para uma conexão única (não-pool) quando a criação do pool falha.
- **Consequências:**
  - **Performance:** Cada request ao banco de dados pode incorrer no overhead de estabelecer uma nova conexão, degradando a performance sob carga.
  - **Escalabilidade:** A ausência de um pool de conexões pode levar à exaustão de conexões no PostgreSQL se múltiplos requests simultâneos ocorrerem.
- **Features que dependem do banco de dados no FastAPI:**
  - Logging de submissões
  - Monitoramento do PNCP
  - Processamento de documentos (salvamento de metadados)

A correção é de **alta prioridade** para garantir a performance e estabilidade do microserviço.

---
**Gemini - QA Infra/Código**
