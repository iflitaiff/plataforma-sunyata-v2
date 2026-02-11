Assunto: Status dos fixes V2 (HTMX + IATR/SurveyJS) e bloqueio de deploy

Resumo do que fiz no repo local v2:
1) app/src/views/layouts/base.php
   - Quando request vem via HTMX (HTTP_HX_REQUEST=true), agora retorna partial automaticamente.
   - Evita injetar HTML completo dentro do layout (causa pagina desformatada e JS quebrado).

2) app/public/areas/iatr/index.php
   - Refatorado para usar o layout user.php.
   - Removido HTML/head/body duplicados; CSS especifico via $headExtra.

3) app/public/areas/iatr/formulario.php
   - Refatorado para usar layout user.php.
   - Adicionada validacao do JSON SurveyJS (json_last_error) com erro amigavel.
   - Mantidos scripts SurveyJS e inicializacao dentro do conteudo.

Sintaxe PHP OK (php -l nos 3 arquivos).

Bloqueio atual: nao consigo deploy na VM100 porque o SSH do Proxmox (158.69.25.114:2222) esta recusando conexao (Connection refused). HTTP no 158.69.25.114 responde, mas porta 2222 fechou. Suspeita: sshd off ou regras NAT/iptables nao persistiram apos reboot. Usuario vai checar via console OVH.

Preciso da tua opiniao:
- Algum risco nos fixes HTMX/partial? Algum outro ponto a ajustar para evitar SurveyJS quebrar no HTMX?
- Alguma ideia para diagnostico rapido da porta 2222 ou alternativa (forward direto VM100)?

Obrigado.
