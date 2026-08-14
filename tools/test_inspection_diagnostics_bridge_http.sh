#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
web_root="$test_root/webroot"
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
  echo "Inspection diagnostics bridge HTTP test failed: $1" >&2
  if [[ -f "$server_log" ]]; then
    tail -n 100 "$server_log" >&2 || true
  fi
  exit 1
}

json_field() {
  python -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$1" "$2"
}

export DIAGNOSTICS_STORAGE_ROOT="$storage_root"
export DIAGNOSTICS_PIN_PEPPER="bridge-test-pin-pepper-0123456789-abcdef"
export DIAGNOSTICS_AUDIT_HMAC_KEY="bridge-test-audit-key-0123456789-abcdef"
export DIAGNOSTICS_RATE_ACCESS_IP_MAX="3"
export DIAGNOSTICS_RATE_IP_MAX="30"
export DIAGNOSTICS_RATE_WINDOW_SECONDS="300"
export DIAGNOSTICS_RATE_LOCKOUT_SECONDS="120"
export DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST="1"
export INSPECTIONS_ADMIN_PIN="bridge-admin-pin"

fixture="$(php "$repo_root/tools/test_diagnostics_delivery_fixture.php" --prepare "$storage_root")"
access_id="$(json_field "$fixture" access_id)"
pin="$(json_field "$fixture" pin)"
report_id="$(json_field "$fixture" report_id)"
priced_access_id="$(json_field "$fixture" priced_access_id)"
priced_pin="$(json_field "$fixture" priced_pin)"

inactive="$(php "$repo_root/tools/test_diagnostics_delivery_fixture.php" --create "$report_id" 1.0)"
inactive_access_id="$(json_field "$inactive" access_id)"
inactive_pin="$(json_field "$inactive" pin)"
php "$repo_root/tools/test_diagnostics_delivery_fixture.php" --revoke "$inactive_access_id" >/dev/null

expired="$(php "$repo_root/tools/test_diagnostics_delivery_fixture.php" --create "$report_id" 1.0)"
expired_access_id="$(json_field "$expired" access_id)"
expired_pin="$(json_field "$expired" pin)"
python - "$storage_root/access/grants/$expired_access_id.json" <<'PY'
import json, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as handle:
    grant = json.load(handle)
grant["expires_at"] = "2020-01-01T00:00:00Z"
with open(path, "w", encoding="utf-8", newline="\n") as handle:
    json.dump(grant, handle, ensure_ascii=False, indent=4)
    handle.write("\n")
PY

mkdir -p "$web_root" "$session_root" "$responses"
cp -a "$repo_root/api" "$web_root/api"
cp "$repo_root/inspekcie.html" "$repo_root/inspekcia.html" "$web_root/"
mkdir -p "$web_root/data"

record_values="$(python - "$web_root/data/inspections.json" "$access_id" "$pin" "$priced_access_id" "$priced_pin" "$inactive_access_id" "$inactive_pin" "$expired_access_id" "$expired_pin" <<'PY'
import json, sys

path, access_id, pin, priced_access_id, priced_pin, inactive_access_id, inactive_pin, expired_access_id, expired_pin = sys.argv[1:]
used = {pin, priced_pin, inactive_pin, expired_pin}

def unique(start):
    value = start
    while f"{value:06d}" in used:
        value += 1
    result = f"{value:06d}"
    used.add(result)
    return result

legacy_pin = unique(110001)
mismatch_pin = unique(220001)
invalid_binding_pin = unique(330001)
wrong_pin = unique(990001)
base = {
    "title": "Synthetic inspection",
    "location": "Test region",
    "summary": "Synthetic bridge test record.",
    "clientEmail": "",
    "status": "ready",
    "media": {},
    "photos": [],
    "createdAt": "2026-08-13T00:00:00+00:00",
    "updatedAt": "2026-08-13T00:00:00+00:00",
}

def record(identifier, record_pin, diagnostics_access_id=None):
    item = dict(base, id=identifier, pin=record_pin)
    if diagnostics_access_id is not None:
        item["diagnosticsAccessId"] = diagnostics_access_id
    return item

