#!/usr/bin/env python3
"""Generate immutable OCI metadata and PHP SDK profile import locks.

This is a repository-maintenance tool. Bazel never runs it: repository setup
consumes the checked-in, digest-verified JSON documents and downloads only a
selected profile layer when that SDK is requested.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path


IMAGE = "datadog/dd-trace-ci"
REGISTRY = "registry-1.docker.io"
REPOSITORY = "datadog/dd-trace-ci"
AUTH = "https://auth.docker.io/token?service=registry.docker.io&scope=repository:datadog/dd-trace-ci:pull"
ARCHES = ("amd64", "arm64")
API = {
    "7.0": 20151012,
    "7.1": 20160303,
    "7.2": 20170718,
    "7.3": 20180731,
    "7.4": 20190902,
    "8.0": 20200930,
    "8.1": 20210902,
    "8.2": 20220829,
    "8.3": 20230831,
    "8.4": 20240924,
    "8.5": 20250925,
}
SOURCE_VERSION = {
    "7.0": "7.0.33",
    "7.1": "7.1.33",
    "7.2": "7.2.34",
    "7.3": "7.3.33",
    "7.4": "7.4.33",
    "8.0": "8.0.30",
    "8.1": "8.1.32",
    "8.2": "8.2.31",
    "8.3": "8.3.31",
    "8.4": "8.4.22",
    "8.5": "8.5.7",
}
BOOKWORM_VERSION = {**SOURCE_VERSION, "8.5": "8.5.8RC1"}
ALPINE_SOURCE_VERSION = {**SOURCE_VERSION, "8.1": "8.1.31"}
KNOWN_CURRENT_CENTOS_VERSION = {
    **SOURCE_VERSION,
    "8.2": "8.2.33",
    "8.3": "8.3.33",
    "8.4": "8.4.25",
    "8.5": "8.5.10",
}
KNOWN_CURRENT_ALPINE_VERSION = {
    **ALPINE_SOURCE_VERSION,
    "8.2": "8.2.33",
    "8.3": "8.3.33",
    "8.4": "8.4.25",
    "8.5": "8.5.10",
}


@dataclass(frozen=True)
class Family:
    name: str
    tag: str
    minors: tuple[str, ...]
    profiles: callable
    declared_versions: dict[str, str]
    known_versions: dict[str, str]
    libc: str
    image_base: str
    filesystem_semantics: str = "profile_copy_snapshot"
    shared_build: bool = False


def bookworm_profiles(minor: str) -> tuple[str, ...]:
    if minor in ("7.0", "7.1", "7.2", "7.3"):
        return ("debug-zts", "debug", "nts", "zts")
    if minor in ("7.4", "8.0", "8.1", "8.2"):
        return ("debug-zts-asan", "debug", "nts", "zts")
    return ("debug-zts-asan", "debug", "nts-asan", "nts", "zts")


def fixed_profiles(*profiles: str):
    return lambda _minor: profiles


FAMILIES = (
    Family(
        "bookworm",
        "php-{minor}_bookworm-10",
        tuple(API),
        bookworm_profiles,
        BOOKWORM_VERSION,
        BOOKWORM_VERSION,
        "glibc",
        "debian:bookworm",
    ),
    Family(
        "bookworm_shared",
        "php-{minor}-shared-ext-10",
        ("7.4", "8.0"),
        fixed_profiles("debug-zts-asan", "debug", "nts", "zts"),
        BOOKWORM_VERSION,
        BOOKWORM_VERSION,
        "glibc",
        "debian:bookworm",
        shared_build=True,
    ),
    Family(
        "centos7",
        "php-{minor}_centos-7",
        tuple(API),
        fixed_profiles("zts", "debug", "nts"),
        SOURCE_VERSION,
        KNOWN_CURRENT_CENTOS_VERSION,
        "glibc",
        "centos:7",
    ),
    Family(
        "alpine322",
        "php-compile-extension-alpine-{minor}",
        tuple(API),
        fixed_profiles("nts", "zts"),
        ALPINE_SOURCE_VERSION,
        KNOWN_CURRENT_ALPINE_VERSION,
        "musl",
        "alpine:3.23.5",
        filesystem_semantics="profile_layer_snapshot",
    ),
)


def run(crane: Path, *arguments: str) -> bytes:
    return subprocess.run(
        [str(crane), *arguments],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    ).stdout


def digest_bytes(data: bytes) -> str:
    return "sha256:" + hashlib.sha256(data).hexdigest()


def decode(data: bytes, what: str) -> dict:
    try:
        return json.loads(data)
    except (json.JSONDecodeError, UnicodeDecodeError) as error:
        raise SystemExit(f"invalid {what} JSON: {error}") from error


def write_metadata(directory: Path, digest: str, suffix: str, data: bytes) -> str:
    if digest_bytes(data) != digest:
        raise SystemExit(f"{suffix} bytes do not match registry digest {digest}")
    relative = Path("metadata") / f"{digest[7:]}.{suffix}.json"
    destination = directory / relative.name
    if destination.exists() and destination.read_bytes() != data:
        raise SystemExit(f"metadata collision at {destination}")
    destination.write_bytes(data)
    return relative.as_posix()


def cached_metadata(directory: Path, digest: str, suffixes: tuple[str, ...]) -> bytes | None:
    for suffix in suffixes:
        path = directory / f"{digest[7:]}.{suffix}.json"
        if path.exists():
            data = path.read_bytes()
            if digest_bytes(data) != digest:
                raise SystemExit(f"cached metadata does not match {digest}: {path}")
            return data
    return None


def nonempty_history(config: dict) -> list[dict]:
    return [entry for entry in config.get("history", []) if not entry.get("empty_layer", False)]


def exact_version(config: dict, fallback: str) -> str:
    candidates: set[str] = set()
    for entry in config.get("history", []):
        command = entry.get("created_by", "")
        candidates.update(re.findall(r"php(?:Version|TarGzUrl)=.*?php-?([0-9]+\.[0-9]+\.[0-9]+(?:RC[0-9]+)?)", command))
        candidates.update(re.findall(r"phpVersion=([0-9]+\.[0-9]+\.[0-9]+(?:RC[0-9]+)?)", command))
    for value in config.get("config", {}).get("Env", []):
        if value.startswith("PHP_VERSION=") and value.count(".") >= 2:
            candidates.add(value.split("=", 1)[1])
    if not candidates:
        return fallback
    if len(candidates) != 1:
        raise SystemExit(f"ambiguous PHP versions in OCI config: {sorted(candidates)}")
    return candidates.pop()


def version_id(version: str) -> int:
    match = re.fullmatch(r"([0-9]+)\.([0-9]+)\.([0-9]+)(?:RC[0-9]+)?", version)
    if not match:
        raise SystemExit(f"unsupported PHP version syntax: {version}")
    major, minor, patch = map(int, match.groups())
    return major * 10000 + minor * 100 + patch


def profile_flags(profile: str) -> tuple[bool, bool, bool]:
    return profile.startswith("debug"), "zts" in profile, "asan" in profile


def target_triple(arch: str, libc: str) -> str:
    cpu = "x86_64" if arch == "amd64" else "aarch64"
    suffix = "gnu" if libc == "glibc" else "musl"
    return f"{cpu}-unknown-linux-{suffix}"


def zend_extension_api(minor: str) -> int:
    engine_generation = 3 if minor.startswith("7.") else 4
    return engine_generation * 100000000 + API[minor]


def source_prefix(family: Family, minor: str, version: str, profile: str) -> str:
    if family.name.startswith("bookworm"):
        return f"opt/php/{profile}"
    if family.name == "centos7":
        suffix = {"nts": "", "zts": "-zts", "debug": "-debug"}[profile]
        return f"opt/php/{minor}{suffix}"
    if family.name == "alpine322":
        suffix = "" if profile == "nts" else "-zts"
        return f"usr/local/php-{version}{suffix}"
    if family.name == "alpine_legacy":
        suffix = {"nts": "", "debug": "-debug", "debug-zts": "-debug-zts"}[profile]
        return f"opt/php/{minor}{suffix}"
    raise AssertionError(family.name)


def find_profile_layer(manifest: dict, config: dict, prefix: str, semantics: str) -> tuple[dict, str]:
    layers = manifest.get("layers", [])
    history = nonempty_history(config)
    if len(layers) != len(history):
        raise SystemExit("manifest layers and nonempty config history lengths differ")
    matches: list[tuple[dict, str]] = []
    for descriptor, entry in zip(layers, history):
        created_by = entry.get("created_by", "")
        if semantics == "profile_copy_snapshot":
            normalized_prefix = "/" + prefix
            matched = "COPY" in created_by and created_by.split().count(normalized_prefix) >= 2
        else:
            matched = "/bin/sh -c install-php" in created_by
        if matched:
            matches.append((descriptor, created_by))
    if len(matches) != 1:
        raise SystemExit(f"expected one immutable profile layer for {prefix}, found {len(matches)}")
    return matches[0]


def platform_manifests(index: dict, image_digest: str) -> dict[str, str]:
    if "manifests" not in index:
        return {"amd64": image_digest}
    result = {}
    for descriptor in index["manifests"]:
        platform = descriptor.get("platform", {})
        arch = platform.get("architecture")
        if platform.get("os") == "linux" and arch in ARCHES:
            if arch in result:
                raise SystemExit(f"duplicate linux/{arch} manifest")
            result[arch] = descriptor["digest"]
    return result


def fetch_image(crane: Path, metadata_dir: Path, tag: str) -> tuple[str, str, str, dict, dict[str, tuple[str, dict, str, dict]]]:
    reference = f"{IMAGE}:{tag}"
    image_digest = run(crane, "digest", reference).decode().strip()
    image_bytes = cached_metadata(metadata_dir, image_digest, ("index", "manifest"))
    if image_bytes is None:
        image_bytes = run(crane, "manifest", f"{IMAGE}@{image_digest}")
    if digest_bytes(image_bytes) != image_digest:
        raise SystemExit(f"registry returned noncanonical image metadata for {reference}")
    image_json = decode(image_bytes, f"{reference} image")
    descriptor_kind = "index" if "manifests" in image_json else "manifest"
    image_path = write_metadata(metadata_dir, image_digest, descriptor_kind, image_bytes)
    platforms = {}
    for arch, manifest_digest in sorted(platform_manifests(image_json, image_digest).items()):
        manifest_bytes = image_bytes if manifest_digest == image_digest else cached_metadata(metadata_dir, manifest_digest, ("manifest",))
        if manifest_bytes is None:
            manifest_bytes = run(crane, "manifest", f"{IMAGE}@{manifest_digest}")
        manifest_path = write_metadata(metadata_dir, manifest_digest, "manifest", manifest_bytes)
        manifest = decode(manifest_bytes, f"{reference} linux/{arch} manifest")
        config_digest = manifest.get("config", {}).get("digest", "")
        config_bytes = cached_metadata(metadata_dir, config_digest, ("config",))
        if config_bytes is None:
            config_bytes = run(crane, "config", f"{IMAGE}@{manifest_digest}")
        config_path = write_metadata(metadata_dir, config_digest, "config", config_bytes)
        config = decode(config_bytes, f"{reference} linux/{arch} config")
        if config.get("architecture") != arch or config.get("os") != "linux":
            raise SystemExit(f"config platform mismatch for {reference} linux/{arch}")
        platforms[arch] = (manifest_digest, manifest, config_path, config)
    return image_digest, descriptor_kind, image_path, image_json, platforms


def record(
    family: Family,
    minor: str,
    profile: str,
    arch: str,
    tag: str,
    image_digest: str,
    descriptor_kind: str,
    image_path: str,
    manifest_digest: str,
    manifest: dict,
    config_path: str,
    config: dict,
) -> dict:
    version = exact_version(config, family.known_versions[minor])
    if not version.startswith(minor + "."):
        raise SystemExit(f"{tag} embeds PHP {version}, outside required minor {minor}")
    prefix = source_prefix(family, minor, version, profile)
    layer, history = find_profile_layer(manifest, config, prefix, family.filesystem_semantics)
    config_digest = manifest["config"]["digest"]
    manifest_path = f"metadata/{manifest_digest[7:]}.manifest.json"
    debug, zts, asan = profile_flags(profile)
    minor_key = minor.replace(".", "_")
    repo_name = f"php_sdk_{family.name}_{minor_key}_{arch}_{profile.replace('-', '_')}"
    return {
        "repo_name": repo_name,
        "image_family": family.name,
        "minor": minor,
        "abi_profile": profile,
        "shared_build": family.shared_build,
        "declared_source_version": family.declared_versions[minor],
        "observed_image_version": version,
        "image_base": family.image_base,
        "target_libc": family.libc,
        "target_triple": target_triple(arch, family.libc),
        "filesystem_semantics": family.filesystem_semantics,
        "image_descriptor_kind": descriptor_kind,
        "registry": REGISTRY,
        "repository": REPOSITORY,
        "source_tag": tag,
        "index_digest": image_digest,
        "manifest_digest": manifest_digest,
        "config_digest": config_digest,
        "metadata": {"index": image_path, "manifest": manifest_path, "config": config_path},
        "expected_history_created_by": history,
        "platform": {"os": "linux", "arch": arch},
        "source_prefix": prefix,
        "content_mode": "headers",
        "max_unpacked_size": 134217728,
        "anonymous_auth": {"token_url": AUTH},
        "expected": {
            "php_version": version,
            "php_version_id": version_id(version),
            "php_api": API[minor],
            "zend_module_api": API[minor],
            "zend_extension_api": zend_extension_api(minor),
            "arch": arch,
            "debug": debug,
            "zts": zts,
            "asan": asan,
            "required_extensions": [],
        },
        "layers": [{
            "digest": layer["digest"],
            "size": layer["size"],
            "media_type": layer["mediaType"],
        }],
    }


def legacy_records(crane: Path, metadata_dir: Path) -> tuple[list[dict], list[dict], list[dict]]:
    tag = "php-8.0_alpine"
    image_digest, kind, image_path, _, platforms = fetch_image(crane, metadata_dir, tag)
    unavailable = []
    compatible_aliases = []
    if "arm64" not in platforms:
        for profile in ("debug-zts", "debug", "nts"):
            missing = {
                "image_family": "alpine_legacy",
                "minor": "8.0",
                "abi_profile": profile,
                "arch": "arm64",
                "reason": "the immutable public image descriptor contains only linux/amd64",
                "source_tag": tag,
                "index_digest": image_digest,
            }
            if profile == "nts":
                missing.update({
                    "compatible_sdk_repo": "php_sdk_alpine322_8_0_arm64_nts",
                    "reason": "the legacy image is amd64-only; reuse the locked arm64 musl PHP 8.0 NTS SDK with the same PHP API",
                })
                compatible_aliases.append(missing)
            else:
                unavailable.append(missing)
    family = Family(
        "alpine_legacy",
        tag,
        ("8.0",),
        fixed_profiles("debug-zts", "debug", "nts"),
        {"8.0": "8.0.15"},
        {"8.0": "8.0.15"},
        "musl",
        "alpine (exact release not encoded in OCI config)",
    )
    imports = []
    for arch, (manifest_digest, manifest, config_path, config) in sorted(platforms.items()):
        for profile in family.profiles("8.0"):
            imports.append(record(
                family, "8.0", profile, arch, tag, image_digest, kind, image_path,
                manifest_digest, manifest, config_path, config,
            ))
    return imports, compatible_aliases, unavailable


def quoted(value: str) -> str:
    return json.dumps(value, ensure_ascii=True)


def matrix_key(record: dict) -> str:
    arch = record.get("arch", record.get("platform", {}).get("arch"))
    return "(%s, %s, %s, %s)" % (
        quoted(record["image_family"]),
        quoted(record["minor"]),
        quoted(arch),
        quoted(record["abi_profile"]),
    )


def write_records_bzl(path: Path, lock: dict) -> None:
    lines = [
        '"""Generated from images.json by generate_locks.py; do not edit."""',
        "",
        "PHP_OCI_IMPORTS = {",
    ]
    for record in lock["imports"]:
        expected = record["expected"]
        layer = record["layers"][0]
        fields = {
            "repo_name": record["repo_name"],
            "observed_image_version": record["observed_image_version"],
            "declared_source_version": record["declared_source_version"],
            "php_api": expected["php_api"],
            "zend_module_api": expected["zend_module_api"],
            "zend_extension_api": expected["zend_extension_api"],
            "debug": expected["debug"],
            "zts": expected["zts"],
            "asan": expected["asan"],
            "shared_build": record["shared_build"],
            "target_libc": record["target_libc"],
            "target_triple": record["target_triple"],
            "source_tag": record["source_tag"],
            "index_digest": record["index_digest"],
            "manifest_digest": record["manifest_digest"],
            "config_digest": record["config_digest"],
            "profile_layer_digest": layer["digest"],
        }
        lines.append(f"    {matrix_key(record)}: struct(")
        for name, value in fields.items():
            rendered = quoted(value) if isinstance(value, str) else repr(value)
            lines.append(f"        {name} = {rendered},")
        lines.append("    ),")
    lines.extend(["}", "", "PHP_OCI_COMPATIBLE_ALIASES = {"])
    for record in lock["compatible_aliases"]:
        lines.extend([
            f"    {matrix_key(record)}: struct(",
            f"        repo_name = {quoted(record['compatible_sdk_repo'])},",
            f"        reason = {quoted(record['reason'])},",
            "    ),",
        ])
    lines.extend(["}", "", "PHP_OCI_UNAVAILABLE = {"])
    for record in lock["unavailable_imports"]:
        lines.extend([
            f"    {matrix_key(record)}: struct(",
            f"        reason = {quoted(record['reason'])},",
            f"        source_tag = {quoted(record['source_tag'])},",
            f"        image_digest = {quoted(record['index_digest'])},",
            "    ),",
        ])
    lines.extend(["}", ""])
    path.write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--crane", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--records-output", type=Path, required=True)
    parser.add_argument("--metadata-dir", type=Path, required=True)
    args = parser.parse_args()
    if not args.crane.is_file():
        raise SystemExit(f"crane executable is missing: {args.crane}")
    args.metadata_dir.mkdir(parents=True, exist_ok=True)

    imports = []
    unavailable = []
    for family in FAMILIES:
        for minor in family.minors:
            tag = family.tag.format(minor=minor)
            print(f"locking {tag}", file=sys.stderr, flush=True)
            image_digest, kind, image_path, _, platforms = fetch_image(args.crane, args.metadata_dir, tag)
            missing = set(ARCHES) - set(platforms)
            if missing:
                raise SystemExit(f"{tag} lacks expected platforms: {sorted(missing)}")
            for arch, (manifest_digest, manifest, config_path, config) in sorted(platforms.items()):
                for profile in family.profiles(minor):
                    imports.append(record(
                        family, minor, profile, arch, tag, image_digest, kind, image_path,
                        manifest_digest, manifest, config_path, config,
                    ))
    print("locking php-8.0_alpine", file=sys.stderr, flush=True)
    legacy, compatible_aliases, legacy_unavailable = legacy_records(args.crane, args.metadata_dir)
    imports.extend(legacy)
    unavailable.extend(legacy_unavailable)
    imports.sort(key=lambda item: item["repo_name"])
    unavailable.sort(key=lambda item: (item["image_family"], item["minor"], item["arch"], item["abi_profile"]))
    if len(imports) != 223 or len(compatible_aliases) != 1 or len(unavailable) != 2:
        raise SystemExit(
            f"matrix cardinality mismatch: {len(imports)} imports, "
            f"{len(compatible_aliases)} aliases, {len(unavailable)} unavailable"
        )
    lock = {
        "schema_version": 1,
        "filesystem_scope": "headers-only self-contained PHP profile snapshots",
        "policy": "same PHP minor and ABI; exact embedded patch is always locked and verified",
        "expected_matrix_records": 226,
        "available_imports": len(imports),
        "compatible_aliases": compatible_aliases,
        "unavailable_imports": unavailable,
        "imports": imports,
    }
    args.output.write_text(json.dumps(lock, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    write_records_bzl(args.records_output, lock)


if __name__ == "__main__":
    main()
