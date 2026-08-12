#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
storage_root="$test_root/private"
fixture="$test_root/preview.zip"
cookie_jar="$test_root/cookies.txt"
server_log="$test_root/server.log"
port="${DH_PREVIEW_TEST_PORT:-18767}"
base="http://127.0.0.1:$port/diagnostika-preview"

cleanup() {
  if [ -n "${server_pid:-}" ]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf "$test_root"
}
trap cleanup EXIT

mkdir -p "$storage_root"
php "$repo_root/tools/test_diagnostics_preview.php" --write-fixture "$fixture" >/dev/null

INSPECTIONS_ADMIN_PIN='test-owner-pin' \
DIAGNOSTICS_STORAGE_ROOT="$storage_root" \
PREVIEW_ALLOW_INSECURE_LOCAL_TEST=1 \
php -S "127.0.0.1:$port" -t "$repo_root" >"$server_log" 2>&1 &
server_pid=$!

for _ in $(seq 1 50); do
  if curl --silent --fail "$base/" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done
curl --silent --fail "$base/" >/dev/null

unauth_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
  "$base/api/report.php?preview=pvw_00000000000000000000000000000000")"
test "$unauth_status" = "401"

login_headers="$test_root/login-headers.txt"
login_body="$test_root/login.json"
curl --silent --show-error --fail \
  --dump-header "$login_headers" --cookie-jar "$cookie_jar" \
  --header "Origin: http://127.0.0.1:$port" \
  --header 'Content-Type: application/json' \
  --data '{"action":"login","pin":"test-owner-pin"}' \
  "$base/api/auth.php" >"$login_body"
grep -Eiq '^set-cookie:.*HttpOnly' "$login_headers"
grep -Eiq '^set-cookie:.*SameSite=Strict' "$login_headers"
csrf="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1], encoding="utf-8"))["csrfToken"])' "$login_body")"
test -n "$csrf"

upload_body="$test_root/upload.json"
curl --silent --show-error --fail \
  --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
  --header "Origin: http://127.0.0.1:$port" \
  --form-string "csrfToken=$csrf" \
  --form "bundle=@$fixture;type=application/zip" \
  "$base/api/upload.php" >"$upload_body"
preview_id="$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1], encoding="utf-8")); assert d["counts"] == {"media":71,"linked_evidence":53,"source_documentation_appendix":18,"videos_pending":5}; print(d["previewId"])' "$upload_body")"
[[ "$preview_id" =~ ^pvw_[0-9a-f]{32}$ ]]

report_body="$test_root/report.json"
appendix_body="$test_root/appendix.json"
curl --silent --show-error --fail --cookie "$cookie_jar" \
  "$base/api/report.php?preview=$preview_id" >"$report_body"
curl --silent --show-error --fail --cookie "$cookie_jar" \
  "$base/api/appendix.php?preview=$preview_id" >"$appendix_body"

python3 - "$report_body" "$appendix_body" <<'PY'
import json, re, sys
report = json.load(open(sys.argv[1], encoding="utf-8"))
appendix = json.load(open(sys.argv[2], encoding="utf-8"))
urls = set()
def walk(value):
    if isinstance(value, dict):
        if value.get("has_media") is True:
            urls.add(value["media_url"])
        for child in value.values():
            walk(child)
    elif isinstance(value, list):
        for child in value:
            walk(child)
walk(report)
assert len(urls) == 53
assert appendix["photo_count"] == 18 and len(appendix["items"]) == 18
assert all(re.fullmatch(r"api/diagnostics-media\.php\?evidence=ev_[0-9a-f]{16,32}", item["media_url"]) for item in appendix["items"])
PY

for index in $(seq 1 71); do
  evidence="$(printf 'ev_%016x' "$index")"
  curl --silent --show-error --fail --cookie "$cookie_jar" \
    --output /dev/null "$base/api/diagnostics-media.php?preview=$preview_id&evidence=$evidence"
done

curl --silent --show-error --fail \
  --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
  --header "Origin: http://127.0.0.1:$port" \
  --header 'Content-Type: application/json' \
  --data "{\"action\":\"logout\",\"csrfToken\":\"$csrf\"}" \
  "$base/api/auth.php" >/dev/null

after_logout_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookie_jar" "$base/api/report.php?preview=$preview_id")"
test "$after_logout_status" = "401"

echo "Protected diagnostics preview HTTP tests: PASS"
