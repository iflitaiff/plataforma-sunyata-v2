#!/bin/bash
# Collect software versions and key params → system_info table
# CT105: /opt/monitoring/collect_system_info.sh
# Cron: 0 */6 * * * /opt/monitoring/collect_system_info.sh >> /var/log/collect_system_info.log 2>&1

PG_HOST="192.168.100.10"
PG_DB="sunyata_platform"

psql_read() {
  PGPASSWORD="Gr4f4n4R34d3r2026!" psql -h "$PG_HOST" -U grafana_reader -d "$PG_DB" -t -A -c "$1" 2>/dev/null
}

upsert() {
  local component="$1" version="$2" status="$3" params="$4"
  PGPASSWORD="N8n-W0rk3r-2026!" psql -h "$PG_HOST" -U n8n_worker -d "$PG_DB" -q -c \
    "INSERT INTO system_info (component,version,status,params,checked_at)
     VALUES ('${component}','${version}','${status}','${params}'::jsonb,NOW())
     ON CONFLICT (component) DO UPDATE SET
       version=EXCLUDED.version,status=EXCLUDED.status,
       params=EXCLUDED.params,checked_at=EXCLUDED.checked_at;" 2>/dev/null
}

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Starting collection..."

# ── PostgreSQL ──────────────────────────────────────────────────────────────
PG_VER=$(psql_read "SELECT split_part(version(),' ',2)")
PG_CONNS=$(psql_read "SELECT count(*) FROM pg_stat_activity WHERE state='active'")
PG_MAX=$(psql_read "SHOW max_connections")
PG_SIZE=$(psql_read "SELECT pg_size_pretty(pg_database_size('sunyata_platform'))")
PG_STATUS="ok"; [ -z "$PG_VER" ] && PG_STATUS="error"
upsert "postgresql" "${PG_VER:-unknown}" "$PG_STATUS" \
  "{\"active_connections\":\"${PG_CONNS:-?}\",\"max_connections\":\"${PG_MAX:-?}\",\"db_size\":\"${PG_SIZE:-?}\"}"
echo "  PostgreSQL: ${PG_VER} | conns=${PG_CONNS}/${PG_MAX} | size=${PG_SIZE} [${PG_STATUS}]"

# ── Redis ───────────────────────────────────────────────────────────────────
REDIS_VER=$(redis-cli -h "$PG_HOST" INFO server 2>/dev/null | grep "redis_version:" | cut -d: -f2 | tr -d '\r ')
REDIS_MEM=$(redis-cli -h "$PG_HOST" INFO memory 2>/dev/null | grep "^used_memory_human:" | cut -d: -f2 | tr -d '\r ')
REDIS_MAXMEM=$(redis-cli -h "$PG_HOST" INFO memory 2>/dev/null | grep "^maxmemory_human:" | cut -d: -f2 | tr -d '\r ')
REDIS_CLIENTS=$(redis-cli -h "$PG_HOST" INFO clients 2>/dev/null | grep "^connected_clients:" | cut -d: -f2 | tr -d '\r ')
REDIS_STATUS="ok"; [ -z "$REDIS_VER" ] && REDIS_STATUS="error"
upsert "redis" "${REDIS_VER:-unknown}" "$REDIS_STATUS" \
  "{\"used_memory\":\"${REDIS_MEM:-?}\",\"maxmemory\":\"${REDIS_MAXMEM:-0B}\",\"connected_clients\":\"${REDIS_CLIENTS:-?}\"}"
echo "  Redis: ${REDIS_VER} | mem=${REDIS_MEM} | clients=${REDIS_CLIENTS} [${REDIS_STATUS}]"

