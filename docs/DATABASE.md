# Database Schema - Plataforma Sunyata v2

**Database:** PostgreSQL 16 + pgvector
**Name:** `sunyata_platform`
**User:** `sunyata_app`
**Last Updated:** 2026-02-24

---

## Table of Contents
- [Users & Authentication](#users--authentication)
- [Canvas & Templates](#canvas--templates)
- [Verticals](#verticals)
- [Submissions & Conversations](#submissions--conversations)
- [Documents & Files](#documents--files)
- [Prompts](#prompts)
- [System & Audit](#system--audit)
- [PNCP & Licitações](#pncp--licitações)
- [Indexes](#indexes)
- [Common Queries](#common-queries)

---

## Users & Authentication

### `users`
Core user table with authentication and profile info.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `google_id` | varchar(255) | YES | - | OAuth Google ID (unique) |
| `email` | varchar(255) | NO | - | Unique email |
| `name` | varchar(255) | NO | - | Display name |
| `picture` | varchar(500) | YES | - | Profile picture URL |
| `password_hash` | varchar(255) | YES | - | Local auth (bcrypt) |
| `access_level` | text | NO | 'guest' | guest, user, premium, admin |
| `selected_vertical` | text | YES | - | Current vertical slug |
| `completed_onboarding` | boolean | YES | false | Onboarding status |
| `is_demo` | boolean | YES | false | Demo account flag |
| `created_at` | timestamptz | YES | now() | Account creation |
| `updated_at` | timestamptz | YES | now() | Last update |
| `last_login` | timestamptz | YES | - | Last login timestamp |

**Constraints:**
- PK: `id`
- UNIQUE: `google_id`, `email`

---

### `user_profiles`
Extended user profile information.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | One-to-one with users |
| `phone` | varchar(20) | YES | - | Phone number |
| `position` | varchar(255) | YES | - | Job position |
| `organization` | varchar(255) | YES | - | Organization name |
| `organization_size` | text | YES | - | Organization size category |
| `area` | varchar(255) | YES | - | Area of work |
| `ifrj_level` | text | YES | - | IFRJ education level |
| `ifrj_course` | varchar(255) | YES | - | IFRJ course name |
| `created_at` | timestamptz | YES | now() | Creation timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)
- UNIQUE: `user_id`

---

### `sessions`
Active user sessions (Redis-backed).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | varchar(128) | NO | PK | Session ID (hashed) |
| `user_id` | integer | NO | FK → users | Session owner |
| `ip_address` | varchar(45) | YES | - | IPv4/IPv6 |
| `user_agent` | text | YES | - | Browser user agent |
| `last_activity` | timestamptz | YES | now() | Last activity timestamp |
| `created_at` | timestamptz | YES | now() | Session start |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)

---

### `consents`
LGPD/GDPR consent tracking.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | User who consented |
| `consent_type` | text | NO | - | Type: terms, privacy, marketing, etc. |
| `consent_given` | boolean | NO | false | Consent status |
| `ip_address` | varchar(45) | YES | - | IP at consent time |
| `user_agent` | text | YES | - | Browser at consent time |
| `consent_text` | text | YES | - | Full consent text shown |
| `consent_version` | varchar(50) | YES | - | Version of consent document |
| `created_at` | timestamptz | YES | now() | Consent timestamp |
| `revoked_at` | timestamptz | YES | - | Revocation timestamp |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)

---

### `contracts`
User contracts and subscriptions.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | Contract owner |
| `type` | text | NO | - | Contract type |
| `vertical` | text | NO | - | Vertical slug |
| `status` | text | YES | 'active' | active, expired, cancelled |
| `start_date` | date | NO | - | Contract start |
| `end_date` | date | YES | - | Contract end (NULL = perpetual) |
| `metadata` | jsonb | YES | - | Additional contract details |
| `created_at` | timestamptz | YES | now() | Creation timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)

---

## Canvas & Templates

### `canvas_templates`
Form templates and AI canvas configurations. **Core table for all canvas-based tools.**

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `slug` | varchar(255) | NO | - | URL-safe identifier (unique) |
| `name` | varchar(255) | NO | - | Display name |
| `form_config` | jsonb | YES | - | **SurveyJS JSON schema** |
| `system_prompt` | text | YES | - | AI system prompt (level 2) |
| `user_prompt_template` | text | YES | - | Template for user prompt |
| `max_questions` | integer | YES | 5 | Max AI questions in conversation |
| `is_active` | boolean | YES | true | Active status |
| `status` | text | YES | 'published' | draft, published |
| `draft_form_config` | jsonb | YES | - | Draft version of form_config |
| `current_version` | integer | YES | 1 | Current version number |
| `last_published_at` | timestamptz | YES | - | Last publish timestamp |
| `has_unpublished_changes` | boolean | YES | false | Draft status flag |
| `last_edited_by` | integer | YES | FK → users | Last editor user ID |
| `api_params_override` | jsonb | YES | - | **Level 3 API params** (claude_model, temperature, max_tokens, top_p) |
| `config` | jsonb | YES | - | Additional config |
| `created_at` | timestamptz | YES | now() | Creation timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |
| `type` | text | YES | 'forms' | forms, page, external |
| `description` | text | YES | - | Canvas description |
| `icon` | varchar(100) | YES | - | Icon emoji/class |
| `color` | varchar(50) | YES | - | Theme color |
| `page_url` | text | YES | - | Internal page URL (type=page) |
| `external_url` | text | YES | - | External URL (type=external) |
| `display_order` | integer | YES | 0 | Vertical-wide sort order |
| `category` | varchar(100) | YES | 'geral' | Category: geral, analise, documentos, gestao, ferramentas |

**Constraints:**
- PK: `id`
- UNIQUE: `slug`
- FK: `last_edited_by` → `users.id`

**IMPORTANT NOTES:**
- `form_config`: JSON schema following [SurveyJS format](https://surveyjs.io/form-library/documentation/design-survey/create-a-simple-survey)
- `api_params_override`: Canvas-level API overrides (Level 3 in hierarchy). **NEVER include `system_prompt` here** - use `system_prompt` column instead.
- **Phase 3.5 (2026-02-19):** `vertical` column **removed**. Use junction table `canvas_vertical_assignments` for many-to-many relationship.

---

### `canvas_vertical_assignments`
**Many-to-many junction table** between canvas and verticals. Added in Phase 3.5 (2026-02-19).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `canvas_id` | integer | NO | FK → canvas_templates | Canvas reference |
| `vertical_slug` | text | NO | - | Vertical slug (not FK - verticals can be file-based) |
| `display_order` | integer | YES | 0 | Order within vertical |
| `created_at` | timestamptz | YES | now() | Assignment timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |

**Constraints:**
- PK: `id`
- FK: `canvas_id` → `canvas_templates.id` (ON DELETE CASCADE)
- UNIQUE: `(canvas_id, vertical_slug)` - prevents duplicate assignments

**Indexes:**
- `idx_canvas_vertical_slug` on `vertical_slug` (query canvas by vertical)
- `idx_canvas_vertical_canvas_id` on `canvas_id` (query verticals for canvas)

**Service Layer - SEMPRE use CanvasService:**
```php
// ✅ CORRETO - Usa service layer (Phase 3.5+)
$canvasService = \Sunyata\Services\CanvasService::getInstance();

// Obter canvas de uma vertical
$canvasList = $canvasService->getByVertical('iatr', $activeOnly = true);

// Atribuir múltiplas verticals a um canvas
$canvasService->assignVerticals($canvasId, ['iatr', 'legal', 'licitacoes']);

// Obter verticals de um canvas
$verticals = $canvasService->getAssignedVerticals($canvasId);
```

**SQL Direto (se realmente necessário):**
```sql
-- Get all canvas for a vertical
SELECT ct.*
FROM canvas_templates ct
INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
WHERE cva.vertical_slug = 'iatr'
ORDER BY cva.display_order, ct.name;

-- Get all verticals for a canvas
SELECT vertical_slug
FROM canvas_vertical_assignments
WHERE canvas_id = 10
ORDER BY display_order;
```

---

### `canvas_template_versions`
Version history for canvas templates (Fase 2.5 - Drafts MVP).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `template_id` | integer | NO | FK → canvas_templates | Parent template |
| `version` | integer | NO | - | Version number |
| `form_config` | jsonb | NO | - | SurveyJS config snapshot |
| `created_at` | timestamptz | YES | now() | Version creation timestamp |
| `comment` | varchar(500) | YES | - | Version comment |

**Constraints:**
- PK: `id`
- FK: `template_id` → `canvas_templates.id` (ON DELETE CASCADE)
- UNIQUE: `(template_id, version)` - ensures version uniqueness per template

---

## Verticals

### `verticals`
Business verticals/domains (e.g., juridico, licitacoes, nicolay-advogados).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `slug` | varchar(100) | NO | - | URL-safe identifier (unique) |
| `name` | varchar(255) | NO | - | Display name |
| `config` | jsonb | YES | - | **Vertical-level config** (system_prompt, API params - Level 1 in hierarchy) |
| `is_active` | boolean | YES | true | Active status |
| `created_at` | timestamptz | YES | now() | Creation timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |

**Constraints:**
- PK: `id`
- UNIQUE: `slug`

**Config JSONB Structure:**
The `config` column stores vertical-level configuration as JSONB. Expected fields:
```json
{
  "icon": "🏛️",                    // Display icon (emoji or HTML)
  "description": "Legal services", // Vertical description
  "order": 10,                     // Display order (lower = higher priority)
  "requires_approval": false,      // User access requires admin approval
  "max_users": null,               // Max users allowed (null = unlimited)
  "api_params": {                  // Level 1 API params (Claude API)
    "claude_model": "claude-sonnet-4.5-20250929",
    "temperature": 0.7,
    "max_tokens": 4096
  },
  "system_prompt": "You are..."   // Level 1 system prompt (optional)
}
```

**IMPORTANT NOTES:**
- Verticals can also be defined in `config/verticals.php` (file-based, read-only)
- Database entries override file-based config
- `config.system_prompt`: Level 1 in system prompt hierarchy
- `config.api_params`: Level 1 in API params hierarchy (portal defaults override this)

**V1→V2 Schema Migration Notes:**
For developers maintaining legacy code or migrating from V1 (MySQL):
- V1: Individual columns (`nome`, `icone`, `descricao`, `ordem`, `disponivel`, `requer_aprovacao`)
- V2: JSONB `config` + simplified columns (`name`, `is_active`)
- **VerticalService::getAll()** provides backward-compatible mapping (V2 → V1 format)
- See commit `0f76143` for reference implementation

**Service Layer - SEMPRE use VerticalService:**
```php
// ✅ CORRETO - Usa service layer com mapeamento automático
$verticalService = \Sunyata\Services\VerticalService::getInstance();
$verticals = $verticalService->getAll();
// Retorna formato compatível com código legado: 'nome', 'icone', etc.

// ❌ INCORRETO - Query direta quebra em V2
$db->fetchAll("SELECT nome, icone FROM verticals");  // Colunas não existem!

// ✅ CORRETO - Query direta usando V2 schema
$db->fetchAll("SELECT name, config->>'icon' as icone FROM verticals");
```

---

### `vertical_access_requests`
User requests for vertical access (approval workflow).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | Requesting user |
| `vertical` | text | NO | - | Vertical slug requested |
| `status` | text | YES | 'pending' | pending, approved, rejected |
| `request_data` | jsonb | YES | - | Additional request context |
| `requested_at` | timestamptz | YES | now() | Request timestamp |
| `processed_at` | timestamptz | YES | - | Processing timestamp |
| `processed_by` | integer | YES | FK → users | Admin who processed |
| `notes` | text | YES | - | Admin notes |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)
- FK: `processed_by` → `users.id`

---

## Submissions & Conversations

### `user_submissions`
User form submissions and AI-generated results.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | Submission owner |
| `canvas_template_id` | integer | NO | FK → canvas_templates | Canvas used |
| `prompt_history_id` | bigint | YES | FK → prompt_history | Related prompt log |
| `vertical_slug` | text | NO | - | Vertical slug |
| `title` | varchar(500) | YES | - | Submission title |
| `form_data` | jsonb | NO | {} | SurveyJS response data |
| `result_markdown` | text | YES | - | AI-generated markdown result |
| `result_metadata` | jsonb | YES | - | Additional result metadata |
| `status` | text | YES | 'pending' | pending, processing, completed, error |
| `parent_id` | bigint | YES | FK → user_submissions | Parent submission (for iterations) |
| `is_favorite` | boolean | YES | false | User favorite flag |
| `tags` | jsonb | YES | [] | User-defined tags |
| `created_at` | timestamptz | YES | now() | Creation timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |
| `search_vector` | tsvector | YES | - | Full-text search vector |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)
- FK: `canvas_template_id` → `canvas_templates.id`
- FK: `prompt_history_id` → `prompt_history.id`
- FK: `parent_id` → `user_submissions.id`

**Indexes:**
- Full-text search on `search_vector`

---

### `form_drafts`
Draft form data (auto-save, Fase 2.5).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | Draft owner |
| `canvas_template_id` | integer | NO | FK → canvas_templates | Canvas being filled |
| `label` | varchar(255) | YES | 'Rascunho sem titulo' | Draft label |
| `form_data` | jsonb | NO | {} | Partial SurveyJS data |
| `page_no` | integer | YES | 0 | Current page in form |
| `created_at` | timestamptz | YES | now() | Draft creation |
| `updated_at` | timestamptz | YES | now() | Last auto-save |
| `expires_at` | timestamptz | YES | now() + 90 days | Expiration date |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)
- FK: `canvas_template_id` → `canvas_templates.id` (ON DELETE CASCADE)

---

### `form_artifacts`
Generated artifacts from submissions (e.g., documents, reports).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | Artifact owner |
| `canvas_template_id` | integer | NO | FK → canvas_templates | Canvas source |
| `submission_id` | integer | YES | FK → user_submissions | Related submission |
| `title` | varchar(255) | NO | - | Artifact title |
| `artifact_type` | varchar(50) | NO | - | document, report, analysis, etc. |
| `content` | text | NO | - | Artifact content (markdown/HTML) |
| `metadata` | jsonb | YES | {} | Additional metadata |
| `created_at` | timestamptz | YES | now() | Creation timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)
- FK: `canvas_template_id` → `canvas_templates.id`
- FK: `submission_id` → `user_submissions.id` (ON DELETE CASCADE)
- UNIQUE: `submission_id`

---

### `artifact_relations`
Relations between artifacts (suggested templates, follow-ups).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `from_template_id` | integer | NO | FK → canvas_templates | Source template |
| `to_template_id` | integer | NO | FK → canvas_templates | Target template |
| `relation_type` | varchar(50) | YES | 'suggested' | suggested, prerequisite, alternative |
| `metadata` | jsonb | YES | {} | Relation metadata |
| `created_at` | timestamptz | YES | now() | Creation timestamp |

**Constraints:**
- PK: `id`
- FK: `from_template_id` → `canvas_templates.id` (ON DELETE CASCADE)
- FK: `to_template_id` → `canvas_templates.id` (ON DELETE CASCADE)
- UNIQUE: `(from_template_id, to_template_id)`

---

### `conversations`
Multi-turn AI conversations (future feature).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | Conversation owner |
| `canvas_id` | integer | NO | FK → canvas_templates | Canvas context |
| `title` | varchar(255) | YES | - | Conversation title |
| `status` | text | YES | 'active' | active, archived, deleted |
| `created_at` | timestamptz | YES | now() | Start timestamp |
| `updated_at` | timestamptz | YES | now() | Last message timestamp |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)
- FK: `canvas_id` → `canvas_templates.id`

---

### `conversation_messages`
Individual messages in conversations.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint | NO | PK, auto | Primary key |
| `conversation_id` | bigint | NO | FK → conversations | Parent conversation |
| `role` | text | NO | - | user, assistant, system |
| `content` | text | YES | - | Message content |
| `message_type` | text | YES | - | question, answer, clarification, etc. |
| `question_number` | integer | YES | - | Question counter |
| `tokens_input` | integer | YES | - | Input tokens |
| `tokens_output` | integer | YES | - | Output tokens |
| `cost_usd` | numeric | YES | - | Message cost |
| `created_at` | timestamptz | YES | now() | Message timestamp |

**Constraints:**
- PK: `id`
- FK: `conversation_id` → `conversations.id` (ON DELETE CASCADE)

---

### `conversation_files`
File attachments in conversations.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `conversation_id` | bigint | NO | PK, FK → conversations | Conversation reference |
| `file_id` | bigint | NO | PK, FK → user_files | File reference |

**Constraints:**
- PK: `(conversation_id, file_id)`
- FK: `conversation_id` → `conversations.id` (ON DELETE CASCADE)
- FK: `file_id` → `user_files.id` (ON DELETE CASCADE)

---

## Documents & Files

### `user_documents`
User-uploaded documents with text extraction (pgvector).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | Document owner |
| `filename` | varchar(255) | NO | - | Original filename |
| `stored_filename` | varchar(500) | NO | - | Stored filename (UUID) |
| `mime_type` | varchar(100) | NO | - | MIME type |
| `file_size` | bigint | NO | 0 | File size in bytes |
| `storage_path` | varchar(500) | NO | - | Storage path |
| `extracted_text` | text | YES | - | Extracted text content |
| `extraction_status` | text | YES | 'pending' | pending, processing, completed, error |
| `metadata` | jsonb | YES | {} | Additional metadata |
| `tags` | jsonb | YES | [] | User-defined tags |
| `is_archived` | boolean | YES | false | Archive flag |
| `created_at` | timestamptz | YES | now() | Upload timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |
| `search_vector` | tsvector | YES | - | Full-text search vector |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)

**Indexes:**
- Full-text search on `search_vector`

---

### `user_files`
Legacy file uploads (Phase 1).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | File owner |
| `filename` | varchar(255) | NO | - | Original filename |
| `stored_filename` | varchar(500) | YES | - | Stored filename |
| `filepath` | varchar(500) | YES | - | File path |
| `mime_type` | varchar(100) | YES | - | MIME type |
| `size_bytes` | bigint | YES | - | File size |
| `md5_hash` | char(32) | NO | - | MD5 checksum |
| `extracted_text` | text | YES | - | Extracted text |
| `extraction_error` | text | YES | - | Extraction error message |
| `uploaded_at` | timestamptz | YES | now() | Upload timestamp |
| `created_at` | timestamptz | YES | now() | Creation timestamp |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)

