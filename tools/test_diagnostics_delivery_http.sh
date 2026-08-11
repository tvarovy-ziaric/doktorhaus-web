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
auth_url="http://127.0.0.1:$port/api/diagnostics-auth.php"
report_url="http://127.0.0.1:$port/api/diagnostics-report.php"
media_url="http://127.0.0.1:$port/api/diagnostics-media.php"

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
  echo "Diagnostics delivery HTTP test failed: $1" >&2
  if [[ -f "$server_log" ]]; then
    tail -n 100 "$server_log" >&2 || true
  fi
  exit 1
}

json_field() {
  python -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$1" "$2"
}

export DIAGNOSTICS_STORAGE_ROOT="$storage_root"
export DIAGNOSTICS_PIN_PEPPER="delivery-test-pin-pepper-0123456789-abcdef"
export DIAGNOSTICS_AUDIT_HMAC_KEY="delivery-test-audit-key-0123456789-abcdef"
export DIAGNOSTICS_RATE_ACCESS_IP_MAX="6"
export DIAGNOSTICS_RATE_IP_MAX="40"
export DIAGNOSTICS_RATE_WINDOW_SECONDS="300"
export DIAGNOSTICS_RATE_LOCKOUT_SECONDS="120"
export DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST="1"

fixture="$(php "$repo_root/tools/test_diagnostics_delivery_fixture.php" --prepare "$storage_root")"
access_id="$(json_field "$fixture" access_id)"
pin="$(json_field "$fixture" pin)"
report_id="$(json_field "$fixture" report_id)"
priced_access_id="$(json_field "$fixture" priced_access_id)"
priced_pin="$(json_field "$fixture" priced_pin)"
photo_id="$(json_field "$fixture" photo_evidence_id)"
pdf_id="$(json_field "$fixture" pdf_evidence_id)"
unsafe_id="$(json_field "$fixture" unsafe_evidence_id)"
internal_id="$(json_field "$fixture" internal_evidence_id)"
orphan_id="$(json_field "$fixture" orphan_evidence_id)"
large_id="$(json_field "$fixture" large_evidence_id)"
source_report_id="$(json_field "$fixture" source_report_evidence_id)"
python -c 'import json,sys; assert json.loads(sys.argv[1])["mismatched_pricing_blocked"] is True' "$fixture" || fail "Mismatched report-pricing ownership must fail before delivery."

mkdir -p "$session_root" "$responses"
cd "$repo_root"
php -d "session.save_path=$session_root" -S "127.0.0.1:$port" -t "$repo_root" >"$server_log" 2>&1 &
server_pid=$!

ready=0
for _attempt in 1 2 3 4 5 6 7 8 9 10; do
  if curl -sS -o "$responses/ready.json" "$report_url"; then
    ready=1
    break
  fi
  sleep 0.2
done
[[ "$ready" == "1" ]] || fail "PHP built-in server did not start."

report_no_session="$(curl -sS -o "$responses/report-no-session.json" -w '%{http_code}' "$report_url")"
[[ "$report_no_session" == "401" ]] || fail "Report without session must return 401."
media_no_session="$(curl -sS -o "$responses/media-no-session.json" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$media_no_session" == "401" ]] || fail "Media without session must return 401."

unlock_payload="{\"action\":\"unlock\",\"accessId\":\"$access_id\",\"pin\":\"$pin\"}"
unlock_code="$(curl -sS -c "$responses/cookies.txt" -H 'Content-Type: application/json' -d "$unlock_payload" -o "$responses/unlock.json" -w '%{http_code}' "$auth_url")"
[[ "$unlock_code" == "200" ]] || fail "Valid delivery grant must unlock."

report_code="$(curl -sS -D "$responses/report.headers" -b "$responses/cookies.txt" -o "$responses/report.json" -w '%{http_code}' "$report_url")"
[[ "$report_code" == "200" ]] || fail "Valid session report must return 200."
grep -qi '^Content-Type: application/json; charset=utf-8' "$responses/report.headers" || fail "Report Content-Type is invalid."
grep -qi '^Cache-Control: no-store, private' "$responses/report.headers" || fail "Report must be no-store and private."
grep -qi '^Vary: Cookie' "$responses/report.headers" || fail "Report must vary on Cookie."
grep -qi '^X-Content-Type-Options: nosniff' "$responses/report.headers" || fail "Report must send nosniff."
if grep -qi '^Access-Control-Allow-Origin:' "$responses/report.headers"; then
  fail "Report must not emit wildcard or other CORS headers."
fi

