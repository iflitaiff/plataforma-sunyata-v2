# Production Readiness Checklist

**CRITICAL:** Antes de colocar a plataforma em produção com usuários reais, execute este checklist completo.

## 1. Environment Configuration

- [ ] **Mudar APP_ENV para production**
  ```bash
  # Em /var/www/sunyata/app/.env
  APP_ENV=production
  ```

- [ ] **Verificar variáveis sensíveis no .env**
  - [ ] `INTERNAL_API_KEY` com valor forte (não dev-key)
  - [ ] `DB_PASS` com senha forte
  - [ ] `REDIS_PASSWORD` configurado
  - [ ] `SESSION_SECRET` aleatório e forte

## 2. Database Security

- [ ] **Preencher ALLOWED_TABLES**
  - Executar: `SELECT tablename FROM pg_tables WHERE schemaname = 'public';`
  - Adicionar TODAS as tabelas em `Database.php::ALLOWED_TABLES`

- [ ] **Preencher ALLOWED_COLUMNS**
  - Para cada tabela, executar: `\d tablename`
  - Adicionar todas as colunas em `Database.php::ALLOWED_COLUMNS`

- [ ] **Testar whitelist funciona**
  - Tentar acesso a tabela não listada → deve bloquear
  - Tentar acesso a coluna não listada → deve bloquear

## 3. Rate Limiting

- [ ] **Verificar RateLimiter está ativo**
  - Confirmar que `getenv('APP_ENV')` NÃO retorna 'development'
  - Testar: fazer 6 tentativas de login em 1 minuto → 6ª deve ser bloqueada

- [ ] **Ajustar limites se necessário**
  - Login: 5 tentativas / 15 minutos
  - Outros endpoints críticos: revisar limites

## 4. PHP Opcache

- [ ] **Habilitar opcache em produção**
  ```ini
  # /etc/php/8.3/fpm/php.ini
  opcache.enable=1
  opcache.validate_timestamps=0  # Melhor performance
  opcache.revalidate_freq=0
  ```

- [ ] **Criar script de deploy que recarrega opcache**
  ```bash
  sudo systemctl reload php8.3-fpm  # ou restart
  ```

## 5. Security Headers & CSRF

- [ ] **Validar CSRF tokens funcionam**
  - Request sem `X-CSRF-Token` → deve retornar 403
  - Request com token inválido → deve retornar 403

- [ ] **Configurar CORS corretamente**
  - Apenas domínios autorizados

- [ ] **Headers de segurança no Nginx**
  ```nginx
  add_header X-Frame-Options "SAMEORIGIN";
  add_header X-Content-Type-Options "nosniff";
  add_header X-XSS-Protection "1; mode=block";
  add_header Referrer-Policy "strict-origin-when-cross-origin";
  ```

## 6. Logging & Monitoring

- [ ] **Revisar todos os error_log()**
  - Não vazar informações sensíveis (passwords, tokens, etc)
  - Logs suficientes para debug de produção

- [ ] **Configurar rotação de logs**
  - PHP error logs
  - Nginx access/error logs
  - Application logs

- [ ] **Monitoramento de erros**
  - Configurar alertas para erros críticos
  - Dashboard de health checks

## 7. Backup & Recovery

- [ ] **Backup automático do banco**
  - Frequência: diária
  - Retenção: 30 dias
  - Testar restore

- [ ] **Backup de uploads/documentos**
  - `/var/uploads/`
  - `app/storage/documents/`

## 8. Performance

- [ ] **Redis configurado corretamente**
  - Sessões
  - Cache
  - Rate limiting

- [ ] **Database indexes**
  - Verificar queries lentas
  - Adicionar índices necessários

- [ ] **CDN/Static assets** (se aplicável)

## 9. Testing

- [ ] **Testes E2E em staging**
  - Todos os fluxos principais funcionando
  - Upload de arquivos
  - Geração de documentos via IA
  - Multi-step forms

- [ ] **Load testing**
  - Simular carga esperada
  - Identificar gargalos

## 10. Final Checks

- [ ] **SSL/TLS configurado**
  - Certificado válido
  - Redirecionamento HTTP → HTTPS

- [ ] **Firewall rules**
  - Apenas portas necessárias abertas (22, 80, 443)

- [ ] **Usuários de teste removidos/desabilitados**
  - Ou com senhas fortes

- [ ] **Documentação atualizada**
  - README
  - API docs
  - Runbooks para operação

---

## Emergency Rollback Plan

Se algo der errado após deploy:

1. **Reverter código:**
   ```bash
   cd /var/www/sunyata/app
   git reset --hard <commit-hash-anterior>
   sudo systemctl restart php8.3-fpm
   ```

2. **Reverter banco (se aplicável):**
   ```bash
   # Restore do backup mais recente
   ```

3. **Comunicar usuários:**
   - Status page
   - Email/notificação

---

**Última atualização:** 2026-02-18
**Responsável:** Claude + Equipe QA
