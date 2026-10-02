#!/usr/bin/env python3
"""Checks a product build's reported artifact identities against PHP_MATRIX."""

import json
import os
import sys


def _expected_product_bindings(rows, role_field):
    result = {}
    for row in rows:
        for product, status in row["products"].items():
            if status["supported"]:
                for role, identity in row[role_field][product].items():
                    binding = (product, row["product_build_keys"][product], role)
                    existing = result.get(identity)
                    if existing is not None and existing != binding:
                        raise ValueError("identity has conflicting declared bindings: %s" % identity)
                    result[identity] = binding
    return result


def _expected_runtime_bindings(rows, role_field):
    result = {}
    for row in rows:
        for role, identity in row[role_field].items():
            binding = ("asan_runtime", row["asan_runtime_scope_key"], role)
            existing = result.get(identity)
            if existing is not None and existing != binding:
                raise ValueError("runtime identity has conflicting declared bindings: %s" % identity)
            result[identity] = binding
    return result


def _reported(records, name, key, expected, allow_binding_reuse):
    if not isinstance(records, list):
        raise ValueError("built manifest %s must be a list of records" % name)
    reported = {}
    bindings = {}
    for record in records:
        if not isinstance(record, dict):
            raise ValueError("%s contains a non-record" % name)
        identity = record.get(key)
        path = record.get("path")
        if not isinstance(identity, str) or not isinstance(path, str):
            raise ValueError("%s records require string %s and path" % (name, key))
        if identity in reported:
            raise ValueError("%s duplicates %s: %s" % (name, key, identity))
        product = record.get("product")
        scope_key = record.get("scope_key")
        role = record.get("role")
        if not all(isinstance(value, str) for value in (product, scope_key, role)):
            raise ValueError("%s records require product, scope_key, and role" % name)
        if not os.path.isfile(path):
            raise ValueError("%s maps %s to a missing file: %s" % (name, identity, path))
        binding = (product, scope_key, role)
        if identity not in expected:
            raise ValueError("%s has an unexpected identity: %s" % (name, identity))
        if expected[identity] != binding:
            raise ValueError("%s binding differs for %s: got=%s expected=%s" % (name, identity, binding, expected[identity]))
        if not allow_binding_reuse and binding in bindings:
            raise ValueError("%s duplicates role binding: %s" % (name, binding))
        reported[identity] = path
        bindings[binding] = identity
    return set(reported)


def main(argv):
    if len(argv) != 3:
        raise ValueError("usage: validate_product_manifest.py DECLARED.json BUILT.json")
    with open(argv[1], encoding="utf-8") as source:
        declared = json.load(source)
    with open(argv[2], encoding="utf-8") as source:
        built = json.load(source)

    if declared.get("schema_version") != 1 or built.get("schema_version") != 1:
        raise ValueError("both manifests must use schema_version 1")
    rows = declared.get("rows")
    if not isinstance(rows, list):
        raise ValueError("declared manifest has no rows list")

    checks = (
        ("build_artifact_roles", "build_artifacts", "identity", _expected_product_bindings, False),
        ("validation_bundle_artifact_roles", "validation_bundle_artifacts", "destination", _expected_product_bindings, True),
        ("asan_runtime_build_artifact_roles", "asan_runtime_build_artifacts", "identity", _expected_runtime_bindings, False),
        ("asan_runtime_validation_artifact_roles", "asan_runtime_validation_artifacts", "destination", _expected_runtime_bindings, True),
    )
    for role_field, built_field, key, expected_fn, allow_binding_reuse in checks:
        expected = expected_fn(rows, role_field)
        reported = _reported(built.get(built_field), built_field, key, expected, allow_binding_reuse)
        if set(expected) != reported:
            missing = sorted(set(expected) - reported)
            unexpected = sorted(reported - set(expected))
            raise ValueError(
                "%s differs; missing=%s unexpected=%s" % (
                    built_field,
                    missing,
                    unexpected,
                ),
            )

if __name__ == "__main__":
    try:
        main(sys.argv)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print("product-manifest validation failed: %s" % error, file=sys.stderr)
        sys.exit(1)
