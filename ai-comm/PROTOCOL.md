# ai-comm Protocol v1.1

Protocolo de comunicação entre agentes da Plataforma Sunyata.
Todo agente DEVE ler este arquivo antes de operar no ai-comm.

---

## Agentes

| ID | Nome | Papel | Cor |
|----|------|-------|-----|
| `claude` | Claude | Executor Principal | Azul |
| `gemini` | Gemini | QA Infra/Codigo | Amarelo |
| `manus` | Manus | Arquiteto de Conteudo | Roxo |
| `codex` | Codex | QA Dados/Templates | Verde |
| `copilot` | Copilot | QA Frontend & Testes | Ciano |
| `filipe` | Filipe | Product Owner (humano) | Laranja |

---

## Diretorio

**Unico e flat:** `/home/u202164171/ai-comm/` (servidor Hostinger)

- Todas as mensagens ficam na raiz deste diretorio
- NAO criar subdiretorios (inbox/, outbox/, etc.)
- Copia local (dev): `ai-comm/` na raiz de cada repositorio

---

## Nomes de Arquivo

```
YYYYMMDD-HHMM-de-ORIGEM-para-DESTINO-assunto.md
```

Regras:
- Tudo lowercase, separado por hifens
- Assunto: maximo 4 palavras
- ORIGEM e DESTINO: usar IDs da tabela acima
- Para multiplos destinatarios: `para-claude-gemini`

Exemplos:
```
20260210-1043-de-claude-para-manus-opiniao-ai-comm.md
20260210-1400-de-manus-para-claude-resposta-ai-comm.md
20260212-1500-de-copilot-para-claude-bug-htmx-sidebar.md
```

---

## Formato da Mensagem

**OBRIGATORIO:** Toda mensagem DEVE comecar com titulo H1 seguido do cabecalho abaixo.
O sistema de monitoramento depende deste formato para identificar remetente e destinatario.
Mensagens sem cabecalho aparecem com campos vazios nas notificacoes por email.

```markdown
# [Titulo Claro e Direto]

**De:** [claude|gemini|manus|codex|copilot|filipe]
**Para:** [claude|gemini|manus|codex|copilot] (um ou mais)
**CC:** Filipe (sempre)
**Data:** YYYY-MM-DD
**Ref:** [arquivo referenciado, se houver]
**Acao esperada:** [Avaliar|Responder|Executar|Revisar|Informativo]

---

## 1. Contexto Essencial

O que o destinatario precisa saber para entender a situacao.
Deve ser autocontido — o destinatario pode nao ter contexto de sessoes anteriores.

## 2. Acao Requerida

Instrucoes claras e objetivas sobre o que precisa ser feito.
Se houver multiplas tarefas, numerar cada uma.

## 3. Arquivos Relacionados (quando aplicavel)

Lista de arquivos no ai-comm ou no repositorio que sao relevantes.

- `ai-comm/arquivo1.md`
- `src/path/to/file.php`

## 4. Criterios de Aceite (quando aplicavel)

Como saber se a tarefa foi concluida com sucesso.
```

### Regras do formato

1. **Cabecalho OBRIGATORIO** — De, Para, CC, Data, Acao esperada. Sem excecao.
2. **CC Filipe sempre** — toda mensagem inclui Filipe como CC
3. **Autocontido** — nao assumir que o destinatario tem contexto de sessoes anteriores
4. **Acao esperada** — sempre declarar o que se espera do destinatario
5. **Secoes opcionais** — secoes 3 e 4 podem ser omitidas se nao aplicaveis
6. **Portugues** — usar `**De:**` e `**Para:**`, nunca `**From:**` ou `**To:**`

---

## Como cada agente opera

### Claude / Codex / Copilot (acesso local + SSH)
```bash
# Escrever: cria localmente e copia para servidor
scp -P 65002 ai-comm/ARQUIVO.md u202164171@82.25.72.226:/home/u202164171/ai-comm/

# Ler: acessa servidor diretamente
ssh -p 65002 u202164171@82.25.72.226 "ls -lt /home/u202164171/ai-comm/ | head -10"
ssh -p 65002 u202164171@82.25.72.226 "cat /home/u202164171/ai-comm/ARQUIVO.md"
```

### Gemini / Manus (via Filipe)
- Filipe compartilha o conteudo da mensagem na sessao do agente
- Agente produz a resposta no formato padrao
- Filipe salva no servidor ou pede ao agente para salvar (se tiver acesso HTTP)

### Notificacao
- Cron no servidor (`monitor-aicomm.sh` v4.1) detecta novos `.md` na raiz
- Envia email formatado para Filipe automaticamente
- Campos De/Para sao extraidos do cabecalho — formato incorreto resulta em fallback
- Fallback: tenta `**From:**`/`**To:**` (ingles), depois extrai do nome do arquivo

---

## Arquivos especiais (ignorados pelo cron)

- `PROTOCOL.md` — este arquivo
- `README.md` — documentacao geral
- `monitor-aicomm.sh` — script de monitoramento
- `.last_*`, `.monitor.log` — controle interno

---

## Historico

| Versao | Data | Descricao |
|--------|------|-----------|
| 1.0 | 2026-02-10 | Versao inicial — consenso Claude + Manus |
| 1.1 | 2026-02-12 | Adicionados Codex e Copilot. Cabecalho OBRIGATORIO. Fallback no monitor. |
