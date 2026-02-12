# Mensagem Inter-Agente

- **De:** Claude (Executor Principal)
- **Para:** Copilot (QA Frontend & Testes)
- **CC:** Filipe
- **Data:** 2026-02-12 16:31
- **Ref:** Licitacoes — Melhorias nas ferramentas (Filipe vai trabalhar contigo nisso)
- **Acao:** Melhorar ferramentas de licitacoes dentro da vertical IATR

---

## Contexto

O Filipe decidiu focar nas ferramentas de licitacoes e quer que voce o ajude a melhora-las. As ferramentas ja estao funcionais e deployadas na vertical IATR.

### Estado Atual

As 3 ferramentas de licitacoes foram movidas para a vertical IATR com categoria propria `licitacoes`:

| ID | Slug | Nome | Tipo | Categoria |
|----|------|------|------|-----------|
| 52 | licitacoes-resumo-executivo | Resumo Executivo de Edital | forms | licitacoes |
| 53 | licitacoes-habilitacao | Analise de Habilitacao | forms | licitacoes |
| 54 | licitacoes-monitor-pncp | Monitor PNCP | page | licitacoes |

Elas aparecem no IATR index (`/areas/iatr/`) na secao "Licitacoes" separada.

### Arquivos Relevantes

| Arquivo | Descricao |
|---------|-----------|
| `app/public/areas/iatr/index.php` | Menu IATR (mostra todas as tools incluindo licitacoes) |
| `app/public/areas/iatr/formulario.php` | Renderiza forms SurveyJS (usado por resumo-executivo e habilitacao) |
| `app/public/areas/iatr/monitor-pncp.php` | Pagina do Monitor PNCP (busca API PNCP) |
| `app/public/api/legal/pncp-search.php` | Backend API que o Monitor PNCP chama |
| DB `canvas_templates` IDs 52-54 | Templates com form_config JSON |

### O que pode ser melhorado

O Filipe vai te orientar diretamente sobre o que quer. Possiveis areas:

1. **UX dos formularios** — Layout, campos, validacao, textos de ajuda
2. **Monitor PNCP** — Filtros adicionais, visualizacao de resultados, detalhes expandiveis
3. **Novos templates** — Criar mais ferramentas de licitacoes
4. **form_config JSON** — Melhorar os formularios SurveyJS (perguntas, logica condicional, etc.)
5. **Testes Playwright** — Cobrir os fluxos de licitacoes

### Acesso

| Item | Valor |
|------|-------|
| **URL** | http://158.69.25.114 |
| **Admin login** | admin@sunyataconsulting.com / password |
| **Repo** | git@github.com:iflitaiff/plataforma-sunyata-v2.git |
| **VM100 SSH** | `ssh ovh 'ssh 192.168.100.10 "commands"'` |
| **DB** | PostgreSQL `sunyata_platform`, user `sunyata_app` |

### Nota Importante

Voce tambem tem a tarefa anterior (admin panel investigation — ver `20260212-1620-de-claude-para-copilot-admin-panel-investigation.md`). O Filipe priorizou licitacoes agora, entao foque nisso primeiro quando ele te acionar.

---

## Entrega

Coordenar diretamente com o Filipe. Ele vai te dizer o que quer melhorar.
