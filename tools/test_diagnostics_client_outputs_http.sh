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
fail() { echo "Diagnostics client output HTTP test failed: $1" >&2; tail -n 120 "$server_log" 2>/dev/null || true; exit 1; }
json_value() { python -c 'import json,sys; value=json.loads(sys.argv[1]);
for key in sys.argv[2].split("."): value=value[int(key)] if key.isdigit() else value[key]
print(value)' "$1" "$2"; }
package_hash() { python - "$1" <<'PY'
import hashlib, pathlib, sys
root = pathlib.Path(sys.argv[1])
digest = hashlib.sha256()
for path in sorted(p for p in root.rglob("*") if p.is_file()):
    digest.update(path.relative_to(root).as_posix().encode())
    digest.update(path.read_bytes())
print(digest.hexdigest())
PY
}

mkdir -p "$web_root" "$session_root"
cp -a "$repo_root/api" "$web_root/api"
cp "$repo_root/inspekcie.html" "$repo_root/inspekcia.html" "$repo_root/inspekcie-admin.html" "$web_root/"
cp -a "$repo_root/JSS" "$repo_root/styles" "$web_root/"

export DIAGNOSTICS_STORAGE_ROOT="$storage_root"
export DIAGNOSTICS_PIN_PEPPER="client-output-pin-pepper-0123456789"
export DIAGNOSTICS_AUDIT_HMAC_KEY="client-output-audit-key-0123456789"
export DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST="1"
export INSPECTIONS_ADMIN_PIN="client-output-admin"
pin="482615"
fixture="$(php "$repo_root/tools/test_diagnostics_photo_complete_fixture.php" --prepare "$storage_root" "$web_root" "$pin")"
report_id="$(json_value "$fixture" report_id)"
inspection_id="$(json_value "$fixture" inspection_record_id)"

python - "$web_root/data/inspections.json" <<'PY'
import json, sys
path = sys.argv[1]
items = json.load(open(path, encoding="utf-8"))
items.append({
    "id": "other-inspection", "title": "Other inspection", "location": "Other region",
    "clientEmail": "", "summary": "Cross-inspection BOLA fixture.", "status": "draft", "pin": "",
    "media": {}, "photos": [], "createdAt": "2026-08-14T00:00:00Z", "updatedAt": "2026-08-14T00:00:00Z"
})
with open(path, "w", encoding="utf-8") as handle:
    json.dump(items, handle, ensure_ascii=False, indent=2)
PY

python - "$test_root" <<'PY'
import base64, pathlib, sys
root = pathlib.Path(sys.argv[1])
(root / "valid.pdf").write_bytes(b"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n")
png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=")
for name in ("one.png", "two.png", "three.png", "other.png", "temporary.png"):
    (root / name).write_bytes(png)
(root / "bad.svg").write_text('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', encoding="utf-8")
(root / "bad.php").write_text('<?php echo "bad"; ?>', encoding="utf-8")
(root / "bad.html").write_text('<!doctype html><script>alert(1)</script>', encoding="utf-8")
PY

package_dir="$storage_root/reports/$report_id/1.0"
package_hash_before="$(package_hash "$package_dir")"

cd "$web_root"
php -d "session.save_path=$session_root" -S "127.0.0.1:$port" -t "$web_root" >"$server_log" 2>&1 &
server_pid=$!
for _ in {1..30}; do curl -sS "$base_url/inspekcie.html" >/dev/null && break; sleep 0.2; done

activate="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"activate-diagnostics\",\"adminPin\":\"client-output-admin\",\"id\":\"$inspection_id\",\"reportId\":\"$report_id\",\"version\":\"1.0\"}" "$base_url/api/inspections.php")"
[[ "$(json_value "$activate" ok)" == "True" ]] || fail "diagnostics activation failed"
access_id="$(json_value "$activate" accessId)"
grant_hash_before="$(sha256sum "$storage_root/access/grants/$access_id.json" | cut -d' ' -f1)"
pin_before="$(python -c 'import json,sys; print(json.load(open(sys.argv[1],encoding="utf-8"))[0]["pin"])' "$web_root/data/inspections.json")"

