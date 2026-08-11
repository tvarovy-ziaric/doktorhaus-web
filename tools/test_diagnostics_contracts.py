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
    if len(schema_paths) != 6:
        failures.append(f"Expected 6 schemas, found {len(schema_paths)}")
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


def validate_report_pricing_schema_contract(failures: list[str]) -> None:
    try:
        with (SCHEMA_DIR / "report-pricing.schema.json").open("r", encoding="utf-8") as handle:
            schema = json.load(handle)
        with (SCHEMA_DIR / "client-report.schema.json").open("r", encoding="utf-8") as handle:
            client_schema = json.load(handle)
        with (SCHEMA_DIR / "report-package.schema.json").open("r", encoding="utf-8") as handle:
            package_schema = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        failures.append(f"Report-pricing schema contract cannot be read: {exc}")
        return

    expected_kinds = {"total_range", "unit_range", "fixed_unit", "no_direct_cost", "not_estimated"}
    actual_kinds = set(schema.get("$defs", {}).get("pricing_kind", {}).get("enum", []))
    if actual_kinds != expected_kinds:
        failures.append(f"Report-pricing kinds differ: {sorted(actual_kinds ^ expected_kinds)}")
    component = schema.get("$defs", {}).get("component", {})
    if component.get("additionalProperties") is not False:
        failures.append("Report-pricing component must use additionalProperties false")
    if len(component.get("allOf", [])) < 7:
        failures.append("Report-pricing component lacks mutually constrained pricing/ownership shapes")
    if client_schema.get("properties", {}).get("pricing", {}).get("$ref") != "report-pricing.schema.json#/$defs/client_projection":
        failures.append("Client-report schema lacks optional strict pricing projection reference")
    package_roles = set(
        package_schema.get("$defs", {}).get("manifest_file", {}).get("properties", {}).get("role", {}).get("enum", [])
    )
    if "report_pricing" not in package_roles:
        failures.append("Report package schema lacks report_pricing file role")


def lint_command(
    inspection: Path,
    diagnosis: Path | None = None,
    report: Path | None = None,
    pricing: Path | None = None,
) -> tuple[int, dict[str, Any], str]:
    command = [sys.executable, str(LINT), "--inspection", str(inspection)]
    if diagnosis:
        command.extend(["--diagnosis", str(diagnosis)])
    if report:
        command.extend(["--report-package", str(report)])
    if pricing:
        command.extend(["--report-pricing", str(pricing)])
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

    pricing_cases = [
        ("report pricing explicit subtotal", VALID_DIR / "report-pricing-example.json"),
        ("report pricing total not computed", VALID_DIR / "report-pricing-not-computed.json"),
    ]
    for label, pricing in pricing_cases:
        code, payload, detail = lint_command(minimal_inspection, pricing=pricing)
        errors = payload.get("errors") if isinstance(payload, dict) else None
        if code != 0 or errors:
            failures.append(f"Valid case '{label}' failed with exit {code}: {detail or json.dumps(payload, ensure_ascii=False)}")


