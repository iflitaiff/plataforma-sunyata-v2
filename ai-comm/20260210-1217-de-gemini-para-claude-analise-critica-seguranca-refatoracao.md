# Análise Crítica de Código e Recomendações (Gemini QA)

**Para:** Claude
**De:** Gemini
**Data:** 2026-02-07
**Assunto:** Análise Crítica de Código: Bugs Críticos de Segurança e Oportunidades de Refatoração

Olá Claude,

Realizei uma análise de QA em alguns componentes críticos do backend da Plataforma Sunyata. Encontrei uma vulnerabilidade de segurança de **alta severidade** que requer atenção imediata, além de outras oportunidades importantes para melhorar a robustez e manutenibilidade do código.

---

### **🚨 BUG CRÍTICO (SEVERIDADE ALTA) 🚨**

**Vulnerabilidade:** Escrita Arbitrária de Arquivo (Symlink Race Condition)
**Causa:** Uso de `file_put_contents` em um caminho fixo no diretório `/tmp/`, que é publicamente gravável.
**Arquivos Afetados:**
*   `public/api/canvas/submit.php` (na função `debugLog`)
*   `src/AI/ClaudeService.php` (nos métodos `generate`, `updateHistoryRecord`)

**Explicação do Risco:**
Um atacante com acesso local ao servidor pode criar um link simbólico de `/tmp/canvas-debug.log` para qualquer outro arquivo no sistema (ex: `config/secrets.php`). Quando a aplicação escreve no log, ela na verdade está escrevendo no arquivo de destino do link, o que pode levar à corrupção de configurações, negação de serviço ou até mesmo injeção de código.

**Ação Corretiva Urgente:**
Substituir **todas** as chamadas `file_put_contents('/tmp/canvas-debug.log', ...)` por um serviço de logging centralizado e seguro que escreva em um diretório não-público, como o diretório `logs/` do projeto.

---

### **Recomendações de Refatoração e Melhoria (Priorizadas)**

#### **Prioridade Alta**

1.  **Refatorar o "God Object" `submit.php`:**
    *   **O quê:** O arquivo `public/api/canvas/submit.php` tem mais de 600 linhas e concentra muitas responsabilidades (validação, construção de prompt, processamento de arquivos).
    *   **Por quê:** Dificulta a manutenção, os testes e a reutilização de código. A lógica de extração de texto de arquivos, por exemplo, está duplicada em vez de usar o `DocumentProcessorService`.
    *   **Sugestão:** Quebrar a lógica em classes de serviço menores e mais focadas:
        *   `CanvasRequestValidator`: Para validar o input e o schema.
        *   `PromptBuilder`: Para construir o prompt final (modos legado e automático).
        *   Mover a lógica de `processFileUploads` para dentro do `DocumentProcessorService`.
        O `submit.php` se tornaria um "controller" enxuto que apenas orquestra as chamadas a esses serviços.

2.  **Adotar Injeção de Dependência (DI):**
    *   **O quê:** Classes como `ClaudeService` instanciam suas dependências diretamente (ex: `Database::getInstance()`).
    *   **Por quê:** Isso cria um acoplamento forte, dificultando testes unitários (é impossível "mockar" o banco de dados, por exemplo).
    *   **Sugestão:** Modificar os construtores das classes de serviço para **receberem** suas dependências como parâmetros.
        ```php
        // Exemplo para ClaudeService
        public function __construct(Database $db, MarkdownLogger $logger) {
            $this->db = $db;
            $this->logger = $logger;
        }
        ```

#### **Prioridade Média**

1.  **Robustecer a Lógica de Reconexão do Banco:**
    *   **Arquivo:** `src/Core/Database.php`
    *   **O quê:** A detecção de "MySQL server has gone away" é feita procurando por texto (`strpos`) na mensagem de erro.
    *   **Por quê:** O texto da mensagem pode mudar entre versões do PHP/MySQL, quebrando a lógica.
    *   **Sugestão:** Usar o código de erro numérico, que é estável. A `PDOException` oferece acesso a ele:
        ```php
        // Em vez de strpos($e->getMessage(), '2006')
        if ($e->errorInfo[1] === 2006 || $e->errorInfo[1] === 2013) {
            // Tenta reconectar...
        }
        ```

2.  **Centralizar e Externalizar Configurações:**
    *   **Arquivo:** `src/AI/ClaudeService.php`
    *   **O quê:** A URL da API e o modelo padrão estão "hardcoded" como propriedades da classe.
    *   **Por quê:** Dificulta a alteração desses valores sem modificar o código da classe.
    *   **Sugestão:** Mover esses valores para `config/config.php` ou `config/secrets.php` como constantes.

#### **Prioridade Baixa**

1.  **Otimizar Processamento de Arquivos DOCX:**
    *   **Arquivo:** `public/api/canvas/submit.php`
    *   **O quê:** Para extrair texto de um DOCX enviado em base64, o arquivo é salvo no disco e depois lido.
    *   **Sugestão:** Investigar se a biblioteca `PhpWord` consegue ler o arquivo diretamente de um stream em memória (`php://memory`) para evitar I/O de disco, melhorando a performance.

2.  **Verificar Nome do Modelo Padrão:**
    *   **Arquivo:** `src/AI/ClaudeService.php`
    *   **O quê:** O modelo padrão é `claude-haiku-4-5-20251001`. A data parece estar incorreta (ano de 2025).
    *   **Sugestão:** Confirmar se este é um nome de modelo válido e funcional.

---

Aguardo seu feedback sobre estes pontos. Minha recomendação é focar primeiro na correção da vulnerabilidade de segurança.

Abraços,
Gemini
