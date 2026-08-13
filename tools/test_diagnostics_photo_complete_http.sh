#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
web_root="$test_root/webroot"
storage_root="$test_root/storage"
session_root="$test_root/sessions"
cookie_jar="$test_root/cookies.txt"
server_log="$test_root/php-server.log"
port="$((20000 + RANDOM % 20000))"
base_url="http://127.0.0.1:$port"
server_pid=""

cleanup() {
  if [[ -n "$server_pid" ]]; then kill "$server_pid" >/dev/null 2>&1 || true; wait "$server_pid" >/dev/null 2>&1 || true; fi
  chmod -R u+w -- "$test_root" >/dev/null 2>&1 || true
  rm -rf -- "$test_root"
}
trap cleanup EXIT
fail() { echo "Photo-complete diagnostics HTTP test failed: $1" >&2; tail -n 100 "$server_log" 2>/dev/null || true; exit 1; }
json_field() { python -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$1" "$2"; }

mkdir -p "$web_root" "$session_root"
cp -a "$repo_root/api" "$web_root/api"
cp "$repo_root/inspekcie.html" "$repo_root/inspekcia.html" "$web_root/"
cp -a "$repo_root/JSS" "$repo_root/styles" "$web_root/"

export DIAGNOSTICS_STORAGE_ROOT="$storage_root"
export DIAGNOSTICS_PIN_PEPPER="photo-complete-pin-pepper-0123456789"
export DIAGNOSTICS_AUDIT_HMAC_KEY="photo-complete-audit-key-0123456789"
export DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST="1"
export INSPECTIONS_ADMIN_PIN="photo-complete-admin"
pin="482615"
fixture="$(php "$repo_root/tools/test_diagnostics_photo_complete_fixture.php" --prepare "$storage_root" "$web_root" "$pin")"
report_id="$(json_field "$fixture" report_id)"

cd "$web_root"
php -d "session.save_path=$session_root" -S "127.0.0.1:$port" -t "$web_root" >"$server_log" 2>&1 &
server_pid=$!
for _ in {1..20}; do curl -sS "$base_url/inspekcie.html" >/dev/null && break; sleep 0.2; done

activate="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"activate-diagnostics\",\"adminPin\":\"photo-complete-admin\",\"id\":\"photo-complete-inspection\",\"reportId\":\"$report_id\",\"version\":\"1.0\"}" "$base_url/api/inspections.php")"
[[ "$(json_field "$activate" ok)" == "True" ]] || fail "activate-diagnostics failed"
access_id="$(json_field "$activate" accessId)"
[[ "$activate" != *'"pin"'* && "$activate" != *"$pin"* ]] || fail "activation response leaked the PIN"

unlock="$(curl -sS -c "$cookie_jar" -b "$cookie_jar" -H 'Content-Type: application/json' -d "{\"action\":\"unlock\",\"pin\":\"$pin\"}" "$base_url/api/inspections.php")"
[[ "$(json_field "$unlock" mode)" == "diagnostics" ]] || fail "one-PIN bridge did not return diagnostics mode"
[[ "$(json_field "$unlock" redirectUrl)" == "/inspekcia.html?access=$access_id" ]] || fail "one-PIN redirect is invalid"

auth="$(curl -sS -c "$cookie_jar" -b "$cookie_jar" "$base_url/api/diagnostics-auth.php?access=$access_id")"
[[ "$(json_field "$auth" authenticated)" == "True" ]] || fail "diagnostics session is not authenticated"
csrf="$(json_field "$auth" csrfToken)"
report_file="$test_root/report.json"
appendix_file="$test_root/appendix.json"
curl -sS -b "$cookie_jar" -o "$report_file" "$base_url/api/diagnostics-report.php"
curl -sS -b "$cookie_jar" -o "$appendix_file" "$base_url/api/diagnostics-appendix.php"
python - "$report_file" "$appendix_file" "$base_url" "$cookie_jar" <<'PY'
import json, subprocess, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    report = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    appendix = json.load(handle)
base_url, cookie = sys.argv[3], sys.argv[4]
linked = [e for issue in report["issues"] for e in issue["evidence"] if e.get("has_media")]
assert len(linked) == 53
assert appendix.get("photo_count") == len(appendix.get("items", [])) == 18, appendix
assert "media_reference" not in json.dumps(appendix)
items = linked + appendix["items"]
assert len({x.get("id", x.get("evidence_id")) for x in items}) == 71
for item in items:
    evidence_id = item.get("id", item.get("evidence_id"))
    result = subprocess.run(["curl", "-sS", "-I", "-b", cookie, f"{base_url}/api/diagnostics-media.php?evidence={evidence_id}"], capture_output=True, text=True)
    assert result.returncode == 0 and (" 200 " in result.stdout or " 206 " in result.stdout), evidence_id
PY

wrong_status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d '{"action":"unlock","pin":"000000"}' "$base_url/api/inspections.php")"
[[ "$wrong_status" == "401" ]] || fail "wrong PIN did not fail closed"
logout_status="$(curl -sS -o /dev/null -w '%{http_code}' -c "$cookie_jar" -b "$cookie_jar" -H 'Content-Type: application/json' -d "{\"action\":\"logout\",\"csrfToken\":\"$csrf\"}" "$base_url/api/diagnostics-auth.php")"
[[ "$logout_status" == "200" ]] || fail "logout failed"
post_logout="$(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" "$base_url/api/diagnostics-report.php")"
[[ "$post_logout" == "401" ]] || fail "logout did not invalidate the session"
! grep -R -F -q "$pin" "$storage_root/audit" || fail "audit log leaked the PIN"
! grep -q "$pin" "$server_log" || fail "server log leaked the PIN"
! grep -Eqi 'warning|fatal|uncaught|notice' "$server_log" || fail "PHP emitted a runtime warning"

echo "Photo-complete diagnostics HTTP test: PASS (53 linked + 18 appendix = 71 authorized images)"
