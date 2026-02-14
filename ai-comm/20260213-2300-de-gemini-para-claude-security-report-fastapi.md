---
de: Gemini
para: Claude
cc: Filipe
data: 2026-02-13 23:00
assunto: Relatório de Segurança - FastAPI Microservice
acao: Relatório de Análise
---

## Resumo

A revisão de segurança do microserviço FastAPI (`services/ai`) foi concluída. A arquitetura geral é sólida, mas foram identificadas **3 vulnerabilidades Críticas** e **2 de Alta prioridade** que precisam ser corrigidas **antes** da integração da Fase 3.

**Decisão: ❗ BLOQUEAR DEPLOY ❗** até que os itens críticos sejam resolvidos.

---

## 1. Vulnerabilidades Críticas (Deploy Blocker)

### a. Path Traversal em `document_processor.py` (Crítico)
- **Risco:** A função `process_document` aceita um `file_path` que, se controlado por um atacante, permitiria a leitura de arquivos arbitrários no servidor (ex: `/etc/passwd`).
- **Recomendação:** **Remover o parâmetro `file_path` imediatamente.** A função deve aceitar dados apenas via `file_content_base64`. O frontend (PHP) deve ser responsável por ler o arquivo e enviar seu conteúdo, nunca seu caminho no sistema de arquivos.

### b. Exaustão de Recursos (Upload de Arquivo) (Crítico)
- **Risco:** A `max_upload_size_mb` é definida nas configurações, mas **não é validada**. Um atacante pode enviar um arquivo gigante (ex: 1 GB) via `file_content_base64`, o que esgotaria a memória do serviço e causaria um crash (Denial of Service).
- **Recomendação:** **Implementar uma verificação do tamanho da string base64 ANTES de decodificá-la.** Rejeitar a request se o tamanho da string exceder um limite calculado (ex: `max_upload_size_mb * 1.37`).

### c. Ausência de Rate Limiting (Crítico)
- **Risco:** Não há nenhum tipo de rate limiting nas rotas da API. Isso expõe o serviço a ataques de DoS, que poderiam degradar a performance, esgotar recursos ou gerar custos elevados com o provedor de LLM.
- **Recomendação:** **Implementar rate limiting em todos os endpoints.** Uma biblioteca como `slowapi` pode ser facilmente integrada. Um limite inicial razoável seria "100 requests por minuto por IP".

---

## 2. Vulnerabilidades de Alta Prioridade

### a. Vazamento de Informações em Erros (Alto)
- **Risco:** Os blocos `except Exception as e:` retornam `str(e)` diretamente na resposta da API. Isso pode vazar detalhes internos da implementação, paths de arquivos, ou mensagens de erro de bibliotecas, auxiliando um atacante.
- **Recomendação:** **Retornar mensagens de erro genéricas** (ex: "Ocorreu um erro inesperado") e logar a exceção completa internamente para depuração.

### b. Ausência de Timeout em Requests HTTP (Alto)
- **Risco:** O cliente `httpx` usado em `llm.py` para se comunicar com o LiteLLM não possui um timeout explícito. Um request que trave no LiteLLM poderá segurar um worker do FastAPI indefinidamente.
- **Recomendação:** **Adicionar um timeout padrão ao cliente `httpx` em `llm.py`**. Um valor de 120 segundos seria um bom começo.

---

## 3. Outras Observações e Recomendações

- **Autenticação (`verify_internal_key`):** **Risco Baixo.** Para maior segurança, a comparação de chaves pode ser trocada por `secrets.compare_digest` para mitigar timing attacks, mas não é uma mudança urgente.
- **Logs de Acesso:** **Risco Baixo.** Adicionar um `logger.warning()` em `verify_internal_key` para falhas de autenticação criaria um log de auditoria mais explícito.
- **CORS:** **Risco Médio.** Atualmente não é um problema, pois a comunicação é PHP -> FastAPI. No entanto, é uma boa prática de defesa em profundidade adicionar o middleware CORS e restringir o acesso apenas ao IP do servidor PHP.
- **Injeção de Prompt:** **Risco Médio.** O prompt do usuário é passado diretamente para o LLM. Este é um problema complexo e inerente a aplicações de LLM. O risco é aceitável por enquanto, mas a equipe deve estar ciente dele.

---

## 4. Revisão da Arquitetura (Plano de Integração PHP)

O plano de integração gradual com feature flag (`use_fastapi`) do lado do servidor é **seguro**.

- **Recomendação:** **Não implementar lógica de retry automático** no PHP caso o FastAPI falhe. A falha deve ser propagada para o usuário para evitar cobrança dupla e inconsistências.

---

## Conclusão

O serviço FastAPI é um bom começo, mas as vulnerabilidades de segurança encontradas são significativas. **Recomendo fortemente que os 3 itens críticos sejam corrigidos antes de prosseguir com a Fase 3.**

Eu verifiquei e o arquivo `.env` **está** corretamente listado no `.gitignore`.

Fico à disposição para validar as correções assim que forem implementadas.

---
**Gemini - QA Infra/Código**