---

### `submission_documents`
Link submissions to uploaded documents.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `submission_id` | bigint | NO | PK, FK → user_submissions | Submission reference |
| `document_id` | bigint | NO | PK, FK → user_documents | Document reference |

**Constraints:**
- PK: `(submission_id, document_id)`
- FK: `submission_id` → `user_submissions.id` (ON DELETE CASCADE)
- FK: `document_id` → `user_documents.id` (ON DELETE CASCADE)

---

## Prompts

### `prompt_history`
Complete log of all AI API calls.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | User who triggered |
| `vertical` | varchar(50) | NO | - | Vertical slug |
| `tool_name` | varchar(100) | NO | - | Tool/canvas name |
| `input_data` | jsonb | NO | - | Form data submitted |
| `generated_prompt` | text | NO | - | Final user prompt sent to API |
| `claude_response` | text | YES | - | AI response |
| `claude_model` | varchar(100) | YES | - | Model used (e.g., claude-opus-4) |
| `temperature` | numeric | YES | - | Temperature param |
| `max_tokens` | integer | YES | - | Max tokens param |
| `top_p` | numeric | YES | - | Top-p param |
| `system_prompt_sent` | text | YES | - | System prompt sent |
| `tokens_input` | integer | YES | - | Input tokens (from API) |
| `tokens_output` | integer | YES | - | Output tokens (from API) |
| `tokens_total` | integer | YES | - | Total tokens |
| `cost_usd` | numeric | YES | - | API cost in USD |
| `response_time_ms` | integer | YES | - | Response time in ms |
| `status` | text | YES | 'pending' | pending, completed, error |
| `error_message` | text | YES | - | Error message if failed |
| `ip_address` | varchar(45) | YES | - | User IP |
| `user_agent` | text | YES | - | User agent |
| `created_at` | timestamptz | YES | now() | Request timestamp |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)

