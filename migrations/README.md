# Migrations: MariaDB → PostgreSQL

Scripts de migração do banco legado (MariaDB/Hostinger) para PostgreSQL 16 na VM OVH.

## Estratégia

1. **pgloader** para conversão automática de schema + dados
2. Scripts SQL manuais para ajustes pós-migração:
   - ENUM → CHECK constraints ou tabelas de lookup
   - JSON → JSONB com índices GIN
   - Adicionar pgvector extension e colunas de embedding
   - Ajustar sequences (AUTO_INCREMENT → SERIAL/GENERATED)

## Ordem de execução

```
001-pre-migration-checks.sql    # Verificações antes de migrar
002-pgloader-config.load        # Configuração do pgloader
003-post-migration-fixes.sql    # Ajustes de tipos e constraints
004-add-pgvector.sql            # Extension pgvector + colunas embedding
005-create-indexes.sql          # Índices JSONB, GIN, etc.
006-verify-migration.sql        # Queries de verificação
```
