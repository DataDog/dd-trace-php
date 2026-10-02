#!/usr/bin/env python3
"""Move checksum-verified Bazel repository downloads outside the checkout."""

import argparse
import hashlib
import os
from pathlib import Path
import re
import shutil
import sys


def digest(path):
    sha = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            sha.update(chunk)
    return sha.hexdigest()


def verified_payloads(root):
    if not root.exists():
        return []
    payloads = list(root.glob("content_addressable/sha256/*/file"))
    for path in payloads:
        expected = path.parent.name
        if len(expected) != 64 or any(c not in "0123456789abcdef" for c in expected):
            raise ValueError("Invalid repository cache key: " + str(path))
        if path.is_symlink() or digest(path) != expected:
            raise ValueError("Corrupt repository download: " + str(path))
    return payloads


def cache_metadata(payload):
    """Return the canonical-ID markers Bazel needs to reuse a cached archive."""
    entry = payload.parent
    metadata = []
    for marker in entry.glob("id-*"):
        if (not re.fullmatch(r"id-[0-9a-f]{64}", marker.name)
                or not marker.is_file() or marker.is_symlink() or marker.stat().st_size):
            raise ValueError("Invalid repository cache canonical ID marker: " + str(marker))
        metadata.append(marker)
    legacy = entry / "canonical_id"
    if legacy.is_file() and not legacy.is_symlink():
        metadata.append(legacy)
    return metadata


def copy_entry(payload, target):
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(payload, target)
    for metadata in cache_metadata(payload):
        shutil.copyfile(metadata, target.parent / metadata.name)


def copy_verified(source, destination):
    destination.mkdir(parents=True, exist_ok=True)
    for path in verified_payloads(source):
        target = destination / path.relative_to(source)
        copy_entry(path, target)
    verified_payloads(destination)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("direction", choices=("restore", "save"))
    args = parser.parse_args()
    checkout = Path(".cache/bazel/repository")
    runtime = Path(os.environ["BAZEL_REPOSITORY_CACHE"])
    if runtime.resolve().is_relative_to(Path.cwd().resolve()):
        parser.error("repository cache must be outside the checkout")
    try:
        copy_verified(checkout if args.direction == "restore" else runtime,
                      runtime if args.direction == "restore" else checkout)
    except ValueError as error:
        print(error, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