---

### `prompt_dictionary`
Reusable prompt templates library.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `vertical` | text | NO | - | Vertical slug |
| `category` | varchar(100) | NO | - | Category (e.g., analysis, writing) |
| `title` | varchar(255) | NO | - | Prompt title |
| `prompt_text` | text | NO | - | Prompt template |
| `description` | text | YES | - | Prompt description |
| `tags` | jsonb | YES | - | Tags array |
| `use_cases` | text | YES | - | Use case examples |
| `access_level` | text | YES | 'free' | free, premium |
| `created_at` | timestamptz | YES | now() | Creation timestamp |
| `updated_at` | timestamptz | YES | now() | Last update |
| `created_by` | integer | YES | FK → users | Creator user ID |
| `search_vector` | tsvector | YES | - | Full-text search vector |

**Constraints:**
- PK: `id`
- FK: `created_by` → `users.id`

**Indexes:**
- Full-text search on `search_vector`

---

## System & Audit

### `settings`
Global system settings (key-value store).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `setting_key` | varchar(100) | NO | - | Setting key (unique) |
| `setting_value` | text | NO | - | Setting value (stringified) |
| `data_type` | text | YES | 'string' | string, integer, boolean, json |
| `description` | varchar(255) | YES | - | Setting description |
| `is_public` | boolean | YES | false | Public visibility flag |
| `updated_at` | timestamptz | YES | now() | Last update |
| `updated_by` | integer | YES | FK → users | Last editor |

