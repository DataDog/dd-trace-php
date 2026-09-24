#!/usr/bin/env python3
"""Prefetch and verify the repository downloads for a manually published CI image."""

import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess

import ci
import repository_cache


IMAGE_ROOT = Path("/opt/dd-php-bazel")


def bazel_version():
    return (ci.ROOT / ".bazelversion").read_text().splitlines()[-1]


def targets(arch):
    return (["//bazel/stages:remote_arch_" + arch]
            + ci.comparison_targets(arch)
            + ["//bazel/products/tracer:ddtrace_fat_" + arch + "_remaining"])


def commands(arch, offline):
    steps = [("build", targets(arch))]
    if arch == "amd64":
        steps.append(("test", ["//bazel/tests:php_tests"]))
    for verb, labels in steps:
        record = ci.state_directory() / ("deps-image-" + verb)
        record.mkdir(parents=True, exist_ok=True)
        command = ci.bazel_command(record, "image", arch, labels, verb)
        # --nobuild performs repository and toolchain resolution without
        # executing the 55-product release build in this maintenance job.
        command.insert(command.index(verb) + 1, "--nobuild")
        if offline:
            command.insert(command.index(verb) + 1, "--repository_disable_download")
        yield command


def prefetch(arch, context):
    os.chdir(ci.ROOT)
    source = Path(os.environ["BAZEL_REPOSITORY_CACHE"])
    if source.resolve().is_relative_to(ci.ROOT) or context.resolve().is_relative_to(source.resolve()):
        raise ValueError("Repository cache and image context must be separate")
    if context.exists():
        raise ValueError("Image context already exists: " + str(context))
    for offline in (False, True):
        for command in commands(arch, offline):
            subprocess.run(command, check=True)
    destination = context / "repository"
    repository_cache.copy_verified(source, destination)
    # copy_verified already checked both sides; avoid hashing a large image
    # layer for a third time just to count its files.
    payloads = list(destination.glob("content_addressable/sha256/*/file"))
    if not payloads:
        raise ValueError("No checksum-verified repository downloads were prefetched")
    bazel = Path(os.environ["BAZEL_BINARY"])
    shutil.copyfile(bazel, context / "bazel")
    (context / "bazel").chmod(0o755)
    shutil.copyfile(ci.ROOT / "tools/bazel/deps-image.Dockerfile", context / "Dockerfile")
    manifest = dict(arch=arch, bazel_version=bazel_version(),
                    repository_files=len(payloads), repository_bytes=sum(path.stat().st_size for path in payloads))
    (context / "manifest.json").write_text(json.dumps(manifest, sort_keys=True) + "\n")
    print(json.dumps(manifest, sort_keys=True), flush=True)


def verify(arch):
    manifest = json.loads((IMAGE_ROOT / "manifest.json").read_text())
    if manifest["arch"] != arch:
        raise ValueError("Dependency image architecture differs from Bazel lane")
    if manifest["bazel_version"] != bazel_version():
        raise ValueError("Dependency image Bazel version differs from checkout")
    if manifest["repository_files"] <= 0 or not (IMAGE_ROOT / "repository/content_addressable/sha256").is_dir():
        raise ValueError("Dependency image repository cache is missing")
    actual = subprocess.check_output([str(IMAGE_ROOT / "bazel"), "--version"], text=True).strip()
    if actual != "bazel " + manifest["bazel_version"]:
        raise ValueError("Dependency image Bazel binary version differs from manifest")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("prefetch", "verify"))
    parser.add_argument("--arch", choices=("amd64", "arm64"), required=True)
    parser.add_argument("--context", type=Path)
    args = parser.parse_args()
    if args.mode == "prefetch":
        if args.context is None:
            parser.error("prefetch requires --context")
        prefetch(args.arch, args.context.resolve())
    else:
        verify(args.arch)


if __name__ == "__main__":
    main()
