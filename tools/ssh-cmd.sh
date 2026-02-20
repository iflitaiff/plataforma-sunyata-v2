#!/usr/bin/env bash
# ssh-cmd.sh — Unified SSH access for Sunyata multi-agent team
# Usage:
#   ssh-cmd.sh <target> "command"           Run command on target
#   ssh-cmd.sh <target> -f script.sh        Upload and run script (base64, no escaping issues)
#   ssh-cmd.sh <target> -f script.sh args   Upload and run script with arguments
#
# Targets:
#   host   — OVH Proxmox host (158.69.25.114)
#   vm100  — Portal dev VM (192.168.100.10) via host hop
#   vm102  — AI sandbox VM (192.168.100.12) via qm guest exec (legacy, being replaced)
#   ct103  — LiteLLM gateway LXC (192.168.100.13) via pct exec
#   ct104  — N8N automation LXC (192.168.100.14) via pct exec
#
# Examples:
#   ssh-cmd.sh host "uptime"
#   ssh-cmd.sh vm100 "tail -20 /var/www/sunyata/app/logs/php_errors.log"
#   ssh-cmd.sh ct103 "docker compose -f /opt/litellm/docker-compose.yml ps"
#   ssh-cmd.sh ct104 "docker logs n8n | tail -50"
#   ssh-cmd.sh vm100 -f migrations/007-drafts.sql
#   ssh-cmd.sh vm100 -f scripts/reset-password.php

set -euo pipefail

SSH_HOST="ovh"  # Uses ~/.ssh/config ControlMaster
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG_FILE="${SSH_CMD_LOG:-$SCRIPT_DIR/../logs/ssh-cmd.log}"

log() {
    local target="$1" action="$2" detail="$3"
    local dir
    dir=$(dirname "$LOG_FILE")
    [[ -d "$dir" ]] || mkdir -p "$dir"
    printf '%s | %-5s | %-4s | %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$target" "$action" "$detail" >> "$LOG_FILE"
}

usage() {
    sed -n '2,/^$/s/^# //p' "$0"
    exit 1
}

detect_interpreter() {
    local file="$1"
    case "${file##*.}" in
        sh|bash)  echo "bash" ;;
        php)      echo "php" ;;
        py)       echo "python3" ;;
        sql)      echo "psql_sunyata" ;;  # special case
        *)        echo "bash" ;;
    esac
}

run_on_host() {
    ssh "$SSH_HOST" "$1"
}

run_on_vm100() {
    ssh "$SSH_HOST" "ssh 192.168.100.10 '$1'"
}

run_on_vm102() {
    # VM102 SSH is broken (Docker iptables conflict), use qm guest exec
    local result
    result=$(ssh "$SSH_HOST" "qm guest exec 102 -- bash -c '$1'" 2>&1)

    # qm guest exec returns JSON — extract out-data and err-data
    if command -v jq &>/dev/null && echo "$result" | jq -e . &>/dev/null 2>&1; then
        local exitcode out_data err_data
        exitcode=$(echo "$result" | jq -r '.exitcode // 0')
        out_data=$(echo "$result" | jq -r '."out-data" // empty')
        err_data=$(echo "$result" | jq -r '."err-data" // empty')
        [[ -n "$out_data" ]] && printf '%s' "$out_data"
        [[ -n "$err_data" ]] && printf '%s' "$err_data" >&2
        return "$exitcode"
    else
        # jq not available or non-JSON output — pass through raw
        echo "$result"
    fi
}

run_on_ct103() {
    # CT103 is an LXC container — pct exec is reliable and fast
    ssh "$SSH_HOST" "pct exec 103 -- bash -c '$1'"
}

run_on_ct104() {
    # CT104 is an LXC container (N8N automation)
    ssh "$SSH_HOST" "pct exec 104 -- bash -c '$1'"
}

run_file_on_target() {
    local target="$1"
    local file="$2"
    shift 2
    local extra_args="$*"

    if [[ ! -f "$file" ]]; then
        echo "Error: File not found: $file" >&2
        exit 1
    fi

    local interp
    interp=$(detect_interpreter "$file")
    local encoded
    encoded=$(base64 -w0 "$file")

    local decode_and_run
    if [[ "$interp" == "psql_sunyata" ]]; then
        decode_and_run="echo '$encoded' | base64 -d | sudo -u postgres psql sunyata_platform"
    else
        decode_and_run="echo '$encoded' | base64 -d | $interp $extra_args"
    fi

    case "$target" in
        host)   run_on_host "$decode_and_run" ;;
        vm100)  run_on_vm100 "$decode_and_run" ;;
        vm102)  run_on_vm102 "$decode_and_run" ;;
        ct103)  run_on_ct103 "$decode_and_run" ;;
        ct104)  run_on_ct104 "$decode_and_run" ;;
    esac
}

# --- Main ---

[[ $# -lt 2 ]] && usage

target="$1"
shift

case "$target" in
    host|vm100|vm102|ct103|ct104) ;;
    *) echo "Error: Unknown target '$target'. Use: host, vm100, vm102, ct103, ct104" >&2; exit 1 ;;
esac

if [[ "$1" == "-f" ]]; then
    [[ $# -lt 2 ]] && { echo "Error: -f requires a file path" >&2; exit 1; }
    local_file="${*:2}"
    log "$target" "file" "$local_file"
    run_file_on_target "$target" ${@:2}
    rc=$?
    log "$target" "exit" "rc=$rc file=$local_file"
    exit $rc
else
    cmd="$*"
    # Log truncated command (max 120 chars) to avoid huge log lines
    log "$target" "cmd " "${cmd:0:120}"
    case "$target" in
        host)   run_on_host "$cmd" ;;
        vm100)  run_on_vm100 "$cmd" ;;
        vm102)  run_on_vm102 "$cmd" ;;
        ct103)  run_on_ct103 "$cmd" ;;
        ct104)  run_on_ct104 "$cmd" ;;
    esac
    rc=$?
    log "$target" "exit" "rc=$rc"
    exit $rc
fi