**Constraints:**
- PK: `id`
- UNIQUE: `setting_key`
- FK: `updated_by` → `users.id`

**Important Settings:**
- `portal_system_prompt`: Portal-wide system prompt (Level 0 in hierarchy)
- `portal_api_params`: Portal-wide API defaults (Level 0 in hierarchy)

---

### `audit_logs`
Audit trail for all admin actions.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint | NO | PK, auto | Primary key |
| `user_id` | integer | YES | FK → users | User who performed action |
| `action` | varchar(255) | NO | - | Action performed |
| `entity_type` | varchar(100) | YES | - | Entity type (e.g., user, canvas) |
| `entity_id` | integer | YES | - | Entity ID |
| `ip_address` | varchar(45) | YES | - | IP address |
| `user_agent` | text | YES | - | User agent |
| `details` | jsonb | YES | - | Additional details |
| `created_at` | timestamptz | YES | now() | Action timestamp |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id`

---

### `tool_access_logs`
Tool/canvas access analytics.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | User accessing tool |
| `tool_name` | varchar(255) | NO | - | Tool name |
| `tool_slug` | varchar(255) | YES | - | Tool slug |
| `tool_path` | varchar(500) | YES | - | URL path |
| `vertical` | text | YES | - | Vertical slug |
| `ip_address` | varchar(45) | YES | - | IP address |
| `user_agent` | text | YES | - | User agent |
| `session_duration` | integer | YES | - | Session duration (seconds) |
| `accessed_at` | timestamptz | YES | now() | Access timestamp |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)

---

### `tool_versions`
Tool/canvas version management.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `tool_name` | varchar(255) | NO | - | Tool name |
| `version` | varchar(50) | NO | - | Version string |
| `file_path` | varchar(500) | NO | - | File path |
| `is_active` | boolean | YES | false | Active version flag |
| `uploaded_by` | integer | YES | FK → users | Uploader user ID |
| `uploaded_at` | timestamptz | YES | now() | Upload timestamp |
| `notes` | text | YES | - | Version notes |

**Constraints:**
- PK: `id`
- UNIQUE: `(tool_name, version)`
- FK: `uploaded_by` → `users.id`

---

### `formulario_feedback`
User feedback on forms.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `user_id` | integer | YES | FK → users | User who gave feedback |
| `page_url` | varchar(500) | YES | - | Page URL |
| `rating` | integer | YES | - | Rating (1-5) |
| `comment` | text | YES | - | Feedback comment |
| `created_at` | timestamptz | YES | now() | Feedback timestamp |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id`

