#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
storage_root="$test_root/storage"
session_root="$test_root/sessions"
responses="$test_root/responses"
server_log="$test_root/php-server.log"
server_pid=""
port="$((20000 + RANDOM % 20000))"
base_url="http://127.0.0.1:$port"

cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" >/dev/null 2>&1 || true
    wait "$server_pid" >/dev/null 2>&1 || true
  fi
  chmod -R u+w -- "$test_root" >/dev/null 2>&1 || true
  rm -rf -- "$test_root"
}
trap cleanup EXIT

fail() {
  echo "Diagnostics renderer HTTP test failed: $1" >&2
  if [[ -f "$server_log" ]]; then
    tail -n 100 "$server_log" >&2 || true
  fi
  exit 1
}

json_field() {
  python -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$1" "$2"
}

export DIAGNOSTICS_STORAGE_ROOT="$storage_root"
export DIAGNOSTICS_PIN_PEPPER="renderer-test-pin-pepper-0123456789-abcdef"
export DIAGNOSTICS_AUDIT_HMAC_KEY="renderer-test-audit-key-0123456789-abcdef"
export DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST="1"

fixture="$(php "$repo_root/tools/test_diagnostics_delivery_fixture.php" --prepare "$storage_root")"
access_id="$(json_field "$fixture" access_id)"
pin="$(json_field "$fixture" pin)"

mkdir -p "$session_root" "$responses"
cd "$repo_root"
php -d "session.save_path=$session_root" -S "127.0.0.1:$port" -t "$repo_root" >"$server_log" 2>&1 &
server_pid=$!

ready=0
for _attempt in 1 2 3 4 5 6 7 8 9 10; do
  if curl -sS -o "$responses/ready.html" "$base_url/inspekcia.html?access=$access_id"; then
    ready=1
    break
  fi
  sleep 0.2
done
[[ "$ready" == "1" ]] || fail "PHP built-in server did not start."

page_code="$(curl -sS -D "$responses/page.headers" -o "$responses/page.html" -w '%{http_code}' "$base_url/inspekcia.html?access=$access_id")"
[[ "$page_code" == "200" ]] || fail "Client page must return 200."
grep -Fq '<meta name="robots" content="noindex,nofollow,noarchive">' "$responses/page.html" || fail "Client page must be noindex."
grep -Fq '<meta name="referrer" content="no-referrer">' "$responses/page.html" || fail "Client page must suppress referrers."
grep -Fq 'JSS/diagnostics-report.js' "$responses/page.html" || fail "Renderer script is not linked."
grep -Fq 'JSS/diagnostics-client.js' "$responses/page.html" || fail "Lifecycle script is not linked."
if grep -Eqi '(src|href)="https?://' "$responses/page.html"; then
  fail "Client page must not load third-party assets."
fi

for asset in styles/diagnostics-report.css JSS/diagnostics-report.js JSS/diagnostics-client.js; do
  asset_code="$(curl -sS -o "$responses/$(basename "$asset")" -w '%{http_code}' "$base_url/$asset")"
  [[ "$asset_code" == "200" ]] || fail "Client asset $asset must return 200."
done

unauthenticated="$(curl -sS -o "$responses/unauthenticated.json" -w '%{http_code}' "$base_url/api/diagnostics-report.php")"
[[ "$unauthenticated" == "401" ]] || fail "Report must remain unavailable before unlock."

unlock_payload="{\"action\":\"unlock\",\"accessId\":\"$access_id\",\"pin\":\"$pin\"}"
unlock_code="$(curl -sS -c "$responses/cookies.txt" -H 'Content-Type: application/json' -d "$unlock_payload" -o "$responses/unlock.json" -w '%{http_code}' "$base_url/api/diagnostics-auth.php")"
[[ "$unlock_code" == "200" ]] || fail "A valid PIN must unlock the report."

python - "$responses/unlock.json" "$access_id" <<'PY'
import json, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
assert body["authenticated"] is True
assert body["accessId"] == sys.argv[2]
assert len(body["csrfToken"]) == 64
PY

report_code="$(curl -sS -b "$responses/cookies.txt" -o "$responses/report.json" -w '%{http_code}' "$base_url/api/diagnostics-report.php")"
[[ "$report_code" == "200" ]] || fail "Authenticated client report must return 200."
python - "$responses/report.json" <<'PY'
import json, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
assert body["document_type"] == "client_report"
assert body["schema_version"] == "1.0.0"
assert body["issues"]
assert body["recommendations"]
PY

if grep -E 'PHP (Warning|Notice|Fatal error)' "$server_log" >/dev/null; then
  fail "PHP server emitted a warning, notice or fatal error."
fi

echo "Diagnostics renderer HTTP tests passed: page/assets, noindex, unlock and client report delivery."