def validate_pricing_scenarios(failures: list[str]) -> None:
    try:
        with (VALID_DIR / "diagnosis-minimal.json").open("r", encoding="utf-8") as handle:
            diagnosis = json.load(handle)
        with (VALID_DIR / "report-pricing-example.json").open("r", encoding="utf-8") as handle:
            subtotal = json.load(handle)
        with (VALID_DIR / "report-pricing-not-computed.json").open("r", encoding="utf-8") as handle:
            no_total = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        failures.append(f"Pricing scenarios cannot be read: {exc}")
        return

    components = {item.get("display_code"): item for item in subtotal.get("components", []) if isinstance(item, dict)}
    no_total_components = {item.get("display_code"): item for item in no_total.get("components", []) if isinstance(item, dict)}
    issue = diagnosis.get("issues", [{}])[0]
    if issue.get("cost_estimate", {}).get("status") != "not_estimated" or issue.get("id") not in components.get("RP-001", {}).get("linked_issue_ids", []):
        failures.append("PASS A must preserve whole issue not_estimated while partial verification is priced")
    if no_total_components.get("RP-002", {}).get("quantity", {}).get("value") is not None or "computed_total" in no_total_components.get("RP-002", {}).get("pricing", {}):
        failures.append("PASS B must keep unit material without quantity outside a computed total")
    direct = no_total_components.get("RP-003", {}).get("pricing", {})
    if [direct.get("min"), direct.get("expected"), direct.get("max")] != [0, 0, 0]:
        failures.append("PASS C must encode no_direct_cost as explicit 0/0/0")
    if no_total_components.get("RP-004", {}).get("conditional") is not True or no_total.get("aggregation", {}).get("status") != "not_computed":
        failures.append("PASS D must keep conditional repair outside an unconditional subtotal")
    shared = components.get("RP-005", {})
    selected = subtotal.get("aggregation", {}).get("component_ids", [])
    if len(shared.get("linked_issue_ids", [])) != 2 or selected.count(shared.get("id")) != 1:
        failures.append("PASS E must link one shared component to two issues and count it once")


def validate_pricing_domain_mutations(failures: list[str]) -> None:
    inspection = VALID_DIR / "inspection-minimal.json"
    try:
        with (VALID_DIR / "report-pricing-not-computed.json").open("r", encoding="utf-8") as handle:
            no_total = json.load(handle)
        with (VALID_DIR / "report-pricing-example.json").open("r", encoding="utf-8") as handle:
            subtotal = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        failures.append(f"Pricing domain mutations cannot be read: {exc}")
        return

    mutations: list[tuple[str, dict[str, Any], str]] = []
    conditional = json.loads(json.dumps(no_total))
    conditional_component = next(item for item in conditional["components"] if item.get("display_code") == "RP-004")
    conditional["aggregation"] = {
        "status": "subtotal",
        "method": "explicit_component_allowlist",
        "component_ids": [conditional_component["id"]],
        "min": 500,
        "expected": 750,
        "max": 1000,
        "currency": "EUR",
    }
    mutations.append(("conditional subtotal", conditional, "E_PRICING_CONDITIONAL_SUBTOTAL"))

    shared_duplicate = json.loads(json.dumps(subtotal))
    shared_id = next(item["id"] for item in shared_duplicate["components"] if item.get("shared_across_issues"))
    shared_duplicate["aggregation"]["component_ids"].append(shared_id)
    mutations.append(("shared duplicate subtotal", shared_duplicate, "E_PRICING_DUPLICATE_SUBTOTAL_COMPONENT"))

    duplicate_id = json.loads(json.dumps(no_total))
    duplicate_id["components"][1]["id"] = duplicate_id["components"][0]["id"]
    mutations.append(("duplicate pricing ID", duplicate_id, "E_DUPLICATE_PRICING_ID"))

    provider_visible = json.loads(json.dumps(no_total))
    provider = next(item for item in provider_visible["components"] if item.get("ownership") == "service_provider_equipment")
    provider["client_visible"] = True
    mutations.append(("provider equipment client visibility", provider_visible, "E_CLIENT_PROVIDER_EQUIPMENT"))

    with tempfile.TemporaryDirectory(prefix="diagnostics-pricing-domain-") as temp_dir:
        for index, (label, document, expected_code) in enumerate(mutations):
            path = Path(temp_dir) / f"pricing-{index}.json"
            with path.open("w", encoding="utf-8") as handle:
                json.dump(document, handle, ensure_ascii=False)
            code, payload, detail = lint_command(inspection, pricing=path)
            actual_codes = {item.get("code") for item in payload.get("errors", [])} if isinstance(payload, dict) else set()
            if code != 1 or expected_code not in actual_codes:
                failures.append(f"Pricing mutation '{label}' expected {expected_code}, got exit {code}: {detail or payload}")


