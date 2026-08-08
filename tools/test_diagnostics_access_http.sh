#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
storage_root="$test_root/storage"
session_root="$test_root/sessions"
server_log="$test_root/php-server.log"
server_pid=""
port="$((20000 + RANDOM % 20000))"
base_url="http://127.0.0.1:$port/api/diagnostics-auth.php"

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
  echo "Diagnostics access HTTP test failed: $1" >&2
  if [[ -f "$server_log" ]]; then
    tail -n 80 "$server_log" >&2 || true
  fi
  exit 1
}

export DIAGNOSTICS_STORAGE_ROOT="$storage_root"
export DIAGNOSTICS_PIN_PEPPER="http-test-pin-pepper-0123456789-abcdef"
export DIAGNOSTICS_AUDIT_HMAC_KEY="http-test-audit-key-0123456789-abcdef"
export DIAGNOSTICS_RATE_ACCESS_IP_MAX="4"
export DIAGNOSTICS_RATE_IP_MAX="30"
export DIAGNOSTICS_RATE_WINDOW_SECONDS="300"
export DIAGNOSTICS_RATE_LOCKOUT_SECONDS="120"
export DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST="1"

fixture="$(php "$repo_root/tools/test_diagnostics_access.php" --prepare-http "$storage_root")"
access_id="$(python -c 'import json,sys; print(json.loads(sys.argv[1])["access_id"])' "$fixture")"
pin="$(python -c 'import json,sys; print(json.loads(sys.argv[1])["pin"])' "$fixture")"

cd "$repo_root"
mkdir -p "$session_root"
php -d "session.save_path=$session_root" -S "127.0.0.1:$port" -t "$repo_root" >"$server_log" 2>&1 &
server_pid=$!

ready=0
for _attempt in 1 2 3 4 5 6 7 8 9 10; do
  if curl -sS -o "$test_root/ready.json" "$base_url"; then
    ready=1
    break
  fi
  sleep 0.2
done
[[ "$ready" == "1" ]] || fail "PHP built-in server did not start."

status_code="$(curl -sS -o "$test_root/no-cookie.json" -w '%{http_code}' "$base_url")"
[[ "$status_code" == "401" ]] || fail "A: status without a cookie must return 401."

wrong_payload="{\"action\":\"unlock\",\"accessId\":\"$access_id\",\"pin\":\"000000\"}"
unknown_id="acc_ffffffffffffffffffffffffffffffff"
unknown_payload="{\"action\":\"unlock\",\"accessId\":\"$unknown_id\",\"pin\":\"000000\"}"
wrong_code="$(curl -sS -H 'Content-Type: application/json' -d "$wrong_payload" -o "$test_root/wrong.json" -w '%{http_code}' "$base_url")"
unknown_code="$(curl -sS -H 'Content-Type: application/json' -d "$unknown_payload" -o "$test_root/unknown.json" -w '%{http_code}' "$base_url")"
[[ "$wrong_code" == "401" && "$unknown_code" == "401" ]] || fail "B: wrong and unknown credentials must both return 401."
cmp -s "$test_root/wrong.json" "$test_root/unknown.json" || fail "C: wrong and unknown credentials must have the same body."

valid_payload="{\"action\":\"unlock\",\"accessId\":\"$access_id\",\"pin\":\"$pin\"}"
valid_code="$(curl -sS -D "$test_root/unlock.headers" -c "$test_root/cookies.txt" -H 'Content-Type: application/json' -d "$valid_payload" -o "$test_root/unlock.json" -w '%{http_code}' "$base_url")"
[[ "$valid_code" == "200" ]] || fail "D: valid credentials must return 200."
grep -qi '^Set-Cookie: DH_DIAGSESSID=.*HttpOnly' "$test_root/unlock.headers" || fail "E: session cookie must be HttpOnly."
grep -qi '^Set-Cookie: DH_DIAGSESSID=.*SameSite=Strict' "$test_root/unlock.headers" || fail "F: session cookie must be SameSite Strict."
grep -qi '^Cache-Control: no-store, private' "$test_root/unlock.headers" || fail "G: response must be no-store and private."
grep -qi '^X-Content-Type-Options: nosniff' "$test_root/unlock.headers" || fail "G: response must include nosniff."

python - "$test_root/unlock.json" "$access_id" <<'PY'
import json, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
assert body == {
    "ok": True,
    "authenticated": True,
    "accessId": sys.argv[2],
    "version": "1.0",
    "csrfToken": body["csrfToken"],
}
assert len(body["csrfToken"]) == 64
for forbidden in ("reportId", "report_id", "manifest", "pin", "session", "DH_DIAGSESSID"):
    assert forbidden not in body