without_pin="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "{\"action\":\"LIST\",\"inspectionId\":\"$inspection_id\"}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$without_pin" == "403" ]] || fail "admin list worked without an Admin PIN"
wrong_pin="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "{\"action\":\"LIST\",\"adminPin\":\"wrong\",\"inspectionId\":\"$inspection_id\"}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$wrong_pin" == "403" ]] || fail "admin list worked with the wrong Admin PIN"
traversal="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d '{"action":"LIST","adminPin":"client-output-admin","inspectionId":"../reports"}' "$base_url/api/inspection-outputs-admin.php")"
[[ "$traversal" == "422" ]] || fail "inspection record traversal was not rejected"

list="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"LIST\",\"adminPin\":\"client-output-admin\",\"inspectionId\":\"$inspection_id\"}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$list" document.revision)" == "0" ]] || fail "initial client output revision is not zero"

link="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"CREATE-LINK\",\"adminPin\":\"client-output-admin\",\"inspectionId\":\"$inspection_id\",\"expectedRevision\":0,\"type\":\"panoraven\",\"title\":\"Strecha\",\"description\":\"360 prehliadka strechy.\",\"url\":\"https://panoraven.com/en/embed/roof\"}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$link" document.revision)" == "1" ]] || fail "post-publish Panoraven link was not created"
docs="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"CREATE-LINK\",\"adminPin\":\"client-output-admin\",\"inspectionId\":\"$inspection_id\",\"expectedRevision\":1,\"type\":\"google_docs\",\"title\":\"Online správa\",\"description\":\"Online dokument správy.\",\"url\":\"https://docs.google.com/document/d/client-output/preview\"}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$docs" document.revision)" == "2" ]] || fail "post-publish Google Docs link was not created"
gallery="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"CREATE-GALLERY\",\"adminPin\":\"client-output-admin\",\"inspectionId\":\"$inspection_id\",\"expectedRevision\":2,\"title\":\"Strecha z dronu\",\"description\":\"Doplnkové pohľady na strechu.\"}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$gallery" document.revision)" == "3" ]] || fail "post-publish gallery was not created"
gallery_id="$(json_value "$gallery" document.outputs.2.id)"

bad_pdf="$(curl -sS -o /dev/null -w '%{http_code}' -F action=UPLOAD-PDF -F adminPin=client-output-admin -F inspectionId="$inspection_id" -F expectedRevision=3 -F "file=@$test_root/bad.html;filename=../../report.pdf" "$base_url/api/inspection-outputs-admin.php")"
[[ "$bad_pdf" == "422" ]] || fail "non-PDF content passed PDF MIME validation"
pdf="$(curl -sS -F action=UPLOAD-PDF -F adminPin=client-output-admin -F inspectionId="$inspection_id" -F expectedRevision=3 -F title='Klientska PDF správa' -F description='PDF doplnené po publikovaní.' -F "file=@$test_root/valid.pdf;filename=../../client-report.pdf" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$pdf" document.revision)" == "4" ]] || fail "post-publish PDF upload failed"
pdf_media="$(json_value "$pdf" document.outputs.3.mediaId)"
[[ ! -e "$storage_root/client-report.pdf" && ! -e "$storage_root/client-outputs/client-report.pdf" ]] || fail "an upload filename escaped server-generated storage"

for invalid in bad.svg bad.php bad.html; do
  status="$(curl -sS -o /dev/null -w '%{http_code}' -F action=UPLOAD-GALLERY-PHOTOS -F adminPin=client-output-admin -F inspectionId="$inspection_id" -F expectedRevision=4 -F galleryId="$gallery_id" -F "photos[]=@$test_root/$invalid" "$base_url/api/inspection-outputs-admin.php")"
  [[ "$status" == "422" ]] || fail "$invalid passed image MIME validation"
done