---

### `data_requests`
LGPD/GDPR data requests (export, deletion).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `user_id` | integer | NO | FK → users | Requesting user |
| `request_type` | text | NO | - | export, delete, anonymize |
| `status` | text | YES | 'pending' | pending, processing, completed, rejected |
| `requested_at` | timestamptz | YES | now() | Request timestamp |
| `processed_at` | timestamptz | YES | - | Processing timestamp |
| `processed_by` | integer | YES | FK → users | Admin who processed |
| `notes` | text | YES | - | Admin notes |

**Constraints:**
- PK: `id`
- FK: `user_id` → `users.id` (ON DELETE CASCADE)
- FK: `processed_by` → `users.id`

---

## Indexes

### Critical Indexes

```sql
-- Canvas-Vertical assignments (Phase 3.5)
CREATE INDEX idx_canvas_vertical_slug ON canvas_vertical_assignments(vertical_slug);
CREATE INDEX idx_canvas_vertical_canvas_id ON canvas_vertical_assignments(canvas_id);

-- Full-text search
CREATE INDEX idx_user_documents_search ON user_documents USING gin(search_vector);
CREATE INDEX idx_user_submissions_search ON user_submissions USING gin(search_vector);
CREATE INDEX idx_prompt_dictionary_search ON prompt_dictionary USING gin(search_vector);

-- Performance indexes
CREATE INDEX idx_sessions_user_id ON sessions(user_id);
CREATE INDEX idx_prompt_history_user_id ON prompt_history(user_id);
CREATE INDEX idx_tool_access_logs_user_id ON tool_access_logs(user_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at DESC);
```

