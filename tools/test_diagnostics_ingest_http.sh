#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
tmp_root="$(mktemp -d)"
port="$((19000 + RANDOM % 1000))"
server_log="$tmp_root/server.log"
cleanup() {
  if [[ -n "${server_pid:-}" ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
  rm -rf -- "$tmp_root"
}
trap cleanup EXIT

INSPECTIONS_ADMIN_PIN="123456" \
DIAGNOSTICS_STORAGE_ROOT="$tmp_root/storage" \
MITTI_INGEST_MODE="shadow" \
php -S "127.0.0.1:$port" -t "$repo_root" >"$server_log" 2>&1 &
server_pid=$!
for _ in $(seq 1 50); do curl -sS "http://127.0.0.1:$port/" >/dev/null 2>&1 && break; sleep 0.1; done

endpoint="http://127.0.0.1:$port/api/diagnostics-ingest-admin.php"
code="$(curl -sS -o "$tmp_root/no-pin.json" -w '%{http_code}' -H 'Content-Type: application/json' --data '{"action":"connection-status"}' "$endpoint")"
[[ "$code" == "403" ]]
code="$(curl -sS -o "$tmp_root/wrong-pin.json" -w '%{http_code}' -H 'Content-Type: application/json' --data '{"action":"connection-status","adminPin":"000000"}' "$endpoint")"
[[ "$code" == "403" ]]
code="$(curl -sS -o "$tmp_root/status.json" -w '%{http_code}' -H 'Content-Type: application/json' --data '{"action":"connection-status","adminPin":"123456"}' "$endpoint")"
[[ "$code" == "200" ]]
! grep -Eq 'MITTI_API_TOKEN|OPENAI_API_KEY|synthetic-token|api_key' "$tmp_root/status.json"
! grep -Eq 'MITTI_API_TOKEN|OPENAI_API_KEY' "$repo_root/inspekcie-admin.html"
echo "Mitti ingest admin authorization and secret boundary: PASS"