# ── N8N ─────────────────────────────────────────────────────────────────────
N8N_HTTP=$(curl -s --max-time 5 -o /dev/null -w "%{http_code}" http://192.168.100.14:5678/healthz 2>/dev/null)
N8N_STATUS="ok"; [ "$N8N_HTTP" != "200" ] && N8N_STATUS="error"
# N8N version hardcoded (not exposed in healthz; update on upgrade)
N8N_VER="2.7.5"
N8N_WFLOWS=$(PGPASSWORD="Gr4f4n4R34d3r2026!" psql -h "$PG_HOST" -U grafana_reader -d "$PG_DB" -t -A \
  -c "SELECT COUNT(*) FROM system_events WHERE event_type LIKE 'iatr%' AND created_at > NOW()-INTERVAL '24h'" 2>/dev/null || echo "?")
upsert "n8n" "$N8N_VER" "$N8N_STATUS" \
  "{\"url\":\"http://192.168.100.14:5678\",\"http_status\":\"${N8N_HTTP}\",\"iatr_events_24h\":\"${N8N_WFLOWS:-?}\"}"
echo "  N8N: ${N8N_VER} | http=${N8N_HTTP} [${N8N_STATUS}]"

# ── LiteLLM ─────────────────────────────────────────────────────────────────
LITELLM_RESP=$(curl -s --max-time 10 -H "Authorization: Bearer sk-sunyata-ec909e5420572a4c8c496822a9459bcdec1391c7" \
  http://192.168.100.13:4000/health 2>/dev/null)
LITELLM_STATUS="ok"
[ -z "$LITELLM_RESP" ] && LITELLM_STATUS="error"
LITELLM_HEALTHY=$(echo "$LITELLM_RESP" | python3 -c \
  "import sys,json; d=json.load(sys.stdin); print(len(d.get('healthy_endpoints',[])))" 2>/dev/null || echo "0")
LITELLM_UNHEALTHY=$(echo "$LITELLM_RESP" | python3 -c \
  "import sys,json; d=json.load(sys.stdin); print(len(d.get('unhealthy_endpoints',[])))" 2>/dev/null || echo "0")
upsert "litellm" "1.81.3" "$LITELLM_STATUS" \
  "{\"healthy_endpoints\":\"${LITELLM_HEALTHY}\",\"unhealthy_endpoints\":\"${LITELLM_UNHEALTHY}\",\"models\":\"10\"}"
echo "  LiteLLM: 1.81.3 | healthy=${LITELLM_HEALTHY} unhealthy=${LITELLM_UNHEALTHY} [${LITELLM_STATUS}]"

# ── FastAPI (AI Microservice) ────────────────────────────────────────────────
FASTAPI_RESP=$(curl -s --max-time 5 http://192.168.100.10/api/ai/health 2>/dev/null)
FASTAPI_VER=$(echo "$FASTAPI_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('version','?'))" 2>/dev/null || echo "?")
FASTAPI_STATUS=$(echo "$FASTAPI_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print('ok' if d.get('status')=='ok' else 'error')" 2>/dev/null || echo "error")
upsert "fastapi" "${FASTAPI_VER:-?}" "$FASTAPI_STATUS" \
  "{\"url\":\"http://192.168.100.10/api/ai/\"}"
echo "  FastAPI: ${FASTAPI_VER} [${FASTAPI_STATUS}]"

# ── Nginx + PHP ──────────────────────────────────────────────────────────────
NGINX_RESP=$(curl -sI --max-time 5 http://192.168.100.10/login 2>/dev/null)
NGINX_VER=$(echo "$NGINX_RESP" | grep -i "^Server:" | grep -oP 'nginx/\K[\d.]+' || echo "1.24.0")
NGINX_STATUS_CODE=$(echo "$NGINX_RESP" | grep -oP 'HTTP/\S+ \K\d+' | head -1 || echo "0")
NGINX_STATUS="ok"; [ "$NGINX_STATUS_CODE" -lt 200 ] 2>/dev/null || [ "$NGINX_STATUS_CODE" -ge 500 ] 2>/dev/null && NGINX_STATUS="error"
[ "$NGINX_STATUS_CODE" = "0" ] && NGINX_STATUS="error"
# PHP version from FastAPI health (PHP-FPM doesn't expose version in headers by default)
PHP_VER=$(psql_read "SELECT params->>'php_version' FROM system_info WHERE component='nginx'" 2>/dev/null || echo "8.3")
upsert "nginx" "${NGINX_VER:-1.24.0}" "$NGINX_STATUS" \
  "{\"php_version\":\"${PHP_VER:-8.3}\",\"http_status\":\"${NGINX_STATUS_CODE}\"}"
echo "  Nginx: ${NGINX_VER} | PHP: ${PHP_VER} | http=${NGINX_STATUS_CODE} [${NGINX_STATUS}]"

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Done."