items = [
    record("legacy", legacy_pin),
    record("linked", pin, access_id),
    record("mismatch", mismatch_pin, priced_access_id),
    record("inactive", inactive_pin, inactive_access_id),
    record("expired", expired_pin, expired_access_id),
    record("invalid-binding", invalid_binding_pin, "acc_not-valid"),
]
with open(path, "w", encoding="utf-8", newline="\n") as handle:
    json.dump(items, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
print(json.dumps({
    "legacy_pin": legacy_pin,
    "mismatch_pin": mismatch_pin,
    "invalid_binding_pin": invalid_binding_pin,
    "wrong_pin": wrong_pin,
}))
PY
)"
legacy_pin="$(json_field "$record_values" legacy_pin)"
mismatch_pin="$(json_field "$record_values" mismatch_pin)"
invalid_binding_pin="$(json_field "$record_values" invalid_binding_pin)"
wrong_pin="$(json_field "$record_values" wrong_pin)"

cd "$web_root"
php -d "session.save_path=$session_root" -S "127.0.0.1:$port" -t "$web_root" >"$server_log" 2>&1 &
server_pid=$!

ready=0
for _attempt in 1 2 3 4 5 6 7 8 9 10; do
  if curl -sS -o "$responses/ready.html" "$base_url/inspekcie.html"; then
    ready=1
    break
  fi
  sleep 0.2
done
[[ "$ready" == "1" ]] || fail "PHP built-in server did not start."

invalid_admin_payload="$(python - <<'PY'
import json
print(json.dumps({
    "action": "save",
    "adminPin": "bridge-admin-pin",
    "inspection": {"title": "Invalid binding", "diagnosticsAccessId": "invalid"},
}))
PY
)"
invalid_admin_code="$(curl -sS -H 'Content-Type: application/json' -d "$invalid_admin_payload" -o "$responses/admin-invalid.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$invalid_admin_code" == "422" ]] || fail "Invalid admin diagnosticsAccessId must be rejected."

valid_admin_payload="$(python - "$access_id" <<'PY'
import json, sys
print(json.dumps({
    "action": "save",
    "adminPin": "bridge-admin-pin",
    "inspection": {"title": "Valid binding", "diagnosticsAccessId": sys.argv[1]},
}))
PY
)"
valid_admin_code="$(curl -sS -H 'Content-Type: application/json' -d "$valid_admin_payload" -o "$responses/admin-valid.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$valid_admin_code" == "422" ]] || fail "Server-managed diagnosticsAccessId must not be writable through save."
python - "$web_root/api/inspections.php" <<'PY'
import re, sys
source = open(sys.argv[1], encoding="utf-8").read()
match = re.search(r"function public_item\(array \$item\): array\s*\{(.*?)\n\}", source, re.S)
assert match and "diagnosticsAccessId" not in match.group(1)
PY

legacy_payload="{\"action\":\"unlock\",\"pin\":\"$legacy_pin\"}"
legacy_code="$(curl -sS -H 'Content-Type: application/json' -d "$legacy_payload" -o "$responses/legacy.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$legacy_code" == "200" ]] || fail "Legacy inspection without diagnosticsAccessId must still unlock."
python - "$responses/legacy.json" <<'PY'
import json, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
assert body["ok"] is True
assert "inspection" in body and "mode" not in body
assert "diagnosticsAccessId" not in body["inspection"]
PY

wrong_payload="{\"action\":\"unlock\",\"pin\":\"$wrong_pin\"}"
wrong_code="$(curl -sS -H 'Content-Type: application/json' -d "$wrong_payload" -o "$responses/wrong.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$wrong_code" == "401" ]] || fail "Wrong PIN must fail closed."

invalid_binding_payload="{\"action\":\"unlock\",\"pin\":\"$invalid_binding_pin\"}"
invalid_binding_code="$(curl -sS -H 'Content-Type: application/json' -d "$invalid_binding_payload" -o "$responses/invalid-binding.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$invalid_binding_code" == "401" ]] || fail "Invalid diagnosticsAccessId must fail closed."
cmp -s "$responses/wrong.json" "$responses/invalid-binding.json" || fail "Wrong PIN and invalid binding must use the same generic body."

linked_payload="{\"action\":\"unlock\",\"pin\":\"$pin\"}"
linked_code="$(curl -sS -D "$responses/linked.headers" -c "$responses/cookies.txt" -H 'Content-Type: application/json' -d "$linked_payload" -o "$responses/linked.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$linked_code" == "200" ]] || fail "Matching linked PIN must establish a diagnostics session."
grep -qi '^Set-Cookie: DH_DIAGSESSID=.*HttpOnly' "$responses/linked.headers" || fail "Bridge session cookie must be HttpOnly."
grep -qi '^Set-Cookie: DH_DIAGSESSID=.*SameSite=Strict' "$responses/linked.headers" || fail "Bridge session cookie must be SameSite Strict."
python - "$responses/linked.json" "$access_id" "$pin" <<'PY'
import json, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
assert body == {
    "ok": True,
    "mode": "diagnostics",
    "redirectUrl": "/inspekcia.html?access=" + sys.argv[2],
}
assert sys.argv[3] not in body["redirectUrl"]
PY

