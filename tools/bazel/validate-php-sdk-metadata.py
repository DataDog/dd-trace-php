#!/usr/bin/env python3
"""Validate generated PHP SDK records against imported OCI observations."""

import json
import pathlib
import sys


def _boolean(value: str) -> bool:
    if value == "true":
        return True
    if value == "false":
        return False
    raise ValueError(f"invalid boolean: {value}")


def validate(argv: list[str]) -> None:
    if len(argv) != 16:
        raise ValueError(f"expected 15 arguments, received {len(argv) - 1}")
    observed = json.loads(pathlib.Path(argv[1]).read_text(encoding="utf-8"))
    lock = json.loads(pathlib.Path(argv[2]).read_text(encoding="utf-8"))
    expected_observed = {
        "php_version": argv[3],
        "php_api": int(argv[4]),
        "zend_module_api": int(argv[5]),
        "zend_extension_api": int(argv[6]),
        "arch": argv[7],
        "debug": _boolean(argv[8]),
        "zts": _boolean(argv[9]),
        "asan": _boolean(argv[10]),
        "content_mode": "headers",
    }
    expected_lock = {
        "image_family": argv[11],
        "abi_profile": argv[12],
        "index_digest": argv[13],
        "target_libc": argv[14],
        "target_triple": argv[15],
    }
    mismatches = []
    for source_name, actual, expected in (
        ("observed", observed, expected_observed),
        ("lock", lock, expected_lock),
    ):
        for field, expected_value in expected.items():
            actual_value = actual.get(field)
            if type(actual_value) is not type(expected_value) or actual_value != expected_value:
                mismatches.append(
                    f"{source_name}.{field}: expected {expected_value!r}, got {actual_value!r}"
                )
    if mismatches:
        raise ValueError("PHP SDK metadata mismatch:\n" + "\n".join(mismatches))


if __name__ == "__main__":
    try:
        validate(sys.argv)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(error, file=sys.stderr)
        raise SystemExit(1) from error
