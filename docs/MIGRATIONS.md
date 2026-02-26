# Database Migrations - Plataforma Sunyata v2

**Database:** PostgreSQL 16
**Migration Path:** `migrations/*.sql`
**Execution:** Sequential (001 → 012)

---

## Migration History

### 001_initial_schema.sql
**Date:** 2026-02-09
**Status:** ✅ Applied
**Type:** Schema Creation

Creates initial database structure:
- Users & authentication (users, user_profiles, sessions)
- Canvas templates (canvas_templates)
- Verticals (verticals)
- Submissions (user_submissions)
- Prompts (prompt_history, prompt_dictionary)
- Documents (user_documents, user_files)
- System tables (settings, audit_logs, tool_access_logs)

**Breaking Changes:** None (initial)

---

### 002_add_form_drafts.sql
**Date:** 2026-02-10
**Status:** ✅ Applied
**Type:** Feature Addition

**Phase 2.5 - Drafts MVP**

Adds auto-save functionality:
```sql
CREATE TABLE form_drafts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    canvas_template_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    label VARCHAR(255) DEFAULT 'Rascunho sem titulo',
    form_data JSONB NOT NULL DEFAULT '{}',
    page_no INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    expires_at TIMESTAMPTZ DEFAULT NOW() + INTERVAL '90 days'
);
```

**Breaking Changes:** None

---

### 003_add_canvas_drafts.sql
**Date:** 2026-02-11
**Status:** ✅ Applied
**Type:** Feature Addition

**Phase 2.5 - Drafts MVP (Canvas Editor)**

Adds draft versioning to canvas templates:
```sql
ALTER TABLE canvas_templates
ADD COLUMN draft_form_config JSONB,
ADD COLUMN current_version INTEGER DEFAULT 1,
ADD COLUMN last_published_at TIMESTAMPTZ,
ADD COLUMN has_unpublished_changes BOOLEAN DEFAULT FALSE,
ADD COLUMN last_edited_by INTEGER REFERENCES users(id);

CREATE TABLE canvas_template_versions (
    id SERIAL PRIMARY KEY,
    template_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    version INTEGER NOT NULL,
    form_config JSONB NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    comment VARCHAR(500),
    UNIQUE(template_id, version)
);
```

**Breaking Changes:** None (additive)

---

### 004_add_conversations.sql
**Date:** 2026-02-11
**Status:** ✅ Applied
**Type:** Feature Addition

Multi-turn conversation support:
```sql
CREATE TABLE conversations (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    canvas_id INTEGER NOT NULL REFERENCES canvas_templates(id),
    title VARCHAR(255),
    status TEXT DEFAULT 'active',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE conversation_messages (
    id BIGSERIAL PRIMARY KEY,
    conversation_id BIGINT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role TEXT NOT NULL,
    content TEXT,
    message_type TEXT,
    question_number INTEGER,
    tokens_input INTEGER,
    tokens_output INTEGER,
    cost_usd NUMERIC,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
```

**Breaking Changes:** None

---

### 005_add_artifacts.sql
**Date:** 2026-02-11
**Status:** ✅ Applied
**Type:** Feature Addition

Artifact management:
```sql
CREATE TABLE form_artifacts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    canvas_template_id INTEGER NOT NULL REFERENCES canvas_templates(id),
    submission_id INTEGER UNIQUE REFERENCES user_submissions(id) ON DELETE CASCADE,
    title VARCHAR(255) NOT NULL,
    artifact_type VARCHAR(50) NOT NULL,
    content TEXT NOT NULL,
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE artifact_relations (
    id SERIAL PRIMARY KEY,
    from_template_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    to_template_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    relation_type VARCHAR(50) DEFAULT 'suggested',
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(from_template_id, to_template_id)
);
```

**Breaking Changes:** None

---

### 006_add_canvas_metadata.sql
**Date:** 2026-02-11
**Status:** ✅ Applied
**Type:** Schema Enhancement

Adds display metadata to canvas:
```sql
ALTER TABLE canvas_templates
ADD COLUMN type TEXT DEFAULT 'forms',
ADD COLUMN description TEXT,
ADD COLUMN icon VARCHAR(100),
ADD COLUMN color VARCHAR(50),
ADD COLUMN page_url TEXT,
ADD COLUMN external_url TEXT,
ADD COLUMN display_order INTEGER DEFAULT 0,
ADD COLUMN category VARCHAR(100) DEFAULT 'geral';
```

**Types:**
- `forms`: SurveyJS form canvas
- `page`: Internal page link
- `external`: External URL

