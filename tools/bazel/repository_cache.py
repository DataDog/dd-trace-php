#!/usr/bin/env python3
"""Move checksum-verified Bazel repository downloads outside the checkout."""

import argparse
import hashlib
import os
from pathlib import Path
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


def copy_verified(source, destination):
    destination.mkdir(parents=True, exist_ok=True)
    for path in verified_payloads(source):
        target = destination / path.relative_to(source)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, target)
        canonical = path.parent / "canonical_id"
        if canonical.is_file() and not canonical.is_symlink():
            shutil.copyfile(canonical, target.parent / "canonical_id")
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