python - "$responses/report.json" "$report_id" "$photo_id" "$internal_id" "$orphan_id" <<'PY'
import json, re, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
assert body["document_type"] == "client_report"
assert body["schema_version"] == "1.0.0"
assert body["report"]["version"] == "1.0"
assert body["overview"]["issue_count"] == 1
assert "pricing" not in body
forbidden = {
    "qa", "actors", "actor_ids", "approved_by", "observed_by", "captured_by", "performed_by",
    "provenance", "import_metadata", "source_system", "source_inspection_id", "source_item_id",
    "source_media_id", "source_reference", "source_hash", "pin", "pin_hash", "csrf_token",
    "session_id", "report_id", "report_version_id", "package_manifest_sha256", "media_reference",
    "sha256", "address_private", "storage_path", "filesystem_path",
}
def walk(value):
    if isinstance(value, dict):
        assert not (set(value) & forbidden), set(value) & forbidden
        for child in value.values(): walk(child)
    elif isinstance(value, list):
        for child in value: walk(child)
walk(body)
encoded = json.dumps(body, ensure_ascii=False)
assert sys.argv[2] not in encoded
assert sys.argv[4] not in encoded
assert sys.argv[5] not in encoded
evidence = {item["id"]: item for issue in body["issues"] for item in issue["evidence"]}
assert sys.argv[3] in evidence
assert re.fullmatch(r"api/diagnostics-media\.php\?evidence=ev_[0-9a-f]{16,32}", evidence[sys.argv[3]]["media_url"])
assert "media/" not in evidence[sys.argv[3]]["media_url"]
assert len(body["issues"][0]["impacts"]) == 7
PY

for query in "reportId=$report_id" "version=9.9" "path=inspection.json" "pricing=report-pricing.json"; do
  code="$(curl -sS -b "$responses/cookies.txt" -o "$responses/report-invalid-${query%%=*}.json" -w '%{http_code}' "$report_url?$query")"
  [[ "$code" == "400" ]] || fail "Report selector query $query must return 400."
done
report_method="$(curl -sS -X POST -b "$responses/cookies.txt" -o "$responses/report-method.json" -w '%{http_code}' "$report_url")"
[[ "$report_method" == "405" ]] || fail "Report POST must return 405."

priced_unlock_payload="{\"action\":\"unlock\",\"accessId\":\"$priced_access_id\",\"pin\":\"$priced_pin\"}"
priced_unlock_code="$(curl -sS -c "$responses/priced-cookies.txt" -H 'Content-Type: application/json' -d "$priced_unlock_payload" -o "$responses/priced-unlock.json" -w '%{http_code}' "$auth_url")"
[[ "$priced_unlock_code" == "200" ]] || fail "Valid priced delivery grant must unlock."
priced_report_code="$(curl -sS -b "$responses/priced-cookies.txt" -o "$responses/priced-report.json" -w '%{http_code}' "$report_url")"
[[ "$priced_report_code" == "200" ]] || fail "Session-bound priced report must return 200."
python - "$responses/priced-report.json" <<'PY'
import json, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
pricing = body["pricing"]
assert len(pricing["components"]) == 1
assert pricing["components"][0]["title"] == "Syntetické odborné overenie"
assert pricing["aggregation"]["status"] == "subtotal"
encoded = json.dumps(pricing, ensure_ascii=False)
for forbidden in ("provenance", "source_method", "source_ids", "snapshot_references", "client_visible"):
    assert forbidden not in encoded
PY

photo_code="$(curl -sS -D "$responses/photo.headers" -b "$responses/cookies.txt" -o "$responses/photo.bin" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$photo_code" == "200" ]] || fail "Visible photo must return 200."
grep -qi '^Content-Type: image/jpeg' "$responses/photo.headers" || fail "Visible photo MIME is invalid."
grep -qi '^Content-Disposition: inline; filename="doktorhaus-'"$photo_id"'\.jpg"' "$responses/photo.headers" || fail "Visible photo filename/disposition is invalid."
grep -qi '^Accept-Ranges: bytes' "$responses/photo.headers" || fail "Media must advertise byte ranges."
grep -qi '^Cache-Control: no-store, private' "$responses/photo.headers" || fail "Media must be no-store and private."
photo_size="$(wc -c < "$responses/photo.bin" | tr -d ' ')"
grep -qi "^Content-Length: $photo_size" "$responses/photo.headers" || fail "Full media Content-Length is invalid."

python - "$port" "$responses/cookies.txt" "$photo_id" <<'PY'
import http.client, sys
cookie = None
for line in open(sys.argv[2], encoding="utf-8"):
    if line.startswith("#HttpOnly_"):
        line = line[len("#HttpOnly_"):]
    elif line.startswith("#"):
        continue
    fields = line.rstrip("\n").split("\t")
    if len(fields) >= 7 and fields[5] == "DH_DIAGSESSID": cookie = fields[6]