**Breaking Changes:** None (defaults provided)

---

### 007_add_file_conversations.sql
**Date:** 2026-02-12
**Status:** ✅ Applied
**Type:** Feature Addition

File attachments in conversations:
```sql
CREATE TABLE conversation_files (
    conversation_id BIGINT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    file_id BIGINT NOT NULL REFERENCES user_files(id) ON DELETE CASCADE,
    PRIMARY KEY (conversation_id, file_id)
);

CREATE TABLE submission_documents (
    submission_id BIGINT NOT NULL REFERENCES user_submissions(id) ON DELETE CASCADE,
    document_id BIGINT NOT NULL REFERENCES user_documents(id) ON DELETE CASCADE,
    PRIMARY KEY (submission_id, document_id)
);
```

**Breaking Changes:** None

---

### 008_add_lgpd_compliance.sql
**Date:** 2026-02-12
**Status:** ✅ Applied
**Type:** Compliance

LGPD/GDPR compliance tables:
```sql
CREATE TABLE consents (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    consent_type TEXT NOT NULL,
    consent_given BOOLEAN NOT NULL DEFAULT FALSE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    consent_text TEXT,
    consent_version VARCHAR(50),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    revoked_at TIMESTAMPTZ
);

CREATE TABLE data_requests (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    request_type TEXT NOT NULL,
    status TEXT DEFAULT 'pending',
    requested_at TIMESTAMPTZ DEFAULT NOW(),
    processed_at TIMESTAMPTZ,
    processed_by INTEGER REFERENCES users(id),
    notes TEXT
);
```

**Breaking Changes:** None

---

### 009_add_contracts.sql
**Date:** 2026-02-12
**Status:** ✅ Applied
**Type:** Feature Addition

User contracts and subscriptions:
```sql
CREATE TABLE contracts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    vertical TEXT NOT NULL,
    status TEXT DEFAULT 'active',
    start_date DATE NOT NULL,
    end_date DATE,
    metadata JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
```

**Breaking Changes:** None

---

### 010_add_vertical_access_requests.sql
**Date:** 2026-02-13
**Status:** ✅ Applied
**Type:** Feature Addition

Vertical access approval workflow:
```sql
CREATE TABLE vertical_access_requests (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    vertical TEXT NOT NULL,
    status TEXT DEFAULT 'pending',
    request_data JSONB,
    requested_at TIMESTAMPTZ DEFAULT NOW(),
    processed_at TIMESTAMPTZ,
    processed_by INTEGER REFERENCES users(id),
    notes TEXT
);
```

**Breaking Changes:** None

---

### 011_canvas_vertical_assignments.sql
**Date:** 2026-02-19
**Status:** ✅ Applied
**Type:** Schema Refactoring

**Phase 3.5 Part 2 - Many-to-Many Canvas-Vertical Assignment**

Creates junction table for many-to-many relationship:
```sql
CREATE TABLE IF NOT EXISTS canvas_vertical_assignments (
    id SERIAL PRIMARY KEY,
    canvas_id INTEGER NOT NULL REFERENCES canvas_templates(id) ON DELETE CASCADE,
    vertical_slug TEXT NOT NULL,
    display_order INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT unique_canvas_vertical UNIQUE (canvas_id, vertical_slug)
);

CREATE INDEX idx_canvas_vertical_slug ON canvas_vertical_assignments(vertical_slug);
CREATE INDEX idx_canvas_vertical_canvas_id ON canvas_vertical_assignments(canvas_id);
```

**Purpose:**
- Allows canvas to appear in multiple verticals
- Replaces 1:N relationship with N:M
- Supports cross-vertical canvas reuse

**Breaking Changes:**
- **NEXT MIGRATION (012)** will remove `canvas_templates.vertical` column
- Code must use `CanvasService::getByVertical()` instead of direct queries

---

### 012_migrate_canvas_verticals.sql
**Date:** 2026-02-19
**Status:** ✅ Applied
**Type:** Data Migration

**Phase 3.5 Part 2 - Complete Transition to Many-to-Many**

**Steps:**
1. Backup existing data
2. Migrate `canvas_templates.vertical` → `canvas_vertical_assignments`
3. Drop `canvas_templates.vertical` column
4. Drop dependent views (if any)

