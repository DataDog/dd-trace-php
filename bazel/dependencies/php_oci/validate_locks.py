#!/usr/bin/env python3
"""Validate checked-in PHP OCI metadata ancestry without downloading layers."""

from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--lock", type=Path, required=True)
    parser.add_argument("--metadata-root", type=Path, required=True)
    parser.add_argument("--extractor", type=Path, required=True)
    args = parser.parse_args()

    spec = importlib.util.spec_from_file_location("php_oci_extract_profile", args.extractor)
    if spec is None or spec.loader is None:
        raise SystemExit(f"cannot load extractor module: {args.extractor}")
    extractor = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(extractor)

    lock = json.loads(args.lock.read_text(encoding="utf-8"))
    if lock.get("schema_version") != 1:
        raise SystemExit(f"unsupported lock schema: {lock.get('schema_version')!r}")
    imports = lock.get("imports", [])
    aliases = lock.get("compatible_aliases", [])
    unavailable = lock.get("unavailable_imports", [])
    expected = lock.get("expected_matrix_records")
    if len(imports) + len(aliases) + len(unavailable) != expected:
        raise SystemExit(
            f"matrix cardinality mismatch: {len(imports)} imports + {len(aliases)} aliases + "
            f"{len(unavailable)} unavailable != {expected}"
        )
    names = [record.get("repo_name") for record in imports]
    if any(not name for name in names) or len(set(names)) != len(names):
        raise SystemExit("PHP OCI import repository names are empty or duplicated")
    for alias in aliases:
        if alias.get("compatible_sdk_repo") not in names:
            raise SystemExit(f"compatible alias references an unknown SDK: {alias!r}")

    referenced_metadata: set[Path] = set()
    for record in imports:
        metadata = record["metadata"]
        paths = {
            kind: args.metadata_root / metadata[kind]
            for kind in ("index", "manifest", "config")
        }
        referenced_metadata.update(paths.values())
        extractor.verify_oci_ancestry(
            record,
            paths["index"],
            paths["manifest"],
            paths["config"],
        )
    checked_in = set((args.metadata_root / "metadata").glob("*.json"))
    if referenced_metadata != checked_in:
        missing = sorted(str(path) for path in referenced_metadata - checked_in)
        stale = sorted(str(path) for path in checked_in - referenced_metadata)
        raise SystemExit(f"metadata closure differs from checked-in files; missing={missing}, stale={stale}")
    print(
        f"validated {len(imports)} imports, {len(aliases)} compatible alias, "
        f"{len(unavailable)} unavailable records, and {len(checked_in)} OCI metadata documents"
    )


if __name__ == "__main__":
    main()