assert cookie
connection = http.client.HTTPConnection("127.0.0.1", int(sys.argv[1]), timeout=10)
connection.request("HEAD", "/api/diagnostics-media.php?evidence=" + sys.argv[3], headers={"Cookie": "DH_DIAGSESSID=" + cookie})
response = connection.getresponse()
body = response.read()
assert response.status == 200, response.status
assert body == b"", body
assert response.getheader("Content-Length") is not None
assert response.getheader("Accept-Ranges") == "bytes"
connection.close()
PY

bounded_code="$(curl -sS -D "$responses/range-bounded.headers" -b "$responses/cookies.txt" -H 'Range: bytes=0-9' -o "$responses/range-bounded.bin" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$bounded_code" == "206" && "$(wc -c < "$responses/range-bounded.bin" | tr -d ' ')" == "10" ]] || fail "Bounded range must return ten bytes with 206."
grep -qi "^Content-Range: bytes 0-9/$photo_size" "$responses/range-bounded.headers" || fail "Bounded Content-Range is invalid."
grep -qi '^Content-Length: 10' "$responses/range-bounded.headers" || fail "Bounded Content-Length is invalid."

open_start=5
open_length="$((photo_size - open_start))"
open_code="$(curl -sS -D "$responses/range-open.headers" -b "$responses/cookies.txt" -H "Range: bytes=$open_start-" -o "$responses/range-open.bin" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$open_code" == "206" && "$(wc -c < "$responses/range-open.bin" | tr -d ' ')" == "$open_length" ]] || fail "Open-ended range is invalid."
grep -qi "^Content-Range: bytes $open_start-$((photo_size - 1))/$photo_size" "$responses/range-open.headers" || fail "Open-ended Content-Range is invalid."

suffix_code="$(curl -sS -D "$responses/range-suffix.headers" -b "$responses/cookies.txt" -H 'Range: bytes=-5' -o "$responses/range-suffix.bin" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$suffix_code" == "206" && "$(wc -c < "$responses/range-suffix.bin" | tr -d ' ')" == "5" ]] || fail "Suffix range is invalid."
grep -qi "^Content-Range: bytes $((photo_size - 5))-$((photo_size - 1))/$photo_size" "$responses/range-suffix.headers" || fail "Suffix Content-Range is invalid."

multiple_code="$(curl -sS -D "$responses/range-multiple.headers" -b "$responses/cookies.txt" -H 'Range: bytes=0-1,3-4' -o "$responses/range-multiple.json" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$multiple_code" == "416" ]] || fail "Multiple ranges must return 416."
grep -qi "^Content-Range: bytes \*/$photo_size" "$responses/range-multiple.headers" || fail "Multiple range 416 must expose only total size."
unsatisfiable_code="$(curl -sS -D "$responses/range-unsat.headers" -b "$responses/cookies.txt" -H 'Range: bytes=999999-' -o "$responses/range-unsat.json" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$unsatisfiable_code" == "416" ]] || fail "Unsatisfiable range must return 416."

for pair in "$internal_id:internal" "$orphan_id:orphan" "$source_report_id:source-report-role" "ev_ffffffffffffffff:unknown"; do
  evidence_id="${pair%%:*}"
  label="${pair##*:}"
  code="$(curl -sS -b "$responses/cookies.txt" -o "$responses/media-$label.json" -w '%{http_code}' "$media_url?evidence=$evidence_id")"
  [[ "$code" == "404" ]] || fail "$label evidence must return generic 404."
  grep -Fq 'Media not found.' "$responses/media-$label.json" || fail "$label evidence must use generic 404 body."
done

invalid_evidence="$(curl -sS -b "$responses/cookies.txt" -o "$responses/media-invalid.json" -w '%{http_code}' "$media_url?evidence=bad")"
[[ "$invalid_evidence" == "400" ]] || fail "Invalid evidence syntax must return 400."
path_query="$(curl -sS -b "$responses/cookies.txt" -o "$responses/media-path.json" -w '%{http_code}' "$media_url?path=media/photo.jpg")"
[[ "$path_query" == "400" ]] || fail "Media path selector must return 400."
report_query="$(curl -sS -b "$responses/cookies.txt" -o "$responses/media-report.json" -w '%{http_code}' "$media_url?report=$report_id")"
[[ "$report_query" == "400" ]] || fail "Media report selector must return 400."
media_method="$(curl -sS -X POST -b "$responses/cookies.txt" -o "$responses/media-method.json" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$media_method" == "405" ]] || fail "Media POST must return 405."

pdf_code="$(curl -sS -D "$responses/pdf.headers" -b "$responses/cookies.txt" -o "$responses/pdf.bin" -w '%{http_code}' "$media_url?evidence=$pdf_id")"
[[ "$pdf_code" == "200" ]] || fail "Visible PDF must return 200."
grep -qi '^Content-Type: application/pdf' "$responses/pdf.headers" || fail "PDF must preserve safe MIME."
grep -qi '^Content-Disposition: inline; filename="doktorhaus-'"$pdf_id"'\.pdf"' "$responses/pdf.headers" || fail "PDF must be inline with generated filename."

