#!/usr/bin/env bash
# cleanup-system-events.sh
# Retention de system_events: remove eventos com mais de 90 dias.
# Estimativa de volume (spec 8.3): ~1500 rows/dia, ~135K rows em 90 dias.
#
# Exemplo de instalação em VM100 (crontab do usuário postgres):
#   0 3 * * * /var/www/sunyata/tools/cleanup-system-events.sh >> /var/log/system-events-cleanup.log 2>&1

set -euo pipefail

DB_NAME="${DB_NAME:-sunyata_platform}"
DB_USER="${DB_USER:-postgres}"

psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -c "DELETE FROM system_events WHERE created_at < NOW() - INTERVAL '90 days';"
