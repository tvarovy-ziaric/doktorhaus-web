#!/usr/bin/env python3
"""Domain lint for DoktorHaus diagnostic JSON contracts.

This tool intentionally is not a general JSON Schema Draft 2020-12 evaluator.
It uses only the Python standard library and checks cross-file/domain invariants.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any, Iterable


IMPACT_DIMENSIONS = {
    "safety",
    "structural",
    "moisture",
    "health",
    "durability",
    "usability",
    "financial",
}
REPORT_VERSION_RE = re.compile(r"^[1-9][0-9]*\.[0-9]+$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
PUBLIC_URL_RE = re.compile(r"^https?://", re.IGNORECASE)
WINDOWS_ABSOLUTE_RE = re.compile(r"^[A-Za-z]:[\\/]")
CLIENT_INTERNAL_PRICING_FIELDS = {
    "internal_tariff",
    "internal_labour_cost",
    "equipment_acquisition_cost",
    "travel_costing",
    "margin",
    "markup",
    "internal_business_notes",
    "private_supplier_negotiations",
    "storage_path",
    "filesystem_path",
}


class Findings:
    def __init__(self) -> None:
        self.errors: list[dict[str, str]] = []
        self.warnings: list[dict[str, str]] = []

    def error(self, code: str, message: str, path: str = "$") -> None:
        self.errors.append({"code": code, "path": path, "message": message})

    def warning(self, code: str, message: str, path: str = "$") -> None:
        self.warnings.append({"code": code, "path": path, "message": message})


def load_json(path: Path) -> dict[str, Any]:
    try:
        with path.open("r", encoding="utf-8") as handle:
            value = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError(f"Cannot read valid JSON from {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise ValueError(f"Top-level JSON value in {path} must be an object")
    return value


def require_top_level(document: dict[str, Any], required: Iterable[str], findings: Findings, label: str) -> None:
    for field in required:
        if field not in document:
            findings.error("E_REQUIRED_FIELD", f"{label} is missing required top-level field '{field}'.", f"$.{field}")


def ensure_document_type(document: dict[str, Any], expected: str, findings: Findings) -> None:
    if document.get("document_type") != expected:
        findings.error("E_DOCUMENT_TYPE", f"Expected document_type '{expected}'.", "$.document_type")


def check_duplicate_ids(groups: Iterable[tuple[str, list[dict[str, Any]]]], findings: Findings, label: str) -> None:
    locations: defaultdict[str, list[str]] = defaultdict(list)
    for group_name, items in groups:
        for index, item in enumerate(items):
            if isinstance(item, dict) and isinstance(item.get("id"), str):
                locations[item["id"]].append(f"$.{group_name}[{index}].id")
    for object_id, paths in locations.items():
        if len(paths) > 1:
            findings.error("E_DUPLICATE_ID", f"Duplicate ID '{object_id}' in {label}: {', '.join(paths)}.", paths[1])


def check_actor_reference(actor_id: Any, actors: set[str], findings: Findings, path: str) -> None:
    if actor_id is not None and actor_id not in actors:
        findings.error("E_DANGLING_REFERENCE", f"Actor reference '{actor_id}' does not exist.", path)


def provenance_key(item: dict[str, Any]) -> tuple[str, str, str] | None:
    provenance = item.get("provenance")
    if not isinstance(provenance, dict) or provenance.get("source_kind") != "safetyculture":
        return None
    values = (provenance.get("source_system"), provenance.get("source_inspection_id"), provenance.get("source_item_id"))
    if all(isinstance(value, str) and value for value in values):
        return values  # type: ignore[return-value]
    return None


def validate_inspection(inspection: dict[str, Any], findings: Findings) -> dict[str, set[str]]:
    required = [
        "schema_version", "document_type", "id", "property", "inspection", "actors", "observations",
        "evidence", "observation_evidence_links", "import_metadata", "created_at", "updated_at",
    ]
    require_top_level(inspection, required, findings, "inspection")
    ensure_document_type(inspection, "inspection", findings)

    doc_id = inspection.get("id")
    property_obj = inspection.get("property") if isinstance(inspection.get("property"), dict) else {}
    metadata = inspection.get("inspection") if isinstance(inspection.get("inspection"), dict) else {}
    actors_list = inspection.get("actors") if isinstance(inspection.get("actors"), list) else []
    observations = inspection.get("observations") if isinstance(inspection.get("observations"), list) else []
    evidence = inspection.get("evidence") if isinstance(inspection.get("evidence"), list) else []
    links = inspection.get("observation_evidence_links") if isinstance(inspection.get("observation_evidence_links"), list) else []

    check_duplicate_ids(
        [("observations", observations), ("evidence", evidence), ("observation_evidence_links", links)],
        findings,
        "inspection document",
    )

    actors = {item.get("id") for item in actors_list if isinstance(item, dict) and isinstance(item.get("id"), str)}
    actor_counts = Counter(item.get("id") for item in actors_list if isinstance(item, dict) and isinstance(item.get("id"), str))
    for actor_id, count in actor_counts.items():
        if count > 1:
            findings.error("E_DUPLICATE_ID", f"Duplicate actor ID '{actor_id}' in inspection actors.", "$.actors")

    if metadata.get("property_id") != property_obj.get("id"):
        findings.error("E_INSPECTION_CONSISTENCY", "inspection.property_id must equal property.id.", "$.inspection.property_id")
    for actor_id in metadata.get("actor_ids", []) if isinstance(metadata.get("actor_ids"), list) else []:
        check_actor_reference(actor_id, actors, findings, "$.inspection.actor_ids")

    observation_ids = {item.get("id") for item in observations if isinstance(item, dict) and isinstance(item.get("id"), str)}
    evidence_ids = {item.get("id") for item in evidence if isinstance(item, dict) and isinstance(item.get("id"), str)}

    keys: defaultdict[tuple[str, str, str], list[str]] = defaultdict(list)
    for collection_name, items in (("observations", observations), ("evidence", evidence)):
        for index, item in enumerate(items):
            if not isinstance(item, dict):
                continue
            if item.get("inspection_id") != doc_id:
                findings.error(
                    "E_INSPECTION_CONSISTENCY",
                    f"{collection_name} item must reference inspection ID '{doc_id}'.",
                    f"$.{collection_name}[{index}].inspection_id",
                )
            key = provenance_key(item)
            if key:
                keys[key].append(f"$.{collection_name}[{index}].provenance")
            if collection_name == "observations":
                check_actor_reference(item.get("observed_by"), actors, findings, f"$.observations[{index}].observed_by")
            else:
                check_actor_reference(item.get("captured_by"), actors, findings, f"$.evidence[{index}].captured_by")
                privacy = item.get("privacy")
                media_reference = item.get("media_reference")
                if privacy in {"client_private", "internal"} and isinstance(media_reference, str) and PUBLIC_URL_RE.match(media_reference):
                    findings.error(
                        "E_PRIVATE_PUBLIC_URL",
                        "Client-private/internal evidence must not use an http(s) media_reference.",
                        f"$.evidence[{index}].media_reference",
                    )

    for key, paths in keys.items():
        if len(paths) > 1:
            findings.error(
                "E_DUPLICATE_IDEMPOTENCY_KEY",
                f"Duplicate SafetyCulture idempotency key {key!r}: {', '.join(paths)}.",
                paths[1],
            )

    for index, link in enumerate(links):
        if not isinstance(link, dict):
            continue
        if link.get("observation_id") not in observation_ids:
            findings.error("E_DANGLING_REFERENCE", f"Unknown observation '{link.get('observation_id')}'.", f"$.observation_evidence_links[{index}].observation_id")
        if link.get("evidence_id") not in evidence_ids:
            findings.error("E_DANGLING_REFERENCE", f"Unknown evidence '{link.get('evidence_id')}'.", f"$.observation_evidence_links[{index}].evidence_id")
        check_actor_reference(link.get("created_by"), actors, findings, f"$.observation_evidence_links[{index}].created_by")

    return {"actors": actors, "observations": observation_ids, "evidence": evidence_ids}


def validate_cost(cost: Any, findings: Findings, path: str) -> None:
    if not isinstance(cost, dict):
        findings.error("E_COST_ESTIMATE", "cost_estimate must be an object.", path)
        return
    status = cost.get("status")
    if status == "estimated":
        required = ["min", "expected", "max", "currency", "confidence", "price_basis_date", "scope", "assumptions", "exclusions", "source_method", "vat_status"]
        missing = [field for field in required if field not in cost]
        if missing:
            findings.error("E_COST_ESTIMATE", f"Estimated cost is missing: {', '.join(missing)}.", path)
            return
        values = (cost.get("min"), cost.get("expected"), cost.get("max"))
        if not all(isinstance(value, (int, float)) and not isinstance(value, bool) for value in values):
            findings.error("E_COST_ESTIMATE", "Estimated min/expected/max must be numeric.", path)
            return
        minimum, expected, maximum = values
        if not minimum <= expected <= maximum:
            findings.error("E_COST_RANGE", "Cost invariant min <= expected <= max is violated.", path)
        if cost.get("confidence") == "low" and expected > 0 and (maximum - minimum) / expected < 0.20:
            findings.warning(
                "W_NARROW_LOW_CONFIDENCE_COST",
                "Low-confidence cost range is narrower than 20% of expected; verify that the interval reflects uncertainty.",
                path,
            )
    elif status == "not_estimated":
        if not isinstance(cost.get("reason"), str) or not cost.get("reason", "").strip():
            findings.error("E_COST_ESTIMATE", "not_estimated cost requires a non-empty reason.", path)
    else:
        findings.error("E_COST_ESTIMATE", "cost_estimate.status must be estimated or not_estimated.", f"{path}.status")


def is_number(value: Any) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def validate_pricing_range(value: Any, findings: Findings, path: str) -> bool:
    if not isinstance(value, dict):
        findings.error("E_PRICING_SHAPE", "Pricing value must be an object.", path)
        return False
    values = (value.get("min"), value.get("expected"), value.get("max"))
    if not all(is_number(item) and item >= 0 for item in values):
        findings.error("E_PRICING_SHAPE", "Pricing min/expected/max must be non-negative numbers.", path)
        return False
    minimum, expected, maximum = values
    if not minimum <= expected <= maximum:
        findings.error("E_PRICING_RANGE", "Pricing invariant min <= expected <= max is violated.", path)
        return False
    return True


def find_forbidden_pricing_fields(value: Any, path: str = "$") -> list[tuple[str, str]]:
    found: list[tuple[str, str]] = []
    if isinstance(value, dict):
        for key, child in value.items():
            child_path = f"{path}.{key}"
            if key in CLIENT_INTERNAL_PRICING_FIELDS:
                found.append((key, child_path))
            found.extend(find_forbidden_pricing_fields(child, child_path))
    elif isinstance(value, list):
        for index, child in enumerate(value):
            found.extend(find_forbidden_pricing_fields(child, f"{path}[{index}]"))
    return found


def validate_report_pricing(
    pricing: dict[str, Any],
    inspection: dict[str, Any],
    diagnosis: dict[str, Any] | None,
    findings: Findings,
) -> None:
    required = [
        "schema_version", "document_type", "report_id", "report_version_id", "inspection_id",
        "components", "aggregation", "generated_at",
    ]
    require_top_level(pricing, required, findings, "report pricing")
    ensure_document_type(pricing, "report_pricing", findings)
    if pricing.get("inspection_id") != inspection.get("id"):
        findings.error(
            "E_INSPECTION_CONSISTENCY",
            "report pricing inspection_id must equal inspection.id.",
            "$.inspection_id",
        )

    components = pricing.get("components") if isinstance(pricing.get("components"), list) else []
    component_locations: defaultdict[str, list[int]] = defaultdict(list)
    for index, component in enumerate(components):
        if isinstance(component, dict) and isinstance(component.get("id"), str):
            component_locations[component["id"]].append(index)
    for component_id, indexes in component_locations.items():
        if len(indexes) > 1:
            findings.error(
                "E_DUPLICATE_PRICING_ID",
                f"Duplicate report-pricing component ID '{component_id}'.",
                f"$.components[{indexes[1]}].id",
            )

    issue_ids: set[str] | None = None
    recommendation_ids: set[str] | None = None
    if diagnosis is not None:
        issue_ids = {
            item.get("id") for item in diagnosis.get("issues", [])
            if isinstance(item, dict) and isinstance(item.get("id"), str)
        }
        recommendation_ids = {
            item.get("id") for item in diagnosis.get("recommendations", [])
            if isinstance(item, dict) and isinstance(item.get("id"), str)
        }

    components_by_id: dict[str, dict[str, Any]] = {}
    for index, component in enumerate(components):
        path = f"$.components[{index}]"
        if not isinstance(component, dict):
            findings.error("E_PRICING_SHAPE", "Pricing component must be an object.", path)
            continue
        component_id = component.get("id")
        if isinstance(component_id, str) and component_id not in components_by_id:
            components_by_id[component_id] = component

        if issue_ids is not None:
            for linked_id in component.get("linked_issue_ids", []) if isinstance(component.get("linked_issue_ids"), list) else []:
                if linked_id not in issue_ids:
                    findings.error("E_DANGLING_REFERENCE", f"Pricing component references unknown issue '{linked_id}'.", f"{path}.linked_issue_ids")
        if recommendation_ids is not None:
            for linked_id in component.get("linked_recommendation_ids", []) if isinstance(component.get("linked_recommendation_ids"), list) else []:
                if linked_id not in recommendation_ids:
                    findings.error("E_DANGLING_REFERENCE", f"Pricing component references unknown recommendation '{linked_id}'.", f"{path}.linked_recommendation_ids")

        if component.get("client_visible") is True:
            if component.get("ownership") == "service_provider_equipment":
                findings.error(
                    "E_CLIENT_PROVIDER_EQUIPMENT",
                    "Service-provider equipment must not be a client-visible remediation cost.",
                    f"{path}.ownership",
                )
            for field, field_path in find_forbidden_pricing_fields(component, path):
                findings.error(
                    "E_CLIENT_INTERNAL_FIELD",
                    f"Client-visible pricing component contains forbidden internal business field '{field}'.",
                    field_path,
                )

        kind = component.get("pricing_kind")
        price = component.get("pricing")
        quantity = component.get("quantity") if isinstance(component.get("quantity"), dict) else {}
        if kind in {"total_range", "unit_range"}:
            validate_pricing_range(price, findings, f"{path}.pricing")
        elif kind == "fixed_unit":
            if not isinstance(price, dict) or not is_number(price.get("amount")) or price.get("amount") < 0:
                findings.error("E_PRICING_SHAPE", "fixed_unit requires a non-negative numeric amount.", f"{path}.pricing")
        elif kind == "no_direct_cost":
            if not isinstance(price, dict) or any(price.get(key) != 0 for key in ("min", "expected", "max")):
                findings.error(
                    "E_PRICING_NO_DIRECT_COST",
                    "no_direct_cost must explicitly use 0/0/0 for its defined scope.",
                    f"{path}.pricing",
                )
        elif kind == "not_estimated":
            if not isinstance(price, dict) or not isinstance(price.get("reason"), str) or not price.get("reason", "").strip():
                findings.error(
                    "E_PRICING_NOT_ESTIMATED_REASON",
                    "not_estimated pricing requires a non-empty reason.",
                    f"{path}.pricing.reason",
                )
        else:
            findings.error("E_PRICING_KIND", "Unsupported report-pricing kind.", f"{path}.pricing_kind")

        if kind in {"unit_range", "fixed_unit"} and isinstance(price, dict) and "computed_total" in price:
            if quantity.get("status") != "known" or not is_number(quantity.get("value")) or quantity.get("value") <= 0:
                findings.error(
                    "E_PRICING_UNIT_TOTAL_WITHOUT_QUANTITY",
                    "A unit-price component may contain computed_total only with a known positive quantity.",
                    f"{path}.pricing.computed_total",
                )
            validate_pricing_range(price.get("computed_total"), findings, f"{path}.pricing.computed_total")

    aggregation = pricing.get("aggregation") if isinstance(pricing.get("aggregation"), dict) else {}
    aggregation_status = aggregation.get("status")
    if aggregation_status == "not_computed":
        if not isinstance(aggregation.get("reason"), str) or not aggregation.get("reason", "").strip():
            findings.error("E_PRICING_AGGREGATION", "not_computed aggregation requires a reason.", "$.aggregation.reason")
    elif aggregation_status == "subtotal":
        validate_pricing_range(aggregation, findings, "$.aggregation")
        selected = aggregation.get("component_ids") if isinstance(aggregation.get("component_ids"), list) else []
        duplicates = [component_id for component_id, count in Counter(selected).items() if count > 1]
        if duplicates:
            findings.error(
                "E_PRICING_DUPLICATE_SUBTOTAL_COMPONENT",
                f"Subtotal includes component more than once: {', '.join(str(item) for item in duplicates)}.",
                "$.aggregation.component_ids",
            )

        subtotal_values = [0.0, 0.0, 0.0]
        subtotal_currency = aggregation.get("currency")
        subtotal_can_be_checked = True
        for component_id in dict.fromkeys(selected):
            component = components_by_id.get(component_id)
            if component is None:
                findings.error("E_DANGLING_REFERENCE", f"Subtotal references unknown pricing component '{component_id}'.", "$.aggregation.component_ids")
                subtotal_can_be_checked = False
                continue
            if component.get("conditional") is True:
                findings.error(
                    "E_PRICING_CONDITIONAL_SUBTOTAL",
                    f"Conditional component '{component_id}' cannot enter an unconditional subtotal.",
                    "$.aggregation.component_ids",
                )
                subtotal_can_be_checked = False
                continue
            kind = component.get("pricing_kind")
            price = component.get("pricing") if isinstance(component.get("pricing"), dict) else {}
            if kind == "total_range":
                source = price
            elif kind in {"unit_range", "fixed_unit"}:
                source = price.get("computed_total") if isinstance(price.get("computed_total"), dict) else None
                if source is None:
                    findings.error(
                        "E_PRICING_UNIT_TOTAL_WITHOUT_QUANTITY",
                        f"Unit component '{component_id}' cannot enter subtotal without quantity and computed_total.",
                        "$.aggregation.component_ids",
                    )
                    subtotal_can_be_checked = False
                    continue
            elif kind == "no_direct_cost":
                source = price
            else:
                findings.error(
                    "E_PRICING_INELIGIBLE_SUBTOTAL",
                    f"Component '{component_id}' is not eligible for subtotal.",
                    "$.aggregation.component_ids",
                )
                subtotal_can_be_checked = False
                continue
            if source.get("currency") != subtotal_currency:
                findings.error("E_PRICING_CURRENCY", f"Component '{component_id}' uses another currency than subtotal.", "$.aggregation.currency")
                subtotal_can_be_checked = False
                continue
            if all(is_number(source.get(key)) for key in ("min", "expected", "max")):
                subtotal_values[0] += float(source["min"])
                subtotal_values[1] += float(source["expected"])
                subtotal_values[2] += float(source["max"])
            else:
                subtotal_can_be_checked = False
        declared = (aggregation.get("min"), aggregation.get("expected"), aggregation.get("max"))
        if subtotal_can_be_checked and all(is_number(item) for item in declared):
            if any(abs(float(declared[index]) - subtotal_values[index]) > 1e-7 for index in range(3)):
                findings.error("E_PRICING_SUBTOTAL_MISMATCH", "Declared subtotal does not equal its explicit component allowlist.", "$.aggregation")
    else:
        findings.error("E_PRICING_AGGREGATION", "Aggregation status must be not_computed or subtotal.", "$.aggregation.status")


def directed_cycle(nodes: set[str], edges: list[tuple[str, str]]) -> bool:
    adjacency: defaultdict[str, list[str]] = defaultdict(list)
    for source, target in edges:
        adjacency[source].append(target)
    visiting: set[str] = set()
    visited: set[str] = set()

    def visit(node: str) -> bool:
        if node in visiting:
            return True
        if node in visited:
            return False
        visiting.add(node)
        for neighbor in adjacency[node]:
            if visit(neighbor):
                return True
        visiting.remove(node)
        visited.add(node)
        return False

    return any(visit(node) for node in nodes if node not in visited)


def validate_diagnosis(diagnosis: dict[str, Any], inspection: dict[str, Any], indexes: dict[str, set[str]], findings: Findings) -> None:
    required = [
        "schema_version", "document_type", "id", "inspection_id", "status", "actors", "issues", "hypotheses",
        "impacts", "verifications", "recommendations", "issue_observation_links", "issue_evidence_links",
        "hypothesis_evidence_links", "verification_issue_links", "verification_hypothesis_links",
        "verification_evidence_links", "recommendation_issue_links", "recommendation_hypothesis_links",
        "recommendation_dependencies", "issue_relations", "qa", "created_at", "updated_at",
    ]
    require_top_level(diagnosis, required, findings, "diagnosis")
    ensure_document_type(diagnosis, "diagnosis", findings)
    inspection_id = inspection.get("id")
    if diagnosis.get("id") != diagnosis.get("inspection_id") or diagnosis.get("inspection_id") != inspection_id:
        findings.error("E_INSPECTION_CONSISTENCY", "diagnosis.id and diagnosis.inspection_id must equal inspection.id.", "$.inspection_id")

    list_names = [
        "issues", "hypotheses", "impacts", "verifications", "recommendations", "issue_observation_links",
        "issue_evidence_links", "hypothesis_evidence_links", "verification_issue_links",
        "verification_hypothesis_links", "verification_evidence_links", "recommendation_issue_links",
        "recommendation_hypothesis_links", "recommendation_dependencies", "issue_relations",
    ]
    collections = {name: diagnosis.get(name) if isinstance(diagnosis.get(name), list) else [] for name in list_names}
    missing_items: list[dict[str, Any]] = []
    for issue in collections["issues"]:
        if isinstance(issue, dict) and isinstance(issue.get("missing_information"), list):
            missing_items.extend(item for item in issue["missing_information"] if isinstance(item, dict))
    duplicate_groups = [(name, items) for name, items in collections.items()]
    duplicate_groups.append(("issues[].missing_information", missing_items))
    check_duplicate_ids(duplicate_groups, findings, "diagnosis document")

    actors_list = diagnosis.get("actors") if isinstance(diagnosis.get("actors"), list) else []
    actor_roles = {item.get("id"): item.get("role") for item in actors_list if isinstance(item, dict) and isinstance(item.get("id"), str)}
    actors = set(actor_roles)
    actor_counts = Counter(item.get("id") for item in actors_list if isinstance(item, dict) and isinstance(item.get("id"), str))
    for actor_id, count in actor_counts.items():
        if count > 1:
            findings.error("E_DUPLICATE_ID", f"Duplicate actor ID '{actor_id}' in diagnosis actors.", "$.actors")

    ids = {
        "issues": {item.get("id") for item in collections["issues"] if isinstance(item, dict) and isinstance(item.get("id"), str)},
        "hypotheses": {item.get("id") for item in collections["hypotheses"] if isinstance(item, dict) and isinstance(item.get("id"), str)},
        "impacts": {item.get("id") for item in collections["impacts"] if isinstance(item, dict) and isinstance(item.get("id"), str)},
        "verifications": {item.get("id") for item in collections["verifications"] if isinstance(item, dict) and isinstance(item.get("id"), str)},
        "recommendations": {item.get("id") for item in collections["recommendations"] if isinstance(item, dict) and isinstance(item.get("id"), str)},
        "missing": {item.get("id") for item in missing_items if isinstance(item.get("id"), str)},
    }

    impacts_by_issue: defaultdict[str, list[dict[str, Any]]] = defaultdict(list)
    for index, impact in enumerate(collections["impacts"]):
        if not isinstance(impact, dict):
            continue
        issue_id = impact.get("diagnostic_issue_id")
        if issue_id not in ids["issues"]:
            findings.error("E_DANGLING_REFERENCE", f"Impact references unknown issue '{issue_id}'.", f"$.impacts[{index}].diagnostic_issue_id")
        else:
            impacts_by_issue[issue_id].append(impact)
        for observation_id in impact.get("supporting_observation_ids", []) if isinstance(impact.get("supporting_observation_ids"), list) else []:
            if observation_id not in indexes["observations"]:
                findings.error("E_DANGLING_REFERENCE", f"Impact references unknown observation '{observation_id}'.", f"$.impacts[{index}].supporting_observation_ids")

    recommendation_types = {item.get("id"): item.get("type") for item in collections["recommendations"] if isinstance(item, dict)}
    recs_by_issue: defaultdict[str, set[str]] = defaultdict(set)
    for link in collections["recommendation_issue_links"]:
        if isinstance(link, dict) and link.get("status") == "active":
            recs_by_issue[link.get("issue_id")].add(link.get("recommendation_id"))

    verifications_by_issue: defaultdict[str, set[str]] = defaultdict(set)
    for link in collections["verification_issue_links"]:
        if isinstance(link, dict) and link.get("status") == "active":
            verifications_by_issue[link.get("issue_id")].add(link.get("verification_id"))

    for index, issue in enumerate(collections["issues"]):
        if not isinstance(issue, dict):
            continue
        issue_id = issue.get("id")
        dimensions = [impact.get("dimension") for impact in impacts_by_issue.get(issue_id, [])]
        if len(dimensions) != 7 or set(dimensions) != IMPACT_DIMENSIONS or len(set(dimensions)) != len(dimensions):
            findings.error(
                "E_IMPACT_DIMENSIONS",
                f"Issue '{issue_id}' must have exactly the seven unique required impact dimensions.",
                f"$.issues[{index}]",
            )
        validate_cost(issue.get("cost_estimate"), findings, f"$.issues[{index}].cost_estimate")

        active_rec_types = {recommendation_types.get(rec_id) for rec_id in recs_by_issue.get(issue_id, set())}
        if issue.get("severity") == "S5" and (issue.get("urgency") != "U1" or issue.get("priority") != "P1"):
            findings.warning("W_S5_PRIORITY", f"S5 issue '{issue_id}' does not use both U1 and P1.", f"$.issues[{index}]")
        if issue.get("short_term_risk", {}).get("level") == "critical" and not active_rec_types.intersection({"IMMEDIATE", "VERIFY"}):
            findings.warning("W_CRITICAL_SHORT_TERM_ACTION", f"Critical short-term risk issue '{issue_id}' lacks an IMMEDIATE/VERIFY recommendation.", f"$.issues[{index}].short_term_risk")
        safety = next((impact for impact in impacts_by_issue.get(issue_id, []) if impact.get("dimension") == "safety"), None)
        if safety and safety.get("level") == "critical" and not active_rec_types.intersection({"IMMEDIATE", "VERIFY"}):
            findings.warning("W_CRITICAL_SAFETY_ACTION", f"Critical safety impact issue '{issue_id}' lacks an IMMEDIATE/VERIFY recommendation.", f"$.issues[{index}]")
        if issue.get("deterioration_rate") == "rapid" and issue.get("urgency") in {"U4", "U5"}:
            findings.warning("W_RAPID_URGENCY", f"Rapid deterioration issue '{issue_id}' uses {issue.get('urgency')}.", f"$.issues[{index}].urgency")
        if issue.get("likelihood") == "L5" and issue.get("likelihood_subject_kind") == "hypothesized_mechanism":
            findings.warning("W_L5_HYPOTHETICAL", f"L5 is applied to a hypothesized mechanism on issue '{issue_id}'.", f"$.issues[{index}].likelihood")
        escalation = issue.get("cost_escalation") if isinstance(issue.get("cost_escalation"), dict) else {}
        if escalation.get("level") in {"high", "critical"} and any(not str(escalation.get(field, "")).strip() for field in ("mechanism", "trigger", "preventive_step")):
            findings.warning("W_COST_ESCALATION_DETAIL", f"High/critical cost escalation on issue '{issue_id}' lacks mechanism, trigger or preventive_step.", f"$.issues[{index}].cost_escalation")
        blocking_missing = [item for item in issue.get("missing_information", []) if isinstance(item, dict) and item.get("blocking") is True]
        if blocking_missing and "VERIFY" not in active_rec_types:
            findings.warning("W_BLOCKING_MISSING_VERIFY", f"Issue '{issue_id}' has blocking missing information without a VERIFY recommendation.", f"$.issues[{index}].missing_information")

    missing_by_id = {item.get("id"): item for item in missing_items if isinstance(item.get("id"), str)}
    contradicting_hypotheses = {
        link.get("hypothesis_id")
        for link in collections["hypothesis_evidence_links"]
        if isinstance(link, dict) and link.get("status") == "active" and link.get("role") == "contradicting"
    }
    for index, hypothesis in enumerate(collections["hypotheses"]):
        if not isinstance(hypothesis, dict):
            continue
        if hypothesis.get("diagnostic_issue_id") not in ids["issues"]:
            findings.error("E_DANGLING_REFERENCE", f"Hypothesis references unknown issue '{hypothesis.get('diagnostic_issue_id')}'.", f"$.hypotheses[{index}].diagnostic_issue_id")
        for missing_id in hypothesis.get("missing_information_ids", []) if isinstance(hypothesis.get("missing_information_ids"), list) else []:
            if missing_id not in missing_by_id:
                findings.error("E_DANGLING_REFERENCE", f"Hypothesis references unknown missing-information item '{missing_id}'.", f"$.hypotheses[{index}].missing_information_ids")
        if hypothesis.get("confidence") == "high" and hypothesis.get("id") in contradicting_hypotheses:
            findings.warning("W_HIGH_CONFIDENCE_CONTRADICTION", f"High-confidence hypothesis '{hypothesis.get('id')}' has active contradicting evidence.", f"$.hypotheses[{index}]")

    for item in missing_items:
        for hypothesis_id in item.get("related_hypothesis_ids", []) if isinstance(item.get("related_hypothesis_ids"), list) else []:
            if hypothesis_id not in ids["hypotheses"]:
                findings.error("E_DANGLING_REFERENCE", f"Missing-information item references unknown hypothesis '{hypothesis_id}'.", "$.issues[].missing_information")

    verification_evidence_results = {
        link.get("verification_id")
        for link in collections["verification_evidence_links"]
        if isinstance(link, dict) and link.get("status") == "active" and link.get("role") in {"result", "supporting", "contradicting"}
    }
    for index, verification in enumerate(collections["verifications"]):
        if not isinstance(verification, dict):
            continue
        check_actor_reference(verification.get("performed_by"), actors, findings, f"$.verifications[{index}].performed_by")
        if verification.get("status") == "completed" and not str(verification.get("result_summary", "")).strip() and verification.get("id") not in verification_evidence_results:
            findings.error("E_VERIFICATION_RESULT", f"Completed verification '{verification.get('id')}' needs result_summary or result evidence link.", f"$.verifications[{index}]")

    for collection_name, fields in {
        "issue_observation_links": (("issue_id", ids["issues"]), ("observation_id", indexes["observations"])),
        "issue_evidence_links": (("issue_id", ids["issues"]), ("evidence_id", indexes["evidence"])),
        "hypothesis_evidence_links": (("hypothesis_id", ids["hypotheses"]), ("evidence_id", indexes["evidence"])),
        "verification_issue_links": (("verification_id", ids["verifications"]), ("issue_id", ids["issues"])),
        "verification_hypothesis_links": (("verification_id", ids["verifications"]), ("hypothesis_id", ids["hypotheses"])),
        "verification_evidence_links": (("verification_id", ids["verifications"]), ("evidence_id", indexes["evidence"])),
        "recommendation_issue_links": (("recommendation_id", ids["recommendations"]), ("issue_id", ids["issues"])),
        "recommendation_hypothesis_links": (("recommendation_id", ids["recommendations"]), ("hypothesis_id", ids["hypotheses"])),
    }.items():
        for index, link in enumerate(collections[collection_name]):
            if not isinstance(link, dict):
                continue
            for field, known_ids in fields:
                if link.get(field) not in known_ids:
                    findings.error("E_DANGLING_REFERENCE", f"{collection_name} references unknown {field} '{link.get(field)}'.", f"$.{collection_name}[{index}].{field}")
            check_actor_reference(link.get("created_by"), actors, findings, f"$.{collection_name}[{index}].created_by")
            if collection_name == "hypothesis_evidence_links" and link.get("role") not in {"supporting", "contradicting"}:
                findings.error("E_LINK_ROLE", "Hypothesis evidence role must be supporting or contradicting.", f"$.{collection_name}[{index}].role")

    edges: list[tuple[str, str]] = []
    for index, dependency in enumerate(collections["recommendation_dependencies"]):
        if not isinstance(dependency, dict):
            continue
        source = dependency.get("from_recommendation_id")
        target = dependency.get("to_recommendation_id")
        if source not in ids["recommendations"] or target not in ids["recommendations"]:
            findings.error("E_DANGLING_REFERENCE", f"Dependency references unknown recommendation '{source}' or '{target}'.", f"$.recommendation_dependencies[{index}]")
        if source == target:
            findings.error("E_DEPENDENCY_SELF", f"Recommendation '{source}' depends on itself.", f"$.recommendation_dependencies[{index}]")
        elif isinstance(source, str) and isinstance(target, str) and dependency.get("status") == "active":
            edges.append((source, target))
        check_actor_reference(dependency.get("created_by"), actors, findings, f"$.recommendation_dependencies[{index}].created_by")
    if directed_cycle(ids["recommendations"], edges):
        findings.error("E_DEPENDENCY_CYCLE", "Active recommendation dependencies contain a directed cycle.", "$.recommendation_dependencies")

    for index, relation in enumerate(collections["issue_relations"]):
        if not isinstance(relation, dict):
            continue
        if relation.get("from_issue_id") not in ids["issues"] or relation.get("to_issue_id") not in ids["issues"]:
            findings.error("E_DANGLING_REFERENCE", "Issue relation references an unknown issue.", f"$.issue_relations[{index}]")
        check_actor_reference(relation.get("created_by"), actors, findings, f"$.issue_relations[{index}].created_by")

    qa = diagnosis.get("qa") if isinstance(diagnosis.get("qa"), dict) else {}
    check_actor_reference(qa.get("checked_by"), actors, findings, "$.qa.checked_by")
    if diagnosis.get("status") == "approved":
        if qa.get("status") != "passed" or not qa.get("checked_by") or not qa.get("checked_at"):
            findings.error("E_DIAGNOSIS_APPROVAL", "Approved diagnosis requires passed QA with checked_by and checked_at.", "$.qa")
        if qa.get("errors_acknowledged"):
            findings.error("E_QA_BLOCKING_ACK", "Blocking errors cannot be acknowledged to approve a diagnosis.", "$.qa.errors_acknowledged")


def has_timezone(value: Any) -> bool:
    return isinstance(value, str) and (value.endswith("Z") or bool(re.search(r"[+-][0-9]{2}:[0-9]{2}$", value)))


def valid_manifest_path(value: Any) -> bool:
    if not isinstance(value, str) or not value:
        return False
    if value.startswith(("/", "\\")) or WINDOWS_ABSOLUTE_RE.match(value) or PUBLIC_URL_RE.match(value):
        return False
    return ".." not in Path(value.replace("\\", "/")).parts


def validate_report_package(package: dict[str, Any], inspection: dict[str, Any], findings: Findings) -> None:
    required = ["schema_version", "document_type", "report", "report_version", "actors", "files", "created_at"]
    require_top_level(package, required, findings, "report package")
    ensure_document_type(package, "report_package", findings)
    report = package.get("report") if isinstance(package.get("report"), dict) else {}
    version = package.get("report_version") if isinstance(package.get("report_version"), dict) else {}
    actors_list = package.get("actors") if isinstance(package.get("actors"), list) else []
    actor_roles = {item.get("id"): item.get("role") for item in actors_list if isinstance(item, dict) and isinstance(item.get("id"), str)}

    if report.get("inspection_id") != inspection.get("id"):
        findings.error("E_INSPECTION_CONSISTENCY", "report.inspection_id must equal inspection.id.", "$.report.inspection_id")
    if version.get("report_id") != report.get("id"):
        findings.error("E_REPORT_REFERENCE", "report_version.report_id must equal report.id.", "$.report_version.report_id")
    if report.get("current_published_version_id") is not None and report.get("current_published_version_id") != version.get("id"):
        findings.error("E_REPORT_REFERENCE", "Manifest report.current_published_version_id must be null or equal this report_version.id.", "$.report.current_published_version_id")

    version_text = version.get("version")
    if not isinstance(version_text, str) or not REPORT_VERSION_RE.fullmatch(version_text):
        findings.error("E_REPORT_VERSION", "Report version must match major.minor.", "$.report_version.version")

    status = version.get("status")
    if status in {"approved", "published", "superseded"}:
        if not version.get("approved_by") or not version.get("approved_at"):
            findings.error("E_REPORT_APPROVAL", f"{status} report version requires approved_by and approved_at.", "$.report_version")
        elif actor_roles.get(version.get("approved_by")) not in {"inspector", "reviewer"}:
            findings.error("E_REPORT_APPROVER_ROLE", "approved_by must reference an inspector or reviewer actor.", "$.report_version.approved_by")
        if version.get("approved_at") and not has_timezone(version.get("approved_at")):
            findings.error("E_DATETIME_TIMEZONE", "approved_at must include a timezone.", "$.report_version.approved_at")
    if status == "published":
        if not version.get("published_at"):
            findings.error("E_REPORT_APPROVAL", "Published report version requires published_at.", "$.report_version.published_at")
        elif not has_timezone(version.get("published_at")):
            findings.error("E_DATETIME_TIMEZONE", "published_at must include a timezone.", "$.report_version.published_at")

    files = package.get("files") if isinstance(package.get("files"), list) else []
    paths: defaultdict[str, list[int]] = defaultdict(list)
    hashes: defaultdict[str, list[int]] = defaultdict(list)
    role_counts: Counter[str] = Counter()
    allowed_roles = {"inspection_data", "diagnosis_data", "report_pricing", "media", "attachment", "source_report", "other"}
    for index, item in enumerate(files):
        if not isinstance(item, dict):
            continue
        role = item.get("role")
        if role not in allowed_roles:
            findings.error("E_MANIFEST_ROLE", f"Unsupported manifest role '{role}'.", f"$.files[{index}].role")
        elif isinstance(role, str):
            role_counts[role] += 1
        if role == "report_pricing" and (item.get("content_type") != "application/json" or item.get("privacy") not in {"client_private", "internal"}):
            findings.error(
                "E_MANIFEST_ROLE",
                "report_pricing must be private/internal application/json and is never a directly public file.",
                f"$.files[{index}]",
            )
        path = item.get("path")
        digest = item.get("sha256")
        if isinstance(path, str):
            paths[path].append(index)
        if isinstance(digest, str):
            hashes[digest].append(index)
        if not valid_manifest_path(path):
            findings.error("E_MANIFEST_PATH", "Manifest path must be a relative internal path without '..' or URL scheme.", f"$.files[{index}].path")
        if not isinstance(digest, str) or not SHA256_RE.fullmatch(digest):
            findings.error("E_MANIFEST_SHA256", "Manifest sha256 must be 64 lowercase hexadecimal characters.", f"$.files[{index}].sha256")
        if item.get("privacy") in {"client_private", "internal"} and isinstance(path, str) and PUBLIC_URL_RE.match(path):
            findings.error("E_PRIVATE_PUBLIC_URL", "Private manifest file must not use an http(s) path.", f"$.files[{index}].path")
    for path, indexes_for_path in paths.items():
        if len(indexes_for_path) > 1:
            findings.error("E_MANIFEST_DUPLICATE_PATH", f"Manifest path '{path}' is duplicated.", f"$.files[{indexes_for_path[1]}].path")
    for digest, indexes_for_hash in hashes.items():
        if digest and len(indexes_for_hash) > 1:
            findings.error("E_MANIFEST_DUPLICATE_HASH", f"Manifest sha256 '{digest}' is duplicated across file entries.", f"$.files[{indexes_for_hash[1]}].sha256")
    if role_counts["inspection_data"] != 1 or role_counts["diagnosis_data"] != 1:
        findings.error("E_MANIFEST_ROLE", "Manifest must contain exactly one inspection_data and one diagnosis_data file.", "$.files")
    if role_counts["report_pricing"] > 1:
        findings.error("E_MANIFEST_ROLE", "Manifest may contain at most one report_pricing file.", "$.files")


def run(
    inspection_path: Path,
    diagnosis_path: Path | None = None,
    report_path: Path | None = None,
    pricing_path: Path | None = None,
) -> tuple[Findings, dict[str, str]]:
    inspection = load_json(inspection_path)
    diagnosis = load_json(diagnosis_path) if diagnosis_path else None
    report = load_json(report_path) if report_path else None
    pricing = load_json(pricing_path) if pricing_path else None
    findings = Findings()
    indexes = validate_inspection(inspection, findings)
    if diagnosis is not None:
        validate_diagnosis(diagnosis, inspection, indexes, findings)
    if report is not None:
        validate_report_package(report, inspection, findings)
    if pricing is not None:
        validate_report_pricing(pricing, inspection, diagnosis, findings)
        if report is not None:
            report_obj = report.get("report") if isinstance(report.get("report"), dict) else {}
            version_obj = report.get("report_version") if isinstance(report.get("report_version"), dict) else {}
            if pricing.get("report_id") != report_obj.get("id") or pricing.get("report_version_id") != version_obj.get("id"):
                findings.error(
                    "E_REPORT_PRICING_OWNERSHIP",
                    "report pricing must be owned by the same report and report version as its package.",
                    "$.report_id",
                )
    inputs = {"inspection": str(inspection_path)}
    if diagnosis_path:
        inputs["diagnosis"] = str(diagnosis_path)
    if report_path:
        inputs["report_package"] = str(report_path)
    if pricing_path:
        inputs["report_pricing"] = str(pricing_path)
    return findings, inputs


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--inspection", required=True, type=Path, help="Path to inspection.json")
    parser.add_argument("--diagnosis", type=Path, help="Optional path to diagnosis.json")
    parser.add_argument("--report-package", type=Path, help="Optional path to report package manifest")
    parser.add_argument("--report-pricing", type=Path, help="Optional path to report-pricing.json")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        findings, inputs = run(args.inspection, args.diagnosis, args.report_package, args.report_pricing)
    except ValueError as exc:
        print(json.dumps({"tool_error": {"code": "E_TOOL_INPUT", "message": str(exc)}}, ensure_ascii=False, indent=2))
        return 2
    result = {"inputs": inputs, "errors": findings.errors, "warnings": findings.warnings}
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 1 if findings.errors else 0


if __name__ == "__main__":
    sys.exit(main())
