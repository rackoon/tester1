#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PID_FILE="$BASE_DIR/runtime/sip-agent.pid"
OUT_LOG="$BASE_DIR/runtime/sip-agent-supervisor.log"

is_running() {
  if [[ -f "$PID_FILE" ]]; then
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      return 0
    fi
  fi
  return 1
}

start() {
  mkdir -p "$BASE_DIR/runtime"
  if is_running; then
    echo "running"
    return 0
  fi
  nohup /usr/bin/python3 "$BASE_DIR/scripts/sip_agent.py" >>"$OUT_LOG" 2>&1 &
  echo "$!" > "$PID_FILE"
  sleep 1
  if is_running; then
    echo "started"
  else
    echo "failed"
    return 1
  fi
}

stop() {
  if ! is_running; then
    rm -f "$PID_FILE"
    echo "stopped"
    return 0
  fi
  local pid
  pid="$(cat "$PID_FILE")"
  kill "$pid" 2>/dev/null || true
  sleep 1
  kill -9 "$pid" 2>/dev/null || true
  rm -f "$PID_FILE"
  echo "stopped"
}

status() {
  if is_running; then
    echo "running"
  else
    echo "stopped"
  fi
}

case "${1:-}" in
  start) start ;;
  stop) stop ;;
  restart) stop; start ;;
  status) status ;;
  *) echo "usage: $0 {start|stop|restart|status}"; exit 2 ;;
esac