photos="$(curl -sS -F action=UPLOAD-GALLERY-PHOTOS -F adminPin=client-output-admin -F inspectionId="$inspection_id" -F expectedRevision=4 -F galleryId="$gallery_id" -F 'titles=["Pohľad 1","Pohľad 2","Pohľad 3"]' -F 'captions=["Od záhrady.","Hrebeň strechy.","Od ulice."]' -F "photos[]=@$test_root/one.png" -F "photos[]=@$test_root/two.png" -F "photos[]=@$test_root/three.png" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$photos" document.revision)" == "5" ]] || fail "three gallery photos were not uploaded"
[[ "$(json_value "$photos" document.outputs.2.photos.2.caption)" == "Od ulice." ]] || fail "gallery photo captions were not stored"
update="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"UPDATE\",\"adminPin\":\"client-output-admin\",\"inspectionId\":\"$inspection_id\",\"expectedRevision\":5,\"outputId\":\"$gallery_id\",\"photoId\":\"$(json_value "$photos" document.outputs.2.photos.0.id)\",\"changes\":{\"title\":\"Pohľad od záhrady\",\"caption\":\"Aktualizovaný popis.\"}}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$update" document.revision)" == "6" ]] || fail "gallery photo update failed"
reorder="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"REORDER\",\"adminPin\":\"client-output-admin\",\"inspectionId\":\"$inspection_id\",\"expectedRevision\":6,\"outputId\":\"$gallery_id\",\"direction\":\"up\"}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$reorder" document.revision)" == "7" ]] || fail "client output reorder failed"

temporary="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"CREATE-GALLERY\",\"adminPin\":\"client-output-admin\",\"inspectionId\":\"$inspection_id\",\"expectedRevision\":7,\"title\":\"Dočasná galéria\",\"description\":\"Na test odstránenia.\"}" "$base_url/api/inspection-outputs-admin.php")"
temporary_id="$(json_value "$temporary" document.outputs.4.id)"
temporary_upload="$(curl -sS -F action=UPLOAD-GALLERY-PHOTOS -F adminPin=client-output-admin -F inspectionId="$inspection_id" -F expectedRevision=8 -F galleryId="$temporary_id" -F "photos[]=@$test_root/temporary.png" "$base_url/api/inspection-outputs-admin.php")"
temporary_media="$(json_value "$temporary_upload" document.outputs.4.photos.0.mediaId)"
temporary_delete="$(curl -sS -H 'Content-Type: application/json' -d "{\"action\":\"DELETE\",\"adminPin\":\"client-output-admin\",\"inspectionId\":\"$inspection_id\",\"expectedRevision\":9,\"outputId\":\"$temporary_id\"}" "$base_url/api/inspection-outputs-admin.php")"
[[ "$(json_value "$temporary_delete" document.revision)" == "10" ]] || fail "client output delete failed"

other_gallery="$(curl -sS -H 'Content-Type: application/json' -d '{"action":"CREATE-GALLERY","adminPin":"client-output-admin","inspectionId":"other-inspection","expectedRevision":0,"title":"Other gallery","description":"BOLA fixture."}' "$base_url/api/inspection-outputs-admin.php")"
other_gallery_id="$(json_value "$other_gallery" document.outputs.0.id)"
other_upload="$(curl -sS -F action=UPLOAD-GALLERY-PHOTOS -F adminPin=client-output-admin -F inspectionId=other-inspection -F expectedRevision=1 -F galleryId="$other_gallery_id" -F "photos[]=@$test_root/other.png" "$base_url/api/inspection-outputs-admin.php")"
other_media="$(json_value "$other_upload" document.outputs.0.photos.0.mediaId)"

package_hash_after_mutation="$(package_hash "$package_dir")"
grant_hash_after_mutation="$(sha256sum "$storage_root/access/grants/$access_id.json" | cut -d' ' -f1)"
pin_after_mutation="$(python -c 'import json,sys; print(json.load(open(sys.argv[1],encoding="utf-8"))[0]["pin"])' "$web_root/data/inspections.json")"
[[ "$package_hash_before" == "$package_hash_after_mutation" ]] || fail "immutable diagnostics package changed after client output mutations"
[[ "$grant_hash_before" == "$grant_hash_after_mutation" ]] || fail "diagnostics grant changed after client output mutations"
[[ "$pin_before" == "$pin_after_mutation" && "$pin_after_mutation" == "$pin" ]] || fail "client PIN changed after client output mutations"