unsafe_code="$(curl -sS -D "$responses/unsafe.headers" -b "$responses/cookies.txt" -o "$responses/unsafe.bin" -w '%{http_code}' "$media_url?evidence=$unsafe_id")"
[[ "$unsafe_code" == "200" ]] || fail "Visible unsafe-type attachment must still deliver."
grep -qi '^Content-Type: application/octet-stream' "$responses/unsafe.headers" || fail "Unsafe MIME must be forced to octet-stream."
grep -qi '^Content-Disposition: attachment; filename="doktorhaus-'"$unsafe_id"'\.bin"' "$responses/unsafe.headers" || fail "Unsafe MIME must be an attachment with generated filename."

large_path="$storage_root/reports/$report_id/1.0/media/large.mp4"
[[ "$(wc -c < "$large_path" | tr -d ' ')" == "6291456" ]] || fail "Large synthetic fixture must be 6 MiB."
large_code="$(curl -sS -D "$responses/large-range.headers" -b "$responses/cookies.txt" -H 'Range: bytes=1048576-1049599' -o "$responses/large-range.bin" -w '%{http_code}' "$media_url?evidence=$large_id")"
[[ "$large_code" == "206" && "$(wc -c < "$responses/large-range.bin" | tr -d ' ')" == "1024" ]] || fail "Large synthetic media must stream a scoped range."
grep -qi '^Content-Length: 1024' "$responses/large-range.headers" || fail "Large range Content-Length is invalid."

rotated="$(php "$repo_root/tools/test_diagnostics_delivery_fixture.php" --rotate "$access_id")"
rotated_pin="$(json_field "$rotated" pin)"
rotated_report="$(curl -sS -b "$responses/cookies.txt" -o "$responses/report-rotated.json" -w '%{http_code}' "$report_url")"
[[ "$rotated_report" == "401" ]] || fail "PIN rotation must invalidate report session."

rotated_payload="{\"action\":\"unlock\",\"accessId\":\"$access_id\",\"pin\":\"$rotated_pin\"}"
curl -sS -c "$responses/revoke-cookies.txt" -H 'Content-Type: application/json' -d "$rotated_payload" -o "$responses/rotated-unlock.json" "$auth_url"
php "$repo_root/tools/test_diagnostics_delivery_fixture.php" --revoke "$access_id" >/dev/null
revoked_report="$(curl -sS -b "$responses/revoke-cookies.txt" -o "$responses/report-revoked.json" -w '%{http_code}' "$report_url")"
[[ "$revoked_report" == "401" ]] || fail "Revocation must invalidate report session."
revoked_media="$(curl -sS -b "$responses/revoke-cookies.txt" -o "$responses/media-revoked.json" -w '%{http_code}' "$media_url?evidence=$photo_id")"
[[ "$revoked_media" == "401" ]] || fail "Revocation must invalidate media session."

cookie_value="$(awk '$6 == "DH_DIAGSESSID" {print $7}' "$responses/cookies.txt")"
python - "$storage_root/audit" "$storage_root" "$pin" "$cookie_value" <<'PY'
import json, pathlib, sys
audit_root = pathlib.Path(sys.argv[1])
raw = "".join(path.read_text(encoding="utf-8") for path in sorted(audit_root.glob("*.jsonl")))
events = [json.loads(line) for line in raw.splitlines() if line]
report_views = [item for item in events if item.get("event") == "report_viewed" and item.get("outcome") == "success"]
assert len(report_views) == 2, len(report_views)
assert any(item.get("event") == "media_accessed" and item.get("metadata", {}).get("request_type") == "full" for item in events)
assert any(item.get("event") == "media_accessed" and item.get("metadata", {}).get("request_type") == "range" for item in events)
assert any(item.get("event") == "media_accessed" and item.get("metadata", {}).get("request_type") == "head" for item in events)
for forbidden in (sys.argv[2], "/media/", "media_reference", sys.argv[3], "127.0.0.1", sys.argv[4], "pin_hash", "session_id"):
    if forbidden:
        assert forbidden not in raw, forbidden
PY

if grep -R -E 'PHP (Warning|Notice|Fatal error)' "$responses" --exclude='*.bin' --exclude='cookies.txt' >/dev/null; then
  fail "A response body contains a PHP warning or fatal error."
fi
if grep -F "$storage_root" "$responses"/*.headers >/dev/null; then
  fail "Response headers exposed a filesystem path."
fi
if grep -E 'PHP (Warning|Notice|Fatal error)' "$server_log" >/dev/null; then
  fail "PHP server emitted a warning, notice or fatal error."
fi

echo "Diagnostics delivery HTTP tests passed: report auth/projection, media visibility, MIME, ranges, large file and audit privacy."