---

## Common Queries

### Get Canvas by Vertical (Many-to-Many)

```sql
-- Get all active canvas for a vertical
SELECT DISTINCT ct.*, cva.display_order, cva.vertical_slug
FROM canvas_templates ct
INNER JOIN canvas_vertical_assignments cva ON ct.id = cva.canvas_id
WHERE cva.vertical_slug = 'iatr'
  AND ct.is_active = TRUE
ORDER BY cva.display_order ASC, ct.name ASC;
```

### Get Verticals for Canvas

```sql
-- Get all verticals assigned to a canvas
SELECT vertical_slug, display_order
FROM canvas_vertical_assignments
WHERE canvas_id = 10
ORDER BY display_order;
```

### User Submission with Canvas Info

```sql
-- Get submissions with canvas and vertical info
SELECT
    us.id,
    us.title,
    us.status,
    us.created_at,
    ct.name AS canvas_name,
    ct.slug AS canvas_slug,
    us.vertical_slug,
    u.name AS user_name
FROM user_submissions us
INNER JOIN canvas_templates ct ON us.canvas_template_id = ct.id
INNER JOIN users u ON us.user_id = u.id
WHERE us.user_id = $1
ORDER BY us.created_at DESC
LIMIT 20;
```

### API Usage by User

```sql
-- Get API usage summary for a user
SELECT
    user_id,
    COUNT(*) AS total_requests,
    SUM(tokens_total) AS total_tokens,
    SUM(cost_usd) AS total_cost_usd,
    AVG(response_time_ms) AS avg_response_time_ms
FROM prompt_history
WHERE user_id = $1
  AND created_at >= NOW() - INTERVAL '30 days'
GROUP BY user_id;
```