unauthenticated="$(curl -sS -o /dev/null -w '%{http_code}' "$base_url/api/diagnostics-output-media.php?media=$pdf_media")"
[[ "$unauthenticated" == "401" ]] || fail "client output media was available without a diagnostics session"
unlock="$(curl -sS -c "$cookie_jar" -b "$cookie_jar" -H 'Content-Type: application/json' -d "{\"action\":\"unlock\",\"pin\":\"$pin\"}" "$base_url/api/inspections.php")"
[[ "$(json_value "$unlock" mode)" == "diagnostics" ]] || fail "existing PIN no longer opens the diagnostics session"
outputs_file="$test_root/outputs.json"
curl -sS -b "$cookie_jar" -o "$outputs_file" "$base_url/api/diagnostics-outputs.php"
python - "$outputs_file" <<'PY'
import json, sys
body = json.load(open(sys.argv[1], encoding="utf-8"))
assert body["document_type"] == "diagnostics_outputs"
assert any(item["type"] == "pdf" and "diagnostics-output-media.php?media=outm_" in item["url"] for item in body["outputs"])
assert any(item.get("title") == "Strecha" and item["type"] == "panoraven" for item in body["outputs"])
assert any(item.get("title") == "Online správa" and item["type"] == "google_docs" for item in body["outputs"])
assert len(body["galleries"]) == 1 and body["galleries"][0]["title"] == "Strecha z dronu"
assert len(body["galleries"][0]["photos"]) == 3
serialized = json.dumps(body).lower()
assert not any(term in serialized for term in ("filename", "sha256", "filesystem", "storage", "diagnosticsaccessid", "pin"))
PY
pdf_status="$(curl -sS -I -b "$cookie_jar" -o /dev/null -w '%{http_code}' "$base_url/api/diagnostics-output-media.php?media=$pdf_media")"
[[ "$pdf_status" == "200" ]] || fail "session-bound PDF delivery failed"
deleted_status="$(curl -sS -I -b "$cookie_jar" -o /dev/null -w '%{http_code}' "$base_url/api/diagnostics-output-media.php?media=$temporary_media")"
[[ "$deleted_status" == "404" ]] || fail "deleted client output media did not return 404"
cross_status="$(curl -sS -I -b "$cookie_jar" -o /dev/null -w '%{http_code}' "$base_url/api/diagnostics-output-media.php?media=$other_media")"
[[ "$cross_status" == "404" ]] || fail "cross-inspection output media access was not blocked"

package_hash_after="$(package_hash "$package_dir")"
pin_after="$(python -c 'import json,sys; print(json.load(open(sys.argv[1],encoding="utf-8"))[0]["pin"])' "$web_root/data/inspections.json")"
[[ "$package_hash_before" == "$package_hash_after" ]] || fail "immutable diagnostics package changed after client output mutations"
[[ "$pin_before" == "$pin_after" && "$pin_after" == "$pin" ]] || fail "client PIN changed after client output mutations"

grep -Fq 'id="client-output-admin"' "$web_root/inspekcie-admin.html" || fail "client output admin UI is missing"
grep -Fq 'multiple = true' "$web_root/inspekcie-admin.html" || fail "multiple gallery upload UI is missing"
grep -Fq 'Legacy polia výstupov — iba kompatibilita' "$web_root/inspekcie-admin.html" || fail "legacy output fields are not isolated from normal UX"
! grep -R -E -q 'docs\.google\.com/document/d/client-output|panoraven\.com/en/embed/roof' "$storage_root/audit" || fail "security audit leaked a mutable output URL"
! grep -Fq "$pin" "$server_log" || fail "server log leaked the PIN"
! grep -Eqi 'warning|fatal|uncaught|notice' "$server_log" || fail "PHP emitted a runtime warning"

echo "Diagnostics client outputs HTTP test: PASS (post-publish PDF, links, gallery, MIME, BOLA, immutable package and unchanged grant/PIN)"
