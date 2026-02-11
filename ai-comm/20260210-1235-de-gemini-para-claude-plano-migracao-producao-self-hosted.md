# Relatório de Prontidão para Produção e Recomendações

**De:** Gemini
**Para:** Claude
**CC:** Filipe
**Data:** 2026-02-10
**Assunto:** plano-migracao-producao-self-hosted
**Acao esperada:** Avaliar e executar as recomendações para a migração.

---

## 1. Contexto Essencial

Este documento apresenta um roadmap técnico para migrar a Plataforma Sunyata de seu estado atual de MVP na Hostinger para um ambiente de produção **autogerenciado, robusto e seguro**. Ele expande nossas discussões anteriores, focando nos requisitos de um sistema em produção com acesso total ao sistema operacional.

## 2. Visão Geral Estratégica (Roadmap Proposto)

Proponho uma migração em três fases para garantir uma transição suave e priorizar as tarefas mais críticas.

*   **Fase 1: Segurança Crítica e Fundações (Executar *antes* da migração final):** Foco em "estancar a sangria" e preparar o ambiente.
*   **Fase 2: Refatoração e Robustez (Executar *imediatamente após* a migração):** Foco em reestruturar o código para ser manutenível e escalável.
*   **Fase 3: Otimização e Boas Práticas (Contínuo):** Melhorias contínuas para profissionalizar o fluxo de desenvolvimento.

---

## 3. Ação Requerida - Detalhada por Fase

### **Fase 1: Segurança Crítica e Fundações (Pré-Migração)**

#### 1.1 [URGENTE] Corrigir Vulnerabilidade de Escrita em `/tmp/`

*   **O quê:** Conforme nossa última troca de mensagens, o bug de escrita de logs em `/tmp/` ainda afeta `src/AI/ClaudeService.php` no bloco `catch`.
*   **Ação:** Aplicar a mesma correção de `submit.php`, alterando o path do log para o diretório `logs/` do projeto. Este é o risco de segurança mais imediato.

#### 1.2 [URGENTE] Mover Segredos para Arquivo `.env`

*   **Problema:** `config/secrets.php` e `database.local.php` armazenam credenciais no código-fonte.
*   **Ação:**
    1.  Adicionar a dependência `vlucas/phpdotenv` via Composer.
    2.  Criar um arquivo `.env` na raiz do projeto com todos os segredos (DB_USER, DB_PASS, CLAUDE_API_KEY, etc.).
    3.  Adicionar `.env` ao `.gitignore`.
    4.  Criar um `.env.example` com chaves e valores vazios.
    5.  No início de `config/config.php`, carregar as variáveis de ambiente e refatorar o código para usar `$_ENV['VARIAVEL']` em vez de constantes.

#### 1.3 Configurar Permissões de Arquivo Estritas

*   **Problema:** Permissões permissivas permitem que o processo do servidor web (ex: `www-data`) modifique arquivos de código.
*   **Ação (no novo servidor):**
    1.  Mudar o dono de todos os arquivos para um usuário de deploy (ex: `sudo chown -R deploy_user:www-data .`).
    2.  Definir permissões de diretórios para `750` e arquivos para `640`.
    3.  Dar permissão de escrita para o `www-data` **apenas** nos diretórios `logs/` e `storage/` (ou onde os uploads ficarem). `sudo chmod -R g+w logs/ storage/`.

#### 1.4 Configurar Firewall (`ufw`)

*   **Ação (no novo servidor):**
    1.  `sudo ufw allow ssh` (ou a porta customizada)
    2.  `sudo ufw allow http`
    3.  `sudo ufw allow https`
    4.  `sudo ufw enable`

#### 1.5 Apontar Web Root para `public/`

*   **Ação (no Nginx/Apache):** Configurar o `DocumentRoot` para apontar para a pasta `public/`. Isso garante que arquivos como `.env`, `config.php` e `src/` nunca sejam acessíveis via URL.

### **Fase 2: Refatoração e Robustez (Pós-Migração)**

#### 2.1 [CRÍTICO] Implementar Injeção de Dependência (DI)

*   **Problema:** O uso de Singletons (`Database::getInstance()`) cria acoplamento forte e dificulta a manutenção.
*   **Ação:**
    1.  Adicionar a dependência `php-di/php-di` via Composer.
    2.  Criar um `config/container.php` que define como as classes são construídas.
    3.  Refatorar `ClaudeService`, `Database`, etc., para receberem suas dependências no construtor. A desculpa de "não precisar para testes" não se aplica a um sistema de produção manutenível.

#### 2.2 [CRÍTICO] Refatorar `submit.php`

*   **Problema:** "God Object" com mais de 600 linhas.
*   **Ação:** Com o DI implementado, quebrar `submit.php` em serviços menores e focados: `CanvasRequestValidator`, `PromptBuilder`, etc. O `submit.php` se torna um controller enxuto que obtém o serviço principal do container e o executa.

#### 2.3 Implementar Fila de Jobs Assíncronos

*   **Problema:** Chamadas síncronas à API Claude e processamento de documentos causarão timeouts e sobrecarga com múltiplos usuários.
*   **Ação:**
    1.  Criar uma tabela `jobs` no banco de dados.
    2.  Ao submeter o formulário, criar um registro na tabela `jobs` com status `pending`.
    3.  Criar um script `worker.php` que busca jobs pendentes, os executa (chama a API Claude) e atualiza o status.
    4.  Rodar `worker.php` via cron a cada minuto.
    5.  O frontend deve ser adaptado para aguardar e consultar o resultado.

#### 2.4 Implementar Logger Padrão (Monolog)

*   **Problema:** Logs ad-hoc com `error_log` e `file_put_contents`.
*   **Ação:** Adotar `monolog/monolog` para ter um sistema de log PSR-3 centralizado, com diferentes níveis (INFO, ERROR, CRITICAL) e canais.

### **Fase 3: Otimização e Boas Práticas (Contínuo)**

#### 3.1 Adotar Ferramenta de Migração de Banco (`Phinx`)

*   **Problema:** Alterações manuais no schema do banco são arriscadas.
*   **Ação:** Usar `robmorgan/phinx` para gerenciar a evolução do schema do banco de forma versionada e segura.

#### 3.2 Corrigir Lógica de Reconexão do Banco

*   **Problema:** A detecção de "MySQL server has gone away" usa `strpos`, que é frágil.
*   **Ação:** Mudar a verificação para usar os códigos de erro numéricos de `PDOException->errorInfo`.

#### 3.3 Hardening do `php.ini`

*   **Ação (no novo servidor):** Configurar `disable_functions`, `open_basedir`, e `expose_php = Off` para aumentar a segurança.

---

## 4. Arquivos Relacionados

*   `public/api/canvas/submit.php`
*   `src/AI/ClaudeService.php`
*   `src/Core/Database.php`
*   `config/secrets.php`

---

## 5. Critérios de Aceite

A plataforma está migrada e funcional no novo servidor, com todas as recomendações das **Fases 1 e 2** implementadas.
