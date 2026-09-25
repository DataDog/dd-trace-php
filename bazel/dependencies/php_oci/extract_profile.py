#!/usr/bin/env python3
"""Safely materialize one PHP installation from checksum-verified OCI layers."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import posixpath
import re
import shutil
import stat
import struct
import tarfile
from pathlib import Path, PurePosixPath


def fail(message: str) -> "NoReturn":
    raise SystemExit(message)


def clean_member_name(name: str, *, allow_root: bool = False) -> str:
    while name.startswith("./"):
        name = name[2:]
    if not name and allow_root:
        return "."
    path = PurePosixPath(name)
    if not name or path.is_absolute() or any(part in ("", ".", "..") for part in path.parts):
        fail(f"unsafe OCI member path: {name!r}")
    return path.as_posix()


def inside(path: str, prefix: str) -> bool:
    return path == prefix or path.startswith(prefix + "/")


def overlaps(path: str, prefix: str) -> bool:
    if path in ("", "."):
        return True
    return inside(path, prefix) or inside(prefix, path)


def remove_path(path: Path) -> None:
    if path.is_symlink() or path.is_file():
        path.unlink()
    elif path.exists():
        shutil.rmtree(path)


def ensure_parent(root: Path, destination: Path) -> None:
    relative = destination.relative_to(root)
    current = root
    for part in relative.parts[:-1]:
        current = current / part
        if current.is_symlink():
            fail(f"OCI member traverses symlink parent: {destination}")
        if current.exists() and not current.is_dir():
            fail(f"OCI member traverses non-directory parent: {destination}")
        current.mkdir(mode=0o755, exist_ok=True)


def reject_symlink_components(root: Path, path: Path) -> None:
    relative = path.relative_to(root)
    current = root
    for part in relative.parts:
        current = current / part
        if current.is_symlink():
            fail(f"OCI hardlink traverses symlink component: {path}")


def normalized_mode(member: tarfile.TarInfo) -> int:
    if member.isdir():
        return 0o755
    return 0o755 if member.mode & 0o111 else 0o644


def mapped_link(member_name: str, link_name: str, prefix: str) -> str:
    if link_name.startswith("/"):
        target = posixpath.normpath(link_name[1:])
    else:
        target = posixpath.normpath(posixpath.join(posixpath.dirname(member_name), link_name))
    clean_member_name(target)
    if not inside(target, prefix):
        fail(f"OCI link escapes selected profile: {member_name!r} -> {link_name!r}")
    member_rel = member_name[len(prefix) + 1 :]
    target_rel = target[len(prefix) + 1 :]
    return posixpath.relpath(target_rel, posixpath.dirname(member_rel) or ".")


def selected_content(rel: str, content_mode: str) -> bool:
    if content_mode == "full_profile":
        return True
    return rel == "include" or rel.startswith("include/") or rel == "bin" or rel == "bin/php"


def apply_layer(
    archive: Path,
    profile: Path,
    prefix: str,
    content_mode: str,
    budget: list[int],
    filesystem_semantics: str,
) -> None:
    hardlinks: list[tuple[Path, str, int]] = []
    selected_names: set[str] = set()
    with tarfile.open(archive, mode="r:*") as layer:
        for member in layer:
            name = clean_member_name(member.name, allow_root=True)
            if name == ".":
                if not member.isdir():
                    fail("OCI archive root entry is not a directory")
                continue
            base = posixpath.basename(name)
            parent = posixpath.dirname(name)

            if base == ".wh..wh..opq":
                # Docker COPY emits this marker at the copied directory root to
                # state that the layer replaces, rather than merges with, any
                # lower directory. That is exactly the self-contained snapshot
                # boundary locked by profile_copy_snapshot.
                if filesystem_semantics == "profile_copy_snapshot" and parent == prefix:
                    continue
                if overlaps(parent, prefix):
                    fail(f"snapshot layer has an opaque whiteout affecting selected profile: {name}")
                continue
            if base.startswith(".wh."):
                target = posixpath.join(parent, base[4:])
                if overlaps(target, prefix):
                    fail(f"snapshot layer has a whiteout affecting selected profile: {name}")
                continue
            if not inside(name, prefix) or name == prefix:
                continue

            if name in selected_names:
                fail(f"duplicate path in OCI snapshot layer: {name}")
            selected_names.add(name)

            rel = name[len(prefix) + 1 :]
            if not selected_content(rel, content_mode):
                continue
            destination = profile / rel
            ensure_parent(profile, destination)

            if member.ischr() or member.isblk() or member.isfifo() or member.isdev():
                fail(f"special file is forbidden in imported PHP profile: {name}")
            if member.isdir():
                if destination.is_symlink() or destination.is_file():
                    remove_path(destination)
                destination.mkdir(mode=0o755, exist_ok=True)
                os.chmod(destination, normalized_mode(member))
                continue

            remove_path(destination)
            if member.issym():
                os.symlink(mapped_link(name, member.linkname, prefix), destination)
            elif member.islnk():
                hardlinks.append((destination, member.linkname, normalized_mode(member)))
            elif member.isfile():
                budget[0] += member.size
                if budget[0] > budget[1]:
                    fail(f"OCI profile exceeds locked unpacked-size limit {budget[1]}")
                source = layer.extractfile(member)
                if source is None:
                    fail(f"unable to read regular OCI member: {name}")
                with source, destination.open("wb") as output:
                    shutil.copyfileobj(source, output, length=1024 * 1024)
                os.chmod(destination, normalized_mode(member))
            else:
                fail(f"unsupported OCI member type for {name!r}")
            os.utime(destination, (0, 0), follow_symlinks=False)

    for destination, link_name, mode in hardlinks:
        ensure_parent(profile, destination)
        source_name = clean_member_name(link_name.lstrip("/"))
        if not inside(source_name, prefix):
            fail(f"OCI hardlink escapes selected profile: {destination} -> {link_name!r}")
        source = profile / source_name[len(prefix) + 1 :]
        reject_symlink_components(profile, source)
        if not source.is_file() or source.is_symlink():
            fail(f"OCI hardlink target is missing or invalid: {link_name!r}")
        os.link(source, destination)
        os.chmod(destination, mode)
        os.utime(destination, (0, 0), follow_symlinks=False)


def define(text: str, name: str) -> str | None:
    match = re.search(rf"^#define\s+{re.escape(name)}\s+(.+?)\s*$", text, re.MULTILINE)
    return match.group(1).strip() if match else None


def elf_needed(path: Path) -> tuple[bytes, int, list[str]]:
    with path.open("rb") as elf:
        header = elf.read(64)
        if len(header) != 64 or header[:4] != b"\x7fELF":
            fail("imported bin/php is not ELF64")
        if header[4] != 2:
            fail(f"unsupported PHP ELF class: {header[4]}")
        if header[5] != 1:
            fail(f"unsupported PHP ELF data encoding: {header[5]}")
        machine = struct.unpack_from("<H", header, 18)[0]
        phoff = struct.unpack_from("<Q", header, 32)[0]
        phentsize = struct.unpack_from("<H", header, 54)[0]
        phnum = struct.unpack_from("<H", header, 56)[0]
        if phentsize < 56 or phnum == 0:
            fail("PHP ELF has no usable program header table")
        segments = []
        dynamic = None
        for index in range(phnum):
            elf.seek(phoff + index * phentsize)
            ph = elf.read(phentsize)
            if len(ph) != phentsize:
                fail("truncated PHP ELF program header table")
            p_type = struct.unpack_from("<I", ph, 0)[0]
            p_offset, p_vaddr, p_filesz, p_memsz = struct.unpack_from("<QQQQ", ph, 8)[0], struct.unpack_from("<Q", ph, 16)[0], struct.unpack_from("<Q", ph, 32)[0], struct.unpack_from("<Q", ph, 40)[0]
            if p_type == 1:
                segments.append((p_vaddr, p_memsz, p_offset, p_filesz))
            elif p_type == 2:
                dynamic = (p_offset, p_filesz)
        if dynamic is None:
            return header, machine, []
        elf.seek(dynamic[0])
        dynamic_data = elf.read(dynamic[1])
        needed_offsets = []
        strtab_address = None
        strtab_size = None
        for offset in range(0, len(dynamic_data) - 15, 16):
            tag, value = struct.unpack_from("<QQ", dynamic_data, offset)
            if tag == 0:
                break
            if tag == 1:
                needed_offsets.append(value)
            elif tag == 5:
                strtab_address = value
            elif tag == 10:
                strtab_size = value
        if strtab_address is None or strtab_size is None:
            fail("PHP ELF dynamic table lacks its string table")
        strtab_offset = None
        for vaddr, memsz, file_offset, filesz in segments:
            if vaddr <= strtab_address < vaddr + memsz:
                delta = strtab_address - vaddr
                if delta + strtab_size > filesz:
                    fail("PHP ELF dynamic string table lies outside file-backed segment")
                strtab_offset = file_offset + delta
                break
        if strtab_offset is None:
            fail("PHP ELF dynamic string table is not in a load segment")
        elf.seek(strtab_offset)
        strings = elf.read(strtab_size)
        needed = []
        for offset in needed_offsets:
            if offset >= len(strings):
                fail("PHP ELF DT_NEEDED offset lies outside string table")
            end = strings.find(b"\0", offset)
            if end < 0:
                fail("unterminated PHP ELF DT_NEEDED string")
            needed.append(strings[offset:end].decode("ascii"))
        return header, machine, needed


def require_profile(profile: Path, expected: dict, content_mode: str) -> dict:
    include = profile / "include" / "php"
    version_header = include / "main" / "php_version.h"
    php_header = include / "main" / "php.h"
    config_header = include / "main" / "php_config.h"
    zend_modules_header = include / "Zend" / "zend_modules.h"
    zend_extensions_header = include / "Zend" / "zend_extensions.h"
    for path in (
        version_header,
        php_header,
        config_header,
        include / "Zend" / "zend.h",
        zend_modules_header,
        zend_extensions_header,
    ):
        if not path.is_file():
            fail(f"imported profile is missing required PHP header: {path}")

    version_text = version_header.read_text(encoding="utf-8")
    php_text = php_header.read_text(encoding="utf-8")
    config_text = config_header.read_text(encoding="utf-8")
    zend_modules_text = zend_modules_header.read_text(encoding="utf-8")
    zend_extensions_text = zend_extensions_header.read_text(encoding="utf-8")
    actual_version = (define(version_text, "PHP_VERSION") or "").strip('"')
    actual_version_id = int(define(version_text, "PHP_VERSION_ID") or "-1")
    actual_api = int(define(php_text, "PHP_API_VERSION") or "-1")
    actual_zend_module_api = int(define(zend_modules_text, "ZEND_MODULE_API_NO") or "-1")
    actual_zend_extension_api = int(define(zend_extensions_text, "ZEND_EXTENSION_API_NO") or "-1")
    actual_debug = define(config_text, "ZEND_DEBUG") == "1"
    actual_zts = define(config_text, "ZTS") == "1"

    checks = {
        "version": (actual_version, expected["php_version"]),
        "version_id": (actual_version_id, expected["php_version_id"]),
        "api": (actual_api, expected["php_api"]),
        "Zend module API": (actual_zend_module_api, expected["zend_module_api"]),
        "Zend extension API": (actual_zend_extension_api, expected["zend_extension_api"]),
        "debug": (actual_debug, expected["debug"]),
        "zts": (actual_zts, expected["zts"]),
    }
    for field, (actual, wanted) in checks.items():
        if actual != wanted:
            fail(f"embedded PHP {field} mismatch: expected {wanted!r}, got {actual!r}")

    extension_files = []
    php = profile / "bin" / "php"
    if not php.is_file():
        fail("imported profile is missing bin/php for ELF validation")
    _, machine, needed = elf_needed(php)
    expected_machine = {"amd64": 62, "arm64": 183}[expected["arch"]]
    if machine != expected_machine:
        fail(f"PHP ELF architecture mismatch: expected e_machine={expected_machine}, got {machine}")
    actual_asan = any("libasan.so" in name or "libclang_rt.asan" in name for name in needed)
    if actual_asan != expected["asan"]:
        fail(f"PHP ASan linkage mismatch: expected {expected['asan']}, got {actual_asan}")
    if content_mode == "full_profile":
        extension_files = sorted(path.name for path in profile.glob("lib/php/extensions/**/*.so"))
        missing_extensions = sorted(set(expected.get("required_extensions", ())) - {name[:-3] for name in extension_files})
        if missing_extensions:
            fail(f"imported profile lacks required shared extensions: {missing_extensions}")

    return {
        "php_version": actual_version,
        "php_version_id": actual_version_id,
        "php_api": actual_api,
        "zend_module_api": actual_zend_module_api,
        "zend_extension_api": actual_zend_extension_api,
        "arch": expected["arch"],
        "debug": actual_debug,
        "zts": actual_zts,
        "asan": actual_asan,
        "extensions": extension_files,
        "content_mode": content_mode,
    }


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return "sha256:" + digest.hexdigest()


def read_locked_metadata(path: Path, expected_digest: str, kind: str) -> dict:
    actual = sha256_file(path)
    if actual != expected_digest:
        fail(f"OCI {kind} digest mismatch: expected {expected_digest}, got {actual}")
    try:
        value = json.loads(path.read_bytes())
    except (json.JSONDecodeError, UnicodeDecodeError) as error:
        fail(f"invalid OCI {kind} JSON: {error}")
    if kind != "config" and value.get("schemaVersion") != 2:
        fail(f"unsupported OCI {kind} schemaVersion: {value.get('schemaVersion')!r}")
    return value


def verify_oci_ancestry(record: dict, index_path: Path, manifest_path: Path, config_path: Path) -> None:
    index = read_locked_metadata(index_path, record["index_digest"], "index")
    manifest = read_locked_metadata(manifest_path, record["manifest_digest"], "manifest")
    config = read_locked_metadata(config_path, record["config_digest"], "config")

    descriptor_kind = record.get("image_descriptor_kind", "index")
    if descriptor_kind == "index":
        selected_manifests = [
            descriptor
            for descriptor in index.get("manifests", [])
            if descriptor.get("digest") == record["manifest_digest"]
        ]
        if len(selected_manifests) != 1:
            fail("OCI index does not contain exactly one locked platform manifest")
        index_platform = selected_manifests[0].get("platform", {})
        if index_platform.get("os") != record["platform"]["os"] or index_platform.get("architecture") != record["platform"]["arch"]:
            fail(f"OCI index platform mismatch: {index_platform!r}")
    elif descriptor_kind == "manifest":
        if record["index_digest"] != record["manifest_digest"]:
            fail("single-manifest OCI record must use the manifest digest as its image digest")
        if index != manifest:
            fail("single-manifest OCI record metadata differs between image and manifest inputs")
    else:
        fail(f"unsupported OCI image descriptor kind: {descriptor_kind!r}")

    config_descriptor = manifest.get("config", {})
    if config_descriptor.get("digest") != record["config_digest"]:
        fail("OCI platform manifest does not reference the locked config")
    layers = manifest.get("layers", [])
    locked_layer = record["layers"][0]
    selected_layers = [
        (index, descriptor)
        for index, descriptor in enumerate(layers)
        if descriptor.get("digest") == locked_layer["digest"]
    ]
    if len(selected_layers) != 1:
        fail("OCI platform manifest does not contain exactly one locked profile layer")
    layer_index, layer_descriptor = selected_layers[0]
    for field in ("size", "mediaType"):
        expected = locked_layer["media_type"] if field == "mediaType" else locked_layer[field]
        if layer_descriptor.get(field) != expected:
            fail(f"OCI profile layer {field} mismatch: expected {expected!r}, got {layer_descriptor.get(field)!r}")

    if config.get("architecture") != record["platform"]["arch"] or config.get("os") != record["platform"]["os"]:
        fail("OCI config architecture/OS differs from the selected index platform")
    diff_ids = config.get("rootfs", {}).get("diff_ids", [])
    history = [entry for entry in config.get("history", []) if not entry.get("empty_layer", False)]
    if len(diff_ids) != len(layers) or len(history) != len(layers):
        fail("OCI manifest layers, config diff_ids, and nonempty history lengths differ")
    actual_history = history[layer_index].get("created_by", "")
    if actual_history != record["expected_history_created_by"]:
        fail(
            "locked profile layer does not have the expected immutable history entry: "
            f"expected {record['expected_history_created_by']!r}, got {actual_history!r}"
        )


def validate_link_graph(root: Path, allowed_root: Path | None = None) -> None:
    allowed = (allowed_root or root).resolve()
    for directory, directories, files in os.walk(root, topdown=True, followlinks=False):
        for name in sorted(directories + files):
            path = Path(directory) / name
            if not path.is_symlink():
                continue
            try:
                resolved = path.resolve(strict=True)
            except (FileNotFoundError, RuntimeError) as error:
                fail(f"invalid normalized output symlink {path}: {error}")
            if allowed not in (resolved, *resolved.parents):
                fail(f"normalized output symlink escapes allowed tree: {path} -> {resolved}")


def normalize_tree(root: Path) -> None:
    validate_link_graph(root)
    for directory, directories, files in os.walk(root, topdown=False, followlinks=False):
        for name in sorted(files + directories):
            path = Path(directory) / name
            if path.is_symlink():
                continue
            if path.is_dir():
                os.chmod(path, 0o755)
            else:
                executable = path.stat().st_mode & 0o111
                os.chmod(path, 0o755 if executable else 0o644)
            os.utime(path, (0, 0))
    os.chmod(root, 0o755)
    os.utime(root, (0, 0))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--lock", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--index", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--config", type=Path, required=True)
    parser.add_argument("layers", type=Path, nargs="+")
    args = parser.parse_args()
    record = json.loads(args.lock.read_text(encoding="utf-8"))
    verify_oci_ancestry(record, args.index, args.manifest, args.config)
    if record.get("filesystem_semantics") not in (
        "profile_copy_snapshot",
        "profile_layer_snapshot",
    ):
        fail("only explicit self-contained profile snapshot imports are supported")
    if len(args.layers) != len(record["layers"]):
        fail("layer archive count does not match the lock")
    if len(args.layers) != 1:
        fail("a PHP profile snapshot import must lock exactly one self-contained layer")
    for archive, descriptor in zip(args.layers, record["layers"]):
        expected_digest = descriptor["digest"]
        if not expected_digest.startswith("sha256:"):
            fail(f"unsupported OCI layer digest: {expected_digest!r}")
        actual_digest = sha256_file(archive)
        if actual_digest != expected_digest:
            fail(f"OCI layer digest mismatch: expected {expected_digest}, got {actual_digest}")
        if archive.stat().st_size != descriptor["size"]:
            fail(
                f"OCI layer size mismatch for {expected_digest}: "
                f"expected {descriptor['size']}, got {archive.stat().st_size}"
            )
    output = args.output.resolve()
    if output.exists():
        shutil.rmtree(output)
    profile = output / ".profile"
    profile.mkdir(parents=True)
    budget = [0, int(record["max_unpacked_size"])]
    prefix = clean_member_name(record["source_prefix"].lstrip("/"))
    content_mode = record["content_mode"]
    for archive in args.layers:
        apply_layer(archive, profile, prefix, content_mode, budget, record["filesystem_semantics"])

    validate_link_graph(profile)
    validate_link_graph(profile / "include", profile / "include")
    observed = require_profile(profile, record["expected"], content_mode)

    sdk = output / "sdk"
    if content_mode == "full_profile":
        runtime = output / "runtime"
        profile.rename(runtime)
        shutil.copytree(runtime / "include", sdk / "include", symlinks=False)
        symbol_reference = output / "symbols" / "php"
        symbol_reference.parent.mkdir(parents=True)
        shutil.copyfile(runtime / "bin" / "php", symbol_reference)
    else:
        symbol_reference = output / "symbols" / "php"
        symbol_reference.parent.mkdir(parents=True)
        (profile / "bin" / "php").rename(symbol_reference)
        (profile / "bin").rmdir()
        profile.rename(sdk)
    (sdk / ".root").write_text("normalized PHP SDK root\n", encoding="utf-8")
    (output / "observed.json").write_text(json.dumps(observed, sort_keys=True, separators=(",", ":")) + "\n", encoding="utf-8")
    normalize_tree(output)


if __name__ == "__main__":
    main()
