#!/usr/bin/env python3
"""Safely derive a relocatable PHP execution closure from final OCI layers."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import posixpath
import shutil
import stat
import struct
import tarfile
from pathlib import Path, PurePosixPath


def fail(message: str) -> "NoReturn":
    raise SystemExit(message)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return "sha256:" + digest.hexdigest()


def clean_name(name: str, *, allow_root: bool = False) -> str:
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


def library_member(name: str) -> bool:
    roots = ("lib", "lib64", "usr/lib", "usr/lib64")
    if not any(inside(name, root) for root in roots):
        return False
    base = posixpath.basename(name)
    return ".so" in base or base.startswith("ld-") or base.startswith("ld-linux")


def selected(name: str, php_prefix: str) -> bool:
    return (
        inside(name, php_prefix)
        or library_member(name)
        or inside(name, "usr/share/icu")
        or name == "usr/share/zoneinfo/UTC"
    )


def selected_scope(name: str, php_prefix: str) -> bool:
    """Whether an OCI directory intersects the selected runtime subtree."""
    scopes = (
        php_prefix,
        "lib",
        "lib64",
        "usr/lib",
        "usr/lib64",
        "usr/share/icu",
        "usr/share/zoneinfo/UTC",
    )
    if name in ("", "."):
        return True
    return any(inside(name, scope) or inside(scope, name) for scope in scopes)


def resolve_in_root(root: Path, name: str, *, follow_final: bool = True) -> Path:
    parts = list(PurePosixPath(clean_name(name)).parts)
    resolved: list[str] = []
    links = 0
    index = 0
    while index < len(parts):
        part = parts[index]
        candidate = root.joinpath(*resolved, part)
        is_final = index == len(parts) - 1
        if candidate.is_symlink() and (follow_final or not is_final):
            links += 1
            if links > 64:
                fail(f"too many symlinks resolving OCI path {name!r}")
            target = os.readlink(candidate)
            remainder = parts[index + 1 :]
            if target.startswith("/"):
                combined = posixpath.normpath(target[1:])
            else:
                combined = posixpath.normpath(posixpath.join(*resolved, target))
            clean_name(combined)
            parts = list(PurePosixPath(combined).parts) + remainder
            resolved = []
            index = 0
            continue
        resolved.append(part)
        index += 1
    return root.joinpath(*resolved)


def remove_path(path: Path) -> None:
    if path.is_symlink() or path.is_file():
        path.unlink()
    elif path.exists():
        shutil.rmtree(path)


def ensure_parent(root: Path, destination_name: str) -> Path:
    destination = resolve_in_root(root, destination_name, follow_final=False)
    parent_name = posixpath.dirname(destination_name)
    if parent_name:
        parent = resolve_in_root(root, parent_name)
        parent.mkdir(parents=True, exist_ok=True)
        destination = parent / posixpath.basename(destination_name)
    return destination


def normalized_mode(member: tarfile.TarInfo) -> int:
    if member.isdir():
        return 0o755
    return 0o755 if member.mode & 0o111 else 0o644


def apply_layer(archive: Path, root: Path, php_prefix: str, budget: list[int]) -> None:
    hardlinks: list[tuple[str, str, int]] = []
    with tarfile.open(archive, mode="r:*") as layer:
        members = list(layer)
        for member in members:
            name = clean_name(member.name, allow_root=True)
            if name == ".":
                continue
            base = posixpath.basename(name)
            parent = posixpath.dirname(name)
            if base == ".wh..wh..opq":
                if selected_scope(parent, php_prefix):
                    directory = root if not parent else resolve_in_root(root, parent)
                    if directory.is_dir():
                        for child in directory.iterdir():
                            remove_path(child)
                continue
            if base.startswith(".wh."):
                target = posixpath.join(parent, base[4:])
                if selected_scope(target, php_prefix):
                    remove_path(resolve_in_root(root, target, follow_final=False))

        for member in members:
            name = clean_name(member.name, allow_root=True)
            keeps_selected_ancestor = (member.isdir() or member.issym()) and selected_scope(name, php_prefix)
            if name == "." or posixpath.basename(name).startswith(".wh.") or not (selected(name, php_prefix) or keeps_selected_ancestor):
                continue
            if member.ischr() or member.isblk() or member.isfifo() or member.isdev():
                fail(f"special file is forbidden in selected OCI runtime paths: {name}")
            destination = ensure_parent(root, name)
            if member.isdir():
                if destination.is_symlink() or destination.is_file():
                    remove_path(destination)
                destination.mkdir(parents=True, exist_ok=True)
                os.chmod(destination, 0o755)
                continue
            remove_path(destination)
            if member.issym():
                target = member.linkname
                if "\0" in target:
                    fail(f"invalid OCI symlink target for {name}")
                os.symlink(target, destination)
            elif member.islnk():
                hardlinks.append((name, clean_name(member.linkname.lstrip("/")), normalized_mode(member)))
            elif member.isfile():
                budget[0] += member.size
                if budget[0] > budget[1]:
                    fail(f"selected OCI runtime files exceed {budget[1]} bytes")
                source = layer.extractfile(member)
                if source is None:
                    fail(f"unable to read OCI member {name}")
                with source, destination.open("wb") as output:
                    shutil.copyfileobj(source, output, length=1024 * 1024)
                os.chmod(destination, normalized_mode(member))
            else:
                fail(f"unsupported OCI member type for {name}")
            os.utime(destination, (0, 0), follow_symlinks=False)

    for destination_name, source_name, mode in hardlinks:
        destination = ensure_parent(root, destination_name)
        source = resolve_in_root(root, source_name)
        if not source.is_file():
            fail(f"selected OCI hardlink target is missing: {destination_name} -> {source_name}")
        remove_path(destination)
        os.link(source, destination)
        os.chmod(destination, mode)


def elf_dynamic(path: Path) -> tuple[int, str | None, list[str]]:
    with path.open("rb") as elf:
        header = elf.read(64)
        if len(header) != 64 or header[:4] != b"\x7fELF" or header[4:6] != b"\x02\x01":
            fail(f"expected little-endian ELF64 file: {path}")
        machine = struct.unpack_from("<H", header, 18)[0]
        phoff = struct.unpack_from("<Q", header, 32)[0]
        phentsize = struct.unpack_from("<H", header, 54)[0]
        phnum = struct.unpack_from("<H", header, 56)[0]
        segments = []
        dynamic = None
        interpreter = None
        for index in range(phnum):
            elf.seek(phoff + index * phentsize)
            ph = elf.read(phentsize)
            p_type = struct.unpack_from("<I", ph, 0)[0]
            p_offset = struct.unpack_from("<Q", ph, 8)[0]
            p_vaddr = struct.unpack_from("<Q", ph, 16)[0]
            p_filesz = struct.unpack_from("<Q", ph, 32)[0]
            p_memsz = struct.unpack_from("<Q", ph, 40)[0]
            if p_type == 1:
                segments.append((p_vaddr, p_memsz, p_offset, p_filesz))
            elif p_type == 2:
                dynamic = (p_offset, p_filesz)
            elif p_type == 3:
                elf.seek(p_offset)
                interpreter = elf.read(p_filesz).rstrip(b"\0").decode("ascii")
        if dynamic is None:
            return machine, interpreter, []
        elf.seek(dynamic[0])
        data = elf.read(dynamic[1])
        offsets = []
        strtab_address = None
        strtab_size = None
        for offset in range(0, len(data) - 15, 16):
            tag, value = struct.unpack_from("<QQ", data, offset)
            if tag == 0:
                break
            if tag == 1:
                offsets.append(value)
            elif tag == 5:
                strtab_address = value
            elif tag == 10:
                strtab_size = value
        if strtab_address is None or strtab_size is None:
            fail(f"ELF dynamic table lacks string table: {path}")
        strtab_offset = None
        for vaddr, memsz, file_offset, filesz in segments:
            if vaddr <= strtab_address < vaddr + memsz:
                delta = strtab_address - vaddr
                if delta + strtab_size > filesz:
                    fail(f"ELF string table exceeds file-backed segment: {path}")
                strtab_offset = file_offset + delta
                break
        if strtab_offset is None:
            fail(f"ELF string table is outside load segments: {path}")
        elf.seek(strtab_offset)
        strings = elf.read(strtab_size)
        needed = []
        for offset in offsets:
            end = strings.find(b"\0", offset)
            if end < 0:
                fail(f"unterminated DT_NEEDED in {path}")
            needed.append(strings[offset:end].decode("ascii"))
        return machine, interpreter, needed


def find_library(root: Path, name: str, php_prefix: str) -> Path:
    candidates = []
    for path in root.rglob(name):
        try:
            actual = resolve_in_root(root, path.relative_to(root).as_posix())
        except SystemExit:
            continue
        if actual.is_file():
            relative = path.relative_to(root).as_posix()
            rank = (
                0 if inside(relative, php_prefix) else 1,
                0 if "linux-gnu" in relative else 1,
                len(PurePosixPath(relative).parts),
                relative,
            )
            candidates.append((rank, actual))
    if not candidates:
        fail(f"final OCI image does not contain DT_NEEDED library {name}")
    by_digest: dict[str, list[Path]] = {}
    for _, candidate in candidates:
        by_digest.setdefault(sha256_file(candidate), []).append(candidate)
    if len(by_digest) != 1:
        choices = sorted(str(candidate.relative_to(root)) for _, candidate in candidates)
        fail(f"ambiguous different-content OCI libraries for {name}: {choices}")
    candidates.sort(key=lambda item: item[0])
    return candidates[0][1]


def validate_stable_dependencies(names: "Iterable[str]") -> None:
    asan_dependencies = sorted(name for name in names if "asan" in name.lower())
    if asan_dependencies:
        fail(f"stable execution PHP closure unexpectedly depends on ASan: {asan_dependencies}")


def copy_execution_closure(root: Path, output: Path, record: dict) -> dict:
    if record.get("closure_schema") != 2:
        fail("execution PHP lock does not select closure schema 2")
    php_prefix = clean_name(record["source_prefix"].lstrip("/"))
    php = resolve_in_root(root, php_prefix + "/bin/php")
    if not php.is_file():
        fail(f"final OCI image lacks {php_prefix}/bin/php")
    expected_machine = {"amd64": 62, "arm64": 183}[record["platform"]["arch"]]
    machine, interpreter, needed = elf_dynamic(php)
    if machine != expected_machine or not interpreter:
        fail("execution PHP ELF architecture/interpreter mismatch")

    bin_dir = output / "bin"
    lib_dir = output / "lib"
    extensions_dir = output / "extensions"
    bin_dir.mkdir(parents=True)
    lib_dir.mkdir()
    extensions_dir.mkdir()
    shutil.copyfile(php, bin_dir / "php")
    os.chmod(bin_dir / "php", 0o755)

    loader = find_library(root, posixpath.basename(interpreter), php_prefix)
    shutil.copyfile(loader, lib_dir / "ld.so")
    os.chmod(lib_dir / "ld.so", 0o755)

    tokenizer_matches = sorted(root.joinpath(php_prefix).glob("lib/php/extensions/**/tokenizer.so"))
    tokenizer = None
    if len(tokenizer_matches) > 1:
        fail("final OCI PHP profile contains multiple tokenizer.so files")
    if tokenizer_matches:
        tokenizer = resolve_in_root(root, tokenizer_matches[0].relative_to(root).as_posix())
        shutil.copyfile(tokenizer, extensions_dir / "tokenizer.so")
        os.chmod(extensions_dir / "tokenizer.so", 0o755)

    tokenizer_needed: list[str] = []
    if tokenizer:
        token_machine, _, token_needed = elf_dynamic(tokenizer)
        if token_machine != expected_machine:
            fail("tokenizer extension architecture differs from execution PHP")
        tokenizer_needed.extend(token_needed)
    # The execution PHP is also the native validation runtime for the
    # universal loader.  Debian 12's PHP executable does not itself retain
    # these pre-glibc-2.34 compatibility DSOs, but the loader does.  Import
    # them from the same locked final image so a loader smoke cannot resolve
    # them from the worker's /lib directories.
    queue = list(needed) + ["libdl.so.2", "libpthread.so.0", "librt.so.1"]
    copied: dict[str, str] = {}
    while queue:
        name = queue.pop(0)
        source = find_library(root, name, php_prefix)
        digest = sha256_file(source)
        if name in copied:
            if copied[name] != digest:
                fail(f"conflicting runtime libraries for SONAME {name}")
            continue
        copied[name] = digest
        destination = lib_dir / name
        shutil.copyfile(source, destination)
        os.chmod(destination, 0o755)
        library_machine, _, transitive = elf_dynamic(source)
        if library_machine != expected_machine:
            fail(f"runtime library {name} has the wrong architecture")
        queue.extend(transitive)

    # Loading tokenizer must not broaden the runtime dependency closure. This
    # lets the PHP executable's loader trace cover every DSO used by generators.
    tokenizer_seen: set[str] = set()
    while tokenizer_needed:
        name = tokenizer_needed.pop(0)
        if name in tokenizer_seen:
            continue
        tokenizer_seen.add(name)
        source = find_library(root, name, php_prefix)
        digest = sha256_file(source)
        if copied.get(name) != digest:
            fail(f"tokenizer dependency is absent from the PHP startup closure: {name}")
        library_machine, _, transitive = elf_dynamic(source)
        if library_machine != expected_machine:
            fail(f"tokenizer runtime library {name} has the wrong architecture")
        tokenizer_needed.extend(transitive)

    validate_stable_dependencies(tuple(copied) + tuple(tokenizer_seen))

    (lib_dir / ".root").write_text("execution PHP library root\n", encoding="utf-8")
    return {
        "arch": record["platform"]["arch"],
        "expected_php_version": record["expected"]["php_version"],
        "image_digest": record["index_digest"],
        "interpreter": interpreter,
        "libraries": copied,
        "manifest_digest": record["manifest_digest"],
        "native_execution_validation": "required",
        "source_tag": record["source_tag"],
        "tokenizer_extension": tokenizer is not None,
    }


def normalize_tree(root: Path) -> None:
    for directory, directories, files in os.walk(root, topdown=False):
        for name in sorted(files + directories):
            path = Path(directory) / name
            if path.is_symlink():
                fail(f"execution PHP closure must not contain symlinks: {path}")
            if path.is_dir():
                os.chmod(path, 0o755)
            else:
                os.chmod(path, 0o755 if path.stat().st_mode & 0o111 else 0o644)
            os.utime(path, (0, 0))
    os.utime(root, (0, 0))


def read_metadata(path: Path, digest: str, kind: str) -> dict:
    actual = sha256_file(path)
    if actual != digest:
        fail(f"OCI {kind} digest mismatch: expected {digest}, got {actual}")
    return json.loads(path.read_bytes())


def verify_ancestry(record: dict, index_path: Path, manifest_path: Path, config_path: Path) -> list[dict]:
    index = read_metadata(index_path, record["index_digest"], "index")
    manifest = read_metadata(manifest_path, record["manifest_digest"], "manifest")
    config = read_metadata(config_path, record["config_digest"], "config")
    matches = [item for item in index.get("manifests", []) if item.get("digest") == record["manifest_digest"]]
    if len(matches) != 1 or matches[0].get("platform", {}).get("architecture") != record["platform"]["arch"]:
        fail("OCI index does not contain the selected execution platform manifest")
    if manifest.get("config", {}).get("digest") != record["config_digest"]:
        fail("OCI manifest does not reference the locked config")
    if config.get("architecture") != record["platform"]["arch"] or config.get("os") != "linux":
        fail("OCI config platform differs from the selected execution platform")
    layers = manifest.get("layers", [])
    if layers != [
        {"digest": item["digest"], "mediaType": item["media_type"], "size": item["size"]}
        for item in record["layers"]
    ]:
        fail("OCI manifest layer sequence differs from the execution runtime lock")
    return layers


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
    descriptors = verify_ancestry(record, args.index, args.manifest, args.config)
    if len(args.layers) != len(descriptors):
        fail("downloaded OCI layer count differs from the manifest")
    for archive, descriptor in zip(args.layers, descriptors):
        if archive.stat().st_size != descriptor["size"] or sha256_file(archive) != descriptor["digest"]:
            fail(f"downloaded OCI layer does not match descriptor: {descriptor['digest']}")

    root = args.output.parent / (args.output.name + ".rootfs")
    remove_path(root)
    remove_path(args.output)
    root.mkdir(parents=True)
    budget = [0, 8 * 1024 * 1024 * 1024]
    php_prefix = clean_name(record["source_prefix"].lstrip("/"))
    for archive in args.layers:
        apply_layer(archive, root, php_prefix, budget)
    args.output.mkdir(parents=True)
    observed = copy_execution_closure(root, args.output, record)
    (args.output / "observed.json").write_text(
        json.dumps(observed, sort_keys=True, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )
    normalize_tree(args.output)
    remove_path(root)


if __name__ == "__main__":
    main()