### Active Sessions Count

```sql
-- Get active sessions (last activity < 1 hour)
SELECT COUNT(*) AS active_sessions
FROM sessions
WHERE last_activity >= NOW() - INTERVAL '1 hour';
```

---

## PNCP & Licitações

### `pncp_editais`
Editais coletados da API PNCP pelo workflow N8N "PNCP Daily Monitor v3". Análise IA escrita pelo workflow "IATR - Análise de Edital".

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | integer | NO | PK, auto | Primary key |
| `pncp_id` | text | NO | - | Identificador PNCP (unique). Formato: `CNPJ-1-SEQNUM/ANO` |
| `numero` | text | YES | - | Número do edital (ex: "012/2025") |
| `titulo` | text | NO | - | Título do edital |
| `objeto` | text | YES | - | Descrição do objeto (truncada em 2000 chars) |
| `orgao` | text | YES | - | Nome do órgão contratante |
| `orgao_cnpj` | text | YES | - | CNPJ do órgão |
| `uf` | text | YES | - | Estado (RJ, SP, MG, BA, PE, DF) |
| `municipio` | text | YES | - | Município |
| `modalidade` | text | YES | - | Modalidade de licitação |
| `valor_estimado` | numeric | YES | - | Valor estimado (0 = sigiloso) |
| `data_abertura` | timestamptz | YES | - | Data de abertura/publicação |
| `data_encerramento` | timestamptz | YES | - | Data de encerramento |
| `url_pncp` | text | YES | - | URL completa no portal PNCP |
| `status` | text | YES | 'aberto' | Status do edital |
| `keywords_matched` | text[] | YES | - | Keywords que matcharam na filtragem |
| `raw_data` | jsonb | YES | - | Dados brutos da API PNCP |
| `status_analise` | text | YES | 'pendente' | pendente/em_analise/concluida/erro |
| `analise_resultado` | jsonb | YES | - | Resultado da análise IA (chave principal: `resumo_executivo`) |
| `analise_modelo` | text | YES | - | Modelo LLM usado (ex: claude-haiku-4-5) |
| `analise_tokens` | integer | YES | - | Total de tokens consumidos |
| `analise_erro` | text | YES | - | Mensagem de erro se análise falhou |
| `analise_concluida_em` | timestamptz | YES | - | Timestamp de conclusão da análise |
| `created_at` | timestamptz | YES | now() | Criação do registo |
| `updated_at` | timestamptz | YES | now() | Última actualização |