```sql
-- Backup
CREATE TABLE canvas_templates_backup_YYYYMMDD_HHMMSS AS
SELECT * FROM canvas_templates;

-- Migrate data
INSERT INTO canvas_vertical_assignments (canvas_id, vertical_slug, display_order, created_at)
SELECT id, vertical, 0, created_at
FROM canvas_templates
WHERE vertical IS NOT NULL AND vertical != ''
ON CONFLICT (canvas_id, vertical_slug) DO NOTHING;

-- Drop column (requires dropping dependent views first)
DROP VIEW IF EXISTS canvas_templates_published CASCADE;
DROP VIEW IF EXISTS canvas CASCADE;
ALTER TABLE canvas_templates DROP COLUMN IF EXISTS vertical CASCADE;
```

**Migration Results:**
- 55 canvas assignments migrated
- Distribution: iatr (23), nicolay-advogados (16), legal (16)

**Breaking Changes:** ✅ CRITICAL
- `canvas_templates.vertical` column **removed**
- Direct SELECT queries on `vertical` will **fail**
- **MUST use CanvasService** or JOIN with junction table
- Dependent views dropped (recreate if needed)

**Rollback:**
```sql
-- Restore from backup
ALTER TABLE canvas_templates ADD COLUMN vertical TEXT;
UPDATE canvas_templates ct
SET vertical = (
    SELECT vertical_slug
    FROM canvas_vertical_assignments
    WHERE canvas_id = ct.id
    LIMIT 1
);
```

---

### Migration 016 — PNCP Enrichment Columns (2026-02-25)

**File:** `migrations/016_pncp_enrichment_columns.sql`

**Changes:**
- Added `pncp_detalhes JSONB` to `pncp_editais` — full compra record from PNCP `/api/consulta/v1/orgaos/{cnpj}/compras/{ano}/{seq}`
- Added `pncp_itens JSONB` to `pncp_editais` — items array from PNCP `/pncp-api/v1/.../itens`
- Added `enriquecido_em TIMESTAMPTZ` to `pncp_editais` — timestamp of last enrichment (null = not yet enriched)

**Rollback:**
```sql
ALTER TABLE pncp_editais DROP COLUMN IF EXISTS pncp_detalhes;
ALTER TABLE pncp_editais DROP COLUMN IF EXISTS pncp_itens;
ALTER TABLE pncp_editais DROP COLUMN IF EXISTS enriquecido_em;
```

---

## Migration Best Practices

### Running Migrations

1. **Backup first:**
   ```bash
   pg_dump -U sunyata_app sunyata_platform > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **Test on staging:**
   ```bash
   psql -U sunyata_app sunyata_platform < migrations/XXX_name.sql
   ```

3. **Verify:**
   ```sql
   SELECT COUNT(*) FROM new_table;
   \d table_name;
   ```

4. **Monitor logs:**
   ```bash
   tail -f /var/log/postgresql/postgresql-16-main.log
   ```

### Common Issues

**1. Foreign Key Violations**
- Ensure parent records exist before inserting child records
- Check ON DELETE CASCADE behavior

**2. Unique Constraint Violations**
- Check for duplicate data before migration
- Use `ON CONFLICT DO NOTHING` or `ON CONFLICT DO UPDATE`

**3. Column Type Mismatches**
- Use explicit CAST when changing types
- Consider intermediate migration steps

**4. Dependent Views**
- DROP dependent views before schema changes
- Recreate views after migration

**5. Transaction Rollback**
- Wrap migrations in BEGIN/COMMIT
- Test with ROLLBACK first
- Be aware RAISE NOTICE outside DO blocks causes syntax error in transactions

---

## Pending Migrations

None - all migrations applied as of 2026-02-25.

---

## Deprecation Notices

### Deprecated in Phase 3.5 (2026-02-19)

**❌ `canvas_templates.vertical` column**
- **Replaced by:** `canvas_vertical_assignments` junction table
- **Removed in:** Migration 012
- **Use instead:** `CanvasService::getByVertical($verticalSlug)`

### Deprecated in Phase 2 (2026-02-11)

**⚠️ `user_files` table**
- **Replaced by:** `user_documents` (better structure, metadata)
- **Status:** Still present for backward compatibility
- **Plan:** Will be merged into `user_documents` in future migration

---

## Schema Evolution Roadmap

### Planned (Not Implemented)

**Phase 4: Full-Text Search Enhancement**
- Add pgvector embeddings to user_documents
- AI-powered semantic search

**Phase 5: Multi-Tenancy**
- Add organization/tenant isolation
- Tenant-specific verticals

**Phase 6: Advanced Workflows**
- Workflow state machine
- Multi-step approval processes

---

**Last Updated:** 2026-02-25
**Maintained by:** Claude (Executor Principal)
