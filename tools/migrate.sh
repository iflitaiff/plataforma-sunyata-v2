#!/usr/bin/env bash
# tools/migrate.sh — Apply pending database migrations to VM100
#
# Usage:
#   tools/migrate.sh          # show pending + apply interactively
#   tools/migrate.sh --dry-run  # show pending only, no apply
#   tools/migrate.sh --yes      # apply all pending without prompt
#
# Tracks applied migrations in schema_migrations table.
# Safe to run repeatedly — already-applied migrations are skipped.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATIONS_DIR="$(cd "${SCRIPT_DIR}/../migrations" && pwd)"
DRY_RUN=false
AUTO_YES=false

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    --yes|-y)  AUTO_YES=true ;;
  esac
done

# Validate filename format before any SQL interpolation (SQL injection prevention)
# Allows: digits, letters, hyphens, underscores, .sql extension
validate_filename() {
  local filename="$1"
  if [[ ! "$filename" =~ ^[0-9]+[a-zA-Z0-9_-]*\.sql$ ]]; then
    echo "  SECURITY ERROR: Invalid migration filename '$filename'."
    echo "  Only digits, letters, hyphens and underscores are allowed."
    exit 1
  fi
}

# Get list of already-applied migrations from DB
echo "==> Checking applied migrations on VM100..."

# First verify schema_migrations table exists (avoids masking connection errors)
if ! tools/ssh-cmd.sh vm100 "sudo -u postgres psql -d sunyata_platform -c \
  'SELECT 1 FROM schema_migrations LIMIT 1;'" >/dev/null 2>&1; then
  echo "  ERROR: Cannot connect to DB or schema_migrations table not found."
  echo "  Run: tools/ssh-cmd.sh vm100 -f /tmp/create_migrations_table.sql"
  exit 1
fi

# Read applied versions (grep -v is safe to fail on empty result)
APPLIED=$(tools/ssh-cmd.sh vm100 "sudo -u postgres psql -d sunyata_platform -t -c \
  'SELECT version FROM schema_migrations ORDER BY version;'" 2>/dev/null \
  | tr -d ' \r' | grep -v '^$') || APPLIED=""

# Find pending migrations — sort -V handles numeric ordering correctly (9 < 010)
PENDING=()
while IFS= read -r filepath; do
  filename=$(basename "$filepath")

  # Security: validate filename before any processing
  validate_filename "$filename"

  # Extract version prefix (leading digits only)
  version=$(echo "$filename" | sed 's/^\([0-9]*\).*/\1/' | sed 's/^0*//')
  version_padded=$(printf '%03d' "$version" 2>/dev/null || echo "$version")

  if echo "$APPLIED" | grep -q "^${version_padded}$" || echo "$APPLIED" | grep -q "^${version}$"; then
    : # already applied
  else
    PENDING+=("$filepath")
  fi
done < <(find "$MIGRATIONS_DIR" -maxdepth 1 -name "*.sql" | sort -V)

if [[ ${#PENDING[@]} -eq 0 ]]; then
  echo "  OK — No pending migrations. Database is up to date."
  exit 0
fi

echo ""
echo "==> Pending migrations (${#PENDING[@]}):"
for f in "${PENDING[@]}"; do
  echo "  • $(basename "$f")"
done

if $DRY_RUN; then
  echo ""
  echo "  (dry-run — no changes made)"
  exit 0
fi

if ! $AUTO_YES; then
  echo ""
  read -r -p "Apply these migrations to VM100? [y/N] " confirm
  [[ "$confirm" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
fi

# Apply each pending migration
echo ""
for filepath in "${PENDING[@]}"; do
  filename=$(basename "$filepath")
  validate_filename "$filename"  # re-validate at apply time

  version=$(echo "$filename" | sed 's/^\([0-9]*\).*/\1/' | sed 's/^0*//')
  version_padded=$(printf '%03d' "$version" 2>/dev/null || echo "$version")

  echo "==> Applying: $filename"
  tools/ssh-cmd.sh vm100 -f "$filepath"

  # Record as applied (version_padded and filename are validated — safe to interpolate)
  tools/ssh-cmd.sh vm100 "sudo -u postgres psql -d sunyata_platform -c \
    \"INSERT INTO schema_migrations (version, filename, applied_by) \
      VALUES ('${version_padded}', '${filename}', 'migrate.sh') \
      ON CONFLICT (version) DO NOTHING;\""

  echo "  OK: $filename applied and recorded."
  echo ""
done

echo "==> All pending migrations applied."
