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
#   vm102  — AI sandbox VM (192.168.100.12) via qm guest exec (legacy)
#   ct103  — LiteLLM gateway LXC (192.168.100.13) via pct exec
#   ct104  — N8N automation LXC (192.168.100.14) via pct exec
#
# Options:
#   SSH_CMD_RETRIES=N   Number of retries on connection failure (default: 1)
#   SSH_CMD_TIMEOUT=N   SSH connection timeout in seconds (default: 10)
#   SSH_CMD_LOG=path    Log file path (default: logs/ssh-cmd.log)
#
# Examples:
#   ssh-cmd.sh host "uptime"
#   ssh-cmd.sh vm100 "psql -c \"SELECT * FROM pncp_editais WHERE id = 84\""
#   ssh-cmd.sh ct103 "docker compose -f /opt/litellm/docker-compose.yml ps"
#   ssh-cmd.sh ct104 "docker logs n8n | tail -50"
#   ssh-cmd.sh vm100 -f migrations/007-drafts.sql
#   ssh-cmd.sh vm100 -f scripts/reset-password.php

set -euo pipefail

SSH_HOST="ovh"  # Uses ~/.ssh/config ControlMaster
SSH_TIMEOUT="${SSH_CMD_TIMEOUT:-10}"
SSH_RETRIES="${SSH_CMD_RETRIES:-1}"
SSH_OPTS="-o ConnectTimeout=$SSH_TIMEOUT -o ServerAliveInterval=15 -o ServerAliveCountMax=2"
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
        sql)      echo "psql_sunyata" ;;
        *)        echo "bash" ;;
    esac
}

# ssh_with_retry — run ssh with retry logic
# Usage: ssh_with_retry [ssh args...]
ssh_with_retry() {
    local attempt=0 rc=0
    while true; do
        rc=0
        ssh $SSH_OPTS "$@" || rc=$?

        # rc=255 = SSH connection failure (not remote command failure)
        if [[ $rc -eq 255 && $attempt -lt $SSH_RETRIES ]]; then
            attempt=$((attempt + 1))
            log "retry" "ssh " "attempt $attempt/$SSH_RETRIES (rc=$rc)"
            sleep 2
            continue
        fi
        return $rc
    done
}

# All run_on_* functions use bash -s to pipe the command via stdin.
# This avoids nested quoting issues entirely — the command never passes
# through a shell argument boundary.

run_on_host() {
    printf '%s' "$1" | ssh_with_retry "$SSH_HOST" "bash -s"
}

run_on_vm100() {
    # Pipe command to host, which pipes to vm100 via bash -s
    printf '%s' "$1" | ssh_with_retry "$SSH_HOST" "ssh -o ConnectTimeout=$SSH_TIMEOUT 192.168.100.10 'bash -s'"
}

run_on_vm102() {
    # VM102 SSH is broken (Docker iptables conflict), use qm guest exec
    # qm guest exec doesn't support stdin, so we base64-encode the command
    local encoded
    encoded=$(printf '%s' "$1" | base64 -w0)
    local result
    result=$(ssh_with_retry "$SSH_HOST" "qm guest exec 102 -- bash -c 'echo $encoded | base64 -d | bash'" 2>&1)

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
        echo "$result"
    fi
}

run_on_ct103() {
    # LXC container — pipe through pct exec with bash -s
    printf '%s' "$1" | ssh_with_retry "$SSH_HOST" "pct exec 103 -- bash -s"
}

run_on_ct104() {
    # LXC container (N8N automation) — pipe through pct exec with bash -s
    printf '%s' "$1" | ssh_with_retry "$SSH_HOST" "pct exec 104 -- bash -s"
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