**Constraints:**
- PK: `id`
- UNIQUE: `pncp_id`

**Escrita por:** N8N workflows (via user `n8n_worker` com SELECT, INSERT, UPDATE)
**Lida por:** Portal (via `sunyata_app`)

**Queries comuns:**
```sql
-- Buscar edital por pncp_id (deep-links do email)
SELECT * FROM pncp_editais WHERE pncp_id = ?;

-- Polling de status de análise (frontend JS)
SELECT id, status_analise, analise_resultado, analise_modelo, analise_tokens, analise_erro, analise_concluida_em
FROM pncp_editais WHERE id = ?;

-- Listar editais por UF
SELECT * FROM pncp_editais WHERE uf = ? ORDER BY data_encerramento;
```

---

## Migration Notes

**See:** `docs/MIGRATIONS.md` for full migration changelog.

**Latest:** PNCP Phase A (2026-02-24) - PNCP Editais + AI Analysis
- Added `pncp_editais` table (migration 013)
- User `n8n_worker` with SELECT, INSERT, UPDATE grants
- JSONB `analise_resultado` stores AI analysis (key: `resumo_executivo`)

**Previous:** Phase 3.5 (2026-02-19) - Many-to-Many Canvas-Vertical Assignment
- Added `canvas_vertical_assignments` junction table
- Removed `canvas_templates.vertical` column
- Migrated 55 existing assignments to junction table
