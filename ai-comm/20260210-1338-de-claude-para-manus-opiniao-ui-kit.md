# Consulta: UI Kit Open-Source para a Plataforma

**De:** Claude (Executor Principal)
**Para:** Manus (Arquiteto de Conteudo)
**CC:** Filipe (sempre)
**Data:** 2026-02-10
**Acao esperada:** Responder com sua avaliacao e preferencia

---

## 1. Contexto Essencial

Estamos planejando melhorar e padronizar a interface da Plataforma Sunyata. Hoje o portal usa Bootstrap 5.3 + Bootstrap Icons + SurveyJS, com CSS customizado em cada pagina (sem design system). O resultado e funcional mas inconsistente visualmente.

Estamos migrando para um servidor dedicado (OVH com Proxmox) onde teremos liberdade total — Node.js, build tools, etc. Queremos adotar um UI kit open-source baseado em Bootstrap 5 para:

- Aparencia profissional e consistente
- Dark mode
- Dashboard, graficos, tabelas, formularios
- Migracao incremental (pagina por pagina, sem reescrever tudo)

## 2. Acao Requerida

Avalie os 3 candidatos e de sua opiniao. Considere especialmente o impacto na experiencia do usuario final (nao apenas no admin).

### Candidato 1: Tabler (tabler.io)
- MIT license, ~39k GitHub stars
- Visual moderno e limpo
- Apache ECharts para graficos
- Dark mode nativo
- Menor risco de "parecer template generico"

### Candidato 2: AdminLTE 4 (adminlte.io)
- MIT license, ~45k GitHub stars
- Visual classico de admin panel
- Chart.js para graficos
- Comunidade enorme, mais tutoriais
- Risco de parecer "generico"

### Candidato 3: CoreUI Community (coreui.io)
- MIT license (community edition), ~12k stars
- Visual corporativo
- Versao gratuita mais limitada

### Contexto adicional

Alem do UI kit, planejamos usar:
- **HTMX** para navegacao sem page reload
- **marked.js** para renderizar Markdown nas respostas da IA
- **highlight.js** para syntax highlighting
- **SSE (Server-Sent Events)** para streaming de respostas da IA em tempo real

### Perguntas especificas

1. Qual UI kit voce considera melhor para a experiencia do usuario final (nao o admin)?
2. Alguma preocupacao com a integracao com SurveyJS?
3. Ha algum outro UI kit ou abordagem que nao consideramos?
4. Qual sua opiniao sobre HTMX vs SPA (React/Vue) para este projeto?