status_code="$(curl -sS -b "$responses/cookies.txt" -o "$responses/status.json" -w '%{http_code}' "$base_url/api/diagnostics-auth.php")"
[[ "$status_code" == "200" ]] || fail "Diagnostics auth status must recognize the bridge session."
report_code="$(curl -sS -b "$responses/cookies.txt" -o "$responses/report.json" -w '%{http_code}' "$base_url/api/diagnostics-report.php")"
[[ "$report_code" == "200" ]] || fail "Report endpoint must be available after one PIN unlock."
csrf_token="$(python -c 'import json,sys; print(json.load(open(sys.argv[1]))["csrfToken"])' "$responses/status.json")"
logout_payload="{\"action\":\"logout\",\"csrfToken\":\"$csrf_token\"}"
logout_code="$(curl -sS -b "$responses/cookies.txt" -c "$responses/cookies.txt" -H 'Content-Type: application/json' -d "$logout_payload" -o "$responses/logout.json" -w '%{http_code}' "$base_url/api/diagnostics-auth.php")"
[[ "$logout_code" == "200" ]] || fail "Diagnostics logout after bridge unlock must succeed."
post_logout_code="$(curl -sS -b "$responses/cookies.txt" -o "$responses/post-logout.json" -w '%{http_code}' "$base_url/api/diagnostics-report.php")"
[[ "$post_logout_code" == "401" ]] || fail "Report must be unavailable after logout."

mismatch_payload="{\"action\":\"unlock\",\"pin\":\"$mismatch_pin\"}"
for attempt in 1 2 3; do
  mismatch_code="$(curl -sS -H 'Content-Type: application/json' -d "$mismatch_payload" -o "$responses/mismatch-$attempt.json" -w '%{http_code}' "$base_url/api/inspections.php")"
  [[ "$mismatch_code" == "401" ]] || fail "Legacy PIN that differs from the diagnostics PIN must fail."
  cmp -s "$responses/wrong.json" "$responses/mismatch-$attempt.json" || fail "PIN mismatch must use the generic auth body."
done
limited_code="$(curl -sS -D "$responses/limited.headers" -H 'Content-Type: application/json' -d "$mismatch_payload" -o "$responses/limited.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$limited_code" == "429" ]] || fail "Diagnostics rate limit must propagate through the bridge."
grep -qi '^Retry-After: [1-9][0-9]*' "$responses/limited.headers" || fail "Bridge 429 must include Retry-After."

inactive_payload="{\"action\":\"unlock\",\"pin\":\"$inactive_pin\"}"
inactive_code="$(curl -sS -H 'Content-Type: application/json' -d "$inactive_payload" -o "$responses/inactive.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$inactive_code" == "401" ]] || fail "Inactive diagnostics grant must fail."
cmp -s "$responses/wrong.json" "$responses/inactive.json" || fail "Inactive grant must use the generic auth body."

expired_payload="{\"action\":\"unlock\",\"pin\":\"$expired_pin\"}"
expired_code="$(curl -sS -H 'Content-Type: application/json' -d "$expired_payload" -o "$responses/expired.json" -w '%{http_code}' "$base_url/api/inspections.php")"
[[ "$expired_code" == "401" ]] || fail "Expired diagnostics grant must fail."
cmp -s "$responses/wrong.json" "$responses/expired.json" || fail "Expired grant must use the generic auth body."

grep -Fq 'window.location.assign(redirectUrl)' "$web_root/inspekcie.html" || fail "PIN form must redirect diagnostics responses."
grep -Fq '/^\/inspekcia\.html\?access=acc_[0-9a-f]{32}$/' "$web_root/inspekcie.html" || fail "Frontend must validate the diagnostics redirect allowlist."

for sensitive_pin in "$pin" "$priced_pin" "$inactive_pin" "$expired_pin" "$mismatch_pin" "$invalid_binding_pin" "$wrong_pin"; do
  if grep -R -Fq -- "$sensitive_pin" "$storage_root/audit" "$server_log"; then
    fail "A PIN appeared in an audit or server log."
  fi
done
if grep -E 'PHP (Warning|Notice|Fatal error)' "$server_log" >/dev/null; then
  fail "PHP server emitted a warning, notice or fatal error."
fi

echo "Inspection diagnostics bridge HTTP tests passed: legacy, binding, same PIN, session, report, logout, failures and leak guards."
