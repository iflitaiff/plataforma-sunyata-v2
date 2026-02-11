Assunto: Relatorio de intervencoes no V2 (VM100) + status atual

Resumo executivo
- Apliquei correcoes no V2 (VM100) para resolver paginas desformatadas e falhas do SurveyJS/HTMX.
- Ajustei BASE_URL no servidor para apontar para o IP de staging.
- Fiz deploys por SCP via Proxmox e copias com backups timestamp.
- Estado atual: IATR melhorou, mas “Meus Documentos” ainda sem area de upload no frontend (usuario reporta). Nao avancei alem do fix de HTMX/global.

Cronologia e mudancas aplicadas
1) VM100 snapshot antes de qualquer alteracao
   - qm snapshot 100 pre-fix-20260211-2135 (no Proxmox).

2) Ajuste HTMX partials no layout base
   - Arquivo: app/src/views/layouts/base.php
   - Mudanca: quando request tem HTTP_HX_REQUEST=true, retorna apenas partial (contentCallback), evitando HTML completo dentro de HTMX.
   - Deploy: copiado para /var/www/sunyata/app/src/views/layouts/base.php (backup .bak-YYYYMMDD-HHMM)

3) Refatoracao de IATR para usar layout base
   - Arquivos: app/public/areas/iatr/index.php, app/public/areas/iatr/formulario.php
   - Mudancas:
     - Removi <html>/<head>/<body> duplicados.
     - Usei layout user.php com $headExtra e $pageContent.
     - Adicionei validacao do JSON do SurveyJS (json_last_error) e erro amigavel.
   - Deploy: copiados para /var/www/sunyata/app/public/areas/iatr/* (backups .bak)

4) Forcar full load nos links IATR (evitar HTMX)
   - app/public/areas/iatr/index.php: adicionados hx-boost="false" nos cards.
   - app/public/areas/iatr/formulario.php: hx-boost="false" nos links principais.
   - app/src/views/components/user-sidebar.php: inicialmente desativei HTMX apenas para /areas/*; depois desativei para todos os links.
   - Deploy: copiados para VM100, backups .bak.

5) Desativar HTMX globalmente (ultima mudanca)
   - app/src/views/layouts/base.php: hx-boost="false" no <body> (antes true).
   - Objetivo: evitar qualquer partial em navegacao, ja que alguns endpoints retornavam apenas o corpo sem head e quebravam CSS/JS.

6) Configuracao de BASE_URL no servidor
   - Arquivo criado/ajustado: /var/www/sunyata/app/.env.local
   - Conteudo: BASE_URL=http://158.69.25.114

Observacoes/diagnostico
- Log de sshd no host Proxmox nao mostrou queda do servico; 2222 ficou indisponivel por motivo externo/intermitente.
- Em nginx access.log, requisicoes para /areas/iatr/formulario.php retornam 200 (tamanho ~8KB).
- A pagina /areas/iatr/formulario.php quando acessada sem login retorna 302 para index.php?m=login_required (esperado).
- php_errors.log continha erros antigos (16:27) relativos a VerticalManager::getVerticalData (antes do fix).

Backups criados
- /var/www/sunyata/app/src/views/layouts/base.php.bak-YYYYMMDD-HHMM
- /var/www/sunyata/app/public/areas/iatr/index.php.bak-YYYYMMDD-HHMM
- /var/www/sunyata/app/public/areas/iatr/formulario.php.bak-YYYYMMDD-HHMM
- /var/www/sunyata/app/src/views/components/user-sidebar.php.bak-YYYYMMDD-HHMM

Estado atual (para revisao)
- IATR: layout melhora; SurveyJS carrega com head completo.
- Sidebar: HTMX desativado globalmente.
- Meus Documentos: usuario reporta que area de upload nao aparece. Nao consegui reproduzir no servidor sem sessao; possivel problema de cache ou de HTMX inject (agora desativado). Ainda sem solucao.

Sugestoes para revisar
- Verificar por que /meus-documentos/ nao mostra upload (talvez CSS/JS ou HTMX nao carregando; checar devtools no browser).
- Avaliar se HTMX global deve ficar off permanentemente ou reconfigurar so para paginas seguras.
- Conferir que user-sidebar fix (getDisplayData) esta no servidor.

