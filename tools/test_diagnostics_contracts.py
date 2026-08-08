#!/usr/bin/env python3
"""Regression tests for DoktorHaus diagnostic schemas, fixtures and domain lint."""

from __future__ import annotations

import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any, Iterable


REPO_ROOT = Path(__file__).resolve().parents[1]
SCHEMA_DIR = REPO_ROOT / "docs" / "diagnostics" / "schemas"
VALID_DIR = REPO_ROOT / "docs" / "diagnostics" / "fixtures" / "valid"
INVALID_DIR = REPO_ROOT / "docs" / "diagnostics" / "fixtures" / "invalid"
LINT = REPO_ROOT / "tools" / "diagnostics_lint.py"


def walk_refs(value: Any) -> Iterable[str]:
    if isinstance(value, dict):
        for key, child in value.items():
            if key == "$ref" and isinstance(child, str):
                yield child
            else:
                yield from walk_refs(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk_refs(child)


def resolve_pointer(document: Any, fragment: str) -> bool:
    if not fragment or fragment == "#":
        return True
    if not fragment.startswith("#/"):
        return False
    current = document
    for raw_part in fragment[2:].split("/"):
        part = raw_part.replace("~1", "/").replace("~0", "~")
        if not isinstance(current, dict) or part not in current:
            return False
        current = current[part]
    return True


def validate_schemas(failures: list[str]) -> None:
    schema_paths = sorted(SCHEMA_DIR.glob("*.schema.json"))
    if len(schema_paths) != 5:
        failures.append(f"Expected 5 schemas, found {len(schema_paths)}")
    loaded: dict[Path, dict[str, Any]] = {}
    for path in schema_paths:
        try:
            with path.open("r", encoding="utf-8") as handle:
                schema = json.load(handle)
        except (OSError, UnicodeError, json.JSONDecodeError) as exc:
            failures.append(f"Schema {path.name} is not valid JSON: {exc}")
            continue
        loaded[path] = schema
        for key in ("$schema", "$id", "title", "description"):
            if not isinstance(schema.get(key), str) or not schema[key]:
                failures.append(f"Schema {path.name} is missing metadata {key}")
        if schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
            failures.append(f"Schema {path.name} does not use Draft 2020-12")
        if path.name == "common.schema.json":
            if schema.get("schema_version") != "1.0.0":
                failures.append("common.schema.json must declare schema_version 1.0.0")
        else:
            version_property = schema.get("properties", {}).get("schema_version", {})
            if "$ref" not in version_property:
                failures.append(f"Schema {path.name} lacks top-level schema_version contract")

    for path, schema in loaded.items():
        for ref in walk_refs(schema):
            if ref.startswith(("http://", "https://")):
                continue
            file_part, separator, fragment_part = ref.partition("#")
            target_path = path if not file_part else (path.parent / file_part).resolve()
            if not target_path.is_file():
                failures.append(f"Broken local $ref in {path.name}: {ref}")
                continue
            target = loaded.get(target_path)
            if target is None:
                try:
                    with target_path.open("r", encoding="utf-8") as handle:
                        target = json.load(handle)
                except (OSError, UnicodeError, json.JSONDecodeError) as exc:
                    failures.append(f"Cannot resolve local $ref {ref} in {path.name}: {exc}")
                    continue
            fragment = f"#{fragment_part}" if separator else ""
            if not resolve_pointer(target, fragment):
                failures.append(f"Broken local $ref fragment in {path.name}: {ref}")


def lint_command(inspection: Path, diagnosis: Path | None = None, report: Path | None = None) -> tuple[int, dict[str, Any], str]:
    command = [sys.executable, str(LINT), "--inspection", str(inspection)]
    if diagnosis:
        command.extend(["--diagnosis", str(diagnosis)])
    if report:
        command.extend(["--report-package", str(report)])
    completed = subprocess.run(command, cwd=REPO_ROOT, text=True, capture_output=True, encoding="utf-8")
    try:
        payload = json.loads(completed.stdout)
    except json.JSONDecodeError:
        payload = {}
    return completed.returncode, payload, completed.stderr or completed.stdout


def validate_json_fixtures(failures: list[str]) -> None:
    fixture_paths = sorted((REPO_ROOT / "docs" / "diagnostics" / "fixtures").rglob("*.json"))
    for path in fixture_paths:
        try:
            with path.open("r", encoding="utf-8") as handle:
                json.load(handle)
        except (OSError, UnicodeError, json.JSONDecodeError) as exc:
            failures.append(f"Fixture {path.relative_to(REPO_ROOT)} is not valid JSON: {exc}")


def validate_valid_fixtures(failures: list[str]) -> None:
    minimal_inspection = VALID_DIR / "inspection-minimal.json"
    example_inspection = VALID_DIR / "inspection-example.json"
    cases = [
        ("minimal inspection", minimal_inspection, None, None),
        ("example inspection", example_inspection, None, None),
        ("minimal pair", minimal_inspection, VALID_DIR / "diagnosis-minimal.json", None),
        ("example pair", example_inspection, VALID_DIR / "diagnosis-example.json", None),
        ("example report package", example_inspection, VALID_DIR / "diagnosis-example.json", VALID_DIR / "report-package-example.json"),
    ]
    for label, inspection, diagnosis, report in cases:
        code, payload, detail = lint_command(inspection, diagnosis, report)
        errors = payload.get("errors") if isinstance(payload, dict) else None
        if code != 0 or errors:
            failures.append(f"Valid case '{label}' failed with exit {code}: {detail or json.dumps(payload, ensure_ascii=False)}")


def validate_invalid_fixtures(failures: list[str]) -> None:
    minimal_inspection = VALID_DIR / "inspection-minimal.json"
    cases = {
        "dangling-reference.json": ("diagnosis", "E_DANGLING_REFERENCE"),
        "duplicate-id.json": ("diagnosis", "E_DUPLICATE_ID"),
        "invalid-cost-range.json": ("diagnosis", "E_COST_RANGE"),
        "invalid-impact-count.json": ("diagnosis", "E_IMPACT_DIMENSIONS"),
        "dependency-cycle.json": ("diagnosis", "E_DEPENDENCY_CYCLE"),
        "invalid-report-approval.json": ("report", "E_REPORT_APPROVAL"),
    }
    for filename, (kind, expected_code) in cases.items():
        path = INVALID_DIR / filename
        diagnosis = path if kind == "diagnosis" else None
        report = path if kind == "report" else None
        code, payload, detail = lint_command(minimal_inspection, diagnosis, report)
        actual_codes = {item.get("code") for item in payload.get("errors", [])} if isinstance(payload, dict) else set()
        if code != 1:
            failures.append(f"Invalid fixture {filename} returned exit {code}, expected 1: {detail}")
        if expected_code not in actual_codes:
            failures.append(f"Invalid fixture {filename} lacks {expected_code}; got {sorted(code for code in actual_codes if code)}")


def validate_client_report_fixture(failures: list[str]) -> None:
    path = VALID_DIR / "client-report-example.json"
    try:
        with path.open("r", encoding="utf-8") as handle:
            report = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        failures.append(f"Client report fixture cannot be read: {exc}")
        return

    expected_keys = {
        "schema_version", "document_type", "report", "property", "inspection", "overview", "issues",
        "recommendations", "verifications", "issue_relations", "unverified_items", "generated_at",
    }
    if set(report) != expected_keys:
        failures.append(f"Client report fixture top-level keys differ: {sorted(set(report) ^ expected_keys)}")
    if report.get("schema_version") != "1.0.0" or report.get("document_type") != "client_report":
        failures.append("Client report fixture metadata is invalid")

    forbidden = {
        "qa", "actors", "actor_ids", "approved_by", "observed_by", "captured_by", "performed_by",
        "provenance", "import_metadata", "source_system", "source_inspection_id", "source_item_id",
        "source_media_id", "source_reference", "source_hash", "pin", "pin_hash", "csrf_token",
        "session_id", "report_id", "report_version_id", "package_manifest_sha256", "media_reference",
        "sha256", "address_private", "storage_path", "filesystem_path",
    }

    def scan(value: Any, location: str = "$") -> None:
        if isinstance(value, dict):
            for key, child in value.items():
                if key in forbidden:
                    failures.append(f"Forbidden client-report key {key} at {location}")
                scan(child, f"{location}.{key}")
        elif isinstance(value, list):
            for index, child in enumerate(value):
                scan(child, f"{location}[{index}]")

    scan(report)
    for issue in report.get("issues", []):
        if not isinstance(issue, dict):
            continue
        if len(issue.get("impacts", [])) != 7:
            failures.append("Client report fixture issue must contain exactly seven impacts")
        for evidence in issue.get("evidence", []):
            if not isinstance(evidence, dict) or not evidence.get("has_media"):
                continue
            media_url = evidence.get("media_url")
            if not isinstance(media_url, str) or re.fullmatch(
                r"api/diagnostics-media\.php\?evidence=ev_[0-9a-f]{16,32}", media_url
            ) is None:
                failures.append(f"Client report fixture has an invalid media URL: {media_url!r}")


def validate_warning_exit_semantics(failures: list[str]) -> None:
    inspection = VALID_DIR / "inspection-example.json"
    with (VALID_DIR / "diagnosis-example.json").open("r", encoding="utf-8") as handle:
        diagnosis = json.load(handle)
    diagnosis["issues"][0]["severity"] = "S5"
    diagnosis["issues"][0]["severity_rationale"] = "Testovacia zmena má vyvolať iba warning pri existujúcich U2/P2."
    with tempfile.TemporaryDirectory(prefix="diagnostics-contract-test-") as temp_dir:
        warning_fixture = Path(temp_dir) / "diagnosis-warning.json"
        with warning_fixture.open("w", encoding="utf-8") as handle:
            json.dump(diagnosis, handle, ensure_ascii=False)
        code, payload, detail = lint_command(inspection, warning_fixture)
    warning_codes = {item.get("code") for item in payload.get("warnings", [])} if isinstance(payload, dict) else set()
    if code != 0 or payload.get("errors"):
        failures.append(f"Warnings-only lint returned exit {code}, expected 0: {detail or payload}")
    if "W_S5_PRIORITY" not in warning_codes:
        failures.append(f"Warnings-only lint lacks W_S5_PRIORITY; got {sorted(code for code in warning_codes if code)}")


def main() -> int:
    failures: list[str] = []
    validate_schemas(failures)
    validate_json_fixtures(failures)
    validate_valid_fixtures(failures)
    validate_invalid_fixtures(failures)
    validate_client_report_fixture(failures)
    validate_warning_exit_semantics(failures)
    if failures:
        print("Diagnostic contract tests FAILED")
        for failure in failures:
            print(f"- {failure}")
        return 1
    valid_count = len(list(VALID_DIR.glob("*.json")))
    invalid_count = len(list(INVALID_DIR.glob("*.json")))
    schema_count = len(list(SCHEMA_DIR.glob("*.schema.json")))
    print(f"Diagnostic contract tests passed: {schema_count} schemas, {valid_count} valid fixtures, {invalid_count} invalid fixtures.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