def validate_pricing_package_ownership(failures: list[str]) -> None:
    inspection = VALID_DIR / "inspection-example.json"
    package = VALID_DIR / "report-package-example.json"
    try:
        with (VALID_DIR / "report-pricing-example.json").open("r", encoding="utf-8") as handle:
            pricing = json.load(handle)
        with package.open("r", encoding="utf-8") as handle:
            manifest = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        failures.append(f"Pricing package ownership fixtures cannot be read: {exc}")
        return

    pricing["report_id"] = manifest["report"]["id"]
    pricing["report_version_id"] = manifest["report_version"]["id"]
    pricing["inspection_id"] = manifest["report"]["inspection_id"]
    with tempfile.TemporaryDirectory(prefix="diagnostics-pricing-ownership-") as temp_dir:
        pricing_path = Path(temp_dir) / "report-pricing.json"
        with pricing_path.open("w", encoding="utf-8") as handle:
            json.dump(pricing, handle, ensure_ascii=False)
        code, payload, detail = lint_command(inspection, report=package, pricing=pricing_path)
        if code != 0 or payload.get("errors"):
            failures.append(f"Matching report-pricing/package ownership failed: {detail or payload}")

        pricing["report_version_id"] = "rptv_ffffffffffffffff"
        with pricing_path.open("w", encoding="utf-8") as handle:
            json.dump(pricing, handle, ensure_ascii=False)
        code, payload, detail = lint_command(inspection, report=package, pricing=pricing_path)
        codes = {item.get("code") for item in payload.get("errors", [])} if isinstance(payload, dict) else set()
        if code != 1 or "E_REPORT_PRICING_OWNERSHIP" not in codes:
            failures.append(f"Mismatched report-pricing/package ownership was not blocked: {detail or payload}")


def validate_invalid_fixtures(failures: list[str]) -> None:
    minimal_inspection = VALID_DIR / "inspection-minimal.json"
    cases = {
        "dangling-reference.json": ("diagnosis", "E_DANGLING_REFERENCE"),
        "duplicate-id.json": ("diagnosis", "E_DUPLICATE_ID"),
        "invalid-cost-range.json": ("diagnosis", "E_COST_RANGE"),
        "invalid-impact-count.json": ("diagnosis", "E_IMPACT_DIMENSIONS"),
        "dependency-cycle.json": ("diagnosis", "E_DEPENDENCY_CYCLE"),
        "invalid-report-approval.json": ("report", "E_REPORT_APPROVAL"),
        "invalid-report-pricing-unit-total.json": ("pricing", "E_PRICING_UNIT_TOTAL_WITHOUT_QUANTITY"),
        "invalid-report-pricing-no-direct-cost.json": ("pricing", "E_PRICING_NO_DIRECT_COST"),
        "invalid-report-pricing-not-estimated.json": ("pricing", "E_PRICING_NOT_ESTIMATED_REASON"),
        "invalid-report-pricing-range.json": ("pricing", "E_PRICING_RANGE"),
        "invalid-report-pricing-reference.json": ("pricing_with_diagnosis", "E_DANGLING_REFERENCE"),
        "invalid-report-pricing-internal-field.json": ("pricing", "E_CLIENT_INTERNAL_FIELD"),
    }
    for filename, (kind, expected_code) in cases.items():
        path = INVALID_DIR / filename
        diagnosis = path if kind == "diagnosis" else None
        report = path if kind == "report" else None
        pricing = path if kind in {"pricing", "pricing_with_diagnosis"} else None
        if kind == "pricing_with_diagnosis":
            diagnosis = VALID_DIR / "diagnosis-minimal.json"
        code, payload, detail = lint_command(minimal_inspection, diagnosis, report, pricing)
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
        "internal_tariff", "internal_labour_cost", "equipment_acquisition_cost", "travel_costing",
        "margin", "markup", "internal_business_notes", "private_supplier_negotiations",
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
    validate_report_pricing_schema_contract(failures)
    validate_json_fixtures(failures)
    validate_valid_fixtures(failures)
    validate_pricing_scenarios(failures)
    validate_pricing_domain_mutations(failures)
    validate_pricing_package_ownership(failures)
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
