Assunto: Deploy de correcoes no V2 (VM100) + snapshot de rollback

Resumo do que fiz hoje:
- Snapshot da VM100 antes do deploy: qm snapshot 100 pre-fix-20260211-2135 (rollback facil).
- Apliquei no servidor (VM100) tres ajustes que discutimos para o bug do dashboard e robustez:
  1) app/config/config.php
     - VERTICALS agora e' montado dinamicamente lendo config/verticals.php.
  2) app/src/views/components/user-sidebar.php
     - Trata tools como string ou array; evita warnings; monta links /areas/{vertical}/.
  3) app/public/api/canvas/submit.php
     - Adiciona autorizacao (usuario vs vertical do canvas; admin/demo allowed).
     - Stream mode faz fallback para sync para evitar vazamento de key interna.

Validacoes:
- php -l passou nos tres arquivos no servidor.
- Nao rodei smoke test de UI/api ainda.

Se quiser, posso fazer smoke tests basicos (dashboard, canvas submit) ou reverter via snapshot.