PY

cookie_value="$(awk '$6 == "DH_DIAGSESSID" {print $7}' "$test_root/cookies.txt")"
[[ -n "$cookie_value" ]] || fail "H: session cookie value was not captured."
grep -Fq "$cookie_value" "$test_root/unlock.json" && fail "H: response body exposed the session identifier."
grep -Eq 'PHPSESSID|DH_DIAGSESSID' "$test_root/unlock.json" && fail "H: response body exposed a session name."

status_code="$(curl -sS -b "$test_root/cookies.txt" -o "$test_root/status.json" -w '%{http_code}' "$base_url")"
[[ "$status_code" == "200" ]] || fail "I: status with a valid cookie must return 200."

csrf_token="$(python -c 'import json,sys; print(json.load(open(sys.argv[1]))["csrfToken"])' "$test_root/status.json")"
bad_logout='{"action":"logout","csrfToken":"0000000000000000000000000000000000000000000000000000000000000000"}'
bad_logout_code="$(curl -sS -b "$test_root/cookies.txt" -H 'Content-Type: application/json' -d "$bad_logout" -o "$test_root/bad-logout.json" -w '%{http_code}' "$base_url")"
[[ "$bad_logout_code" == "403" ]] || fail "J: logout with wrong CSRF must return 403."
good_logout="{\"action\":\"logout\",\"csrfToken\":\"$csrf_token\"}"
good_logout_code="$(curl -sS -b "$test_root/cookies.txt" -c "$test_root/cookies.txt" -H 'Content-Type: application/json' -d "$good_logout" -o "$test_root/good-logout.json" -w '%{http_code}' "$base_url")"
[[ "$good_logout_code" == "200" ]] || fail "K: logout with valid CSRF must return 200."
post_logout_code="$(curl -sS -b "$test_root/cookies.txt" -o "$test_root/post-logout.json" -w '%{http_code}' "$base_url")"
[[ "$post_logout_code" == "401" ]] || fail "K: status after logout must return 401."

curl -sS -c "$test_root/rotate-cookies.txt" -H 'Content-Type: application/json' -d "$valid_payload" -o "$test_root/reunlock.json" "$base_url"
rotated="$(php "$repo_root/tools/test_diagnostics_access.php" --rotate-http "$access_id")"
rotated_pin="$(python -c 'import json,sys; print(json.loads(sys.argv[1])["pin"])' "$rotated")"
rotated_status="$(curl -sS -b "$test_root/rotate-cookies.txt" -o "$test_root/rotated-status.json" -w '%{http_code}' "$base_url")"
[[ "$rotated_status" == "401" ]] || fail "L: PIN rotation must invalidate an existing session."

rotated_payload="{\"action\":\"unlock\",\"accessId\":\"$access_id\",\"pin\":\"$rotated_pin\"}"
curl -sS -c "$test_root/revoke-cookies.txt" -H 'Content-Type: application/json' -d "$rotated_payload" -o "$test_root/rotated-unlock.json" "$base_url"
php "$repo_root/tools/test_diagnostics_access.php" --revoke-http "$access_id" >/dev/null
revoked_status="$(curl -sS -b "$test_root/revoke-cookies.txt" -o "$test_root/revoked-status.json" -w '%{http_code}' "$base_url")"
[[ "$revoked_status" == "401" ]] || fail "M: revocation must invalidate an existing session."

for _attempt in 2 3 4; do
  code="$(curl -sS -H 'Content-Type: application/json' -d "$unknown_payload" -o "$test_root/rate-$_attempt.json" -w '%{http_code}' "$base_url")"
  [[ "$code" == "401" ]] || fail "N: attempts below the threshold must return 401."
done
rate_code="$(curl -sS -D "$test_root/rate.headers" -H 'Content-Type: application/json' -d "$unknown_payload" -o "$test_root/rate.json" -w '%{http_code}' "$base_url")"
[[ "$rate_code" == "429" ]] || fail "N: limited authentication must return 429."
grep -qi '^Retry-After: [1-9][0-9]*' "$test_root/rate.headers" || fail "N: 429 must include Retry-After."

malformed_code="$(curl -sS -H 'Content-Type: application/json' -d '{' -o "$test_root/malformed.json" -w '%{http_code}' "$base_url")"
[[ "$malformed_code" == "400" ]] || fail "O: malformed JSON must return 400."
method_code="$(curl -sS -X PUT -o "$test_root/method.json" -w '%{http_code}' "$base_url")"
[[ "$method_code" == "405" ]] || fail "O: unsupported method must return 405."

echo "Diagnostics access HTTP tests passed: scenarios A-O."
