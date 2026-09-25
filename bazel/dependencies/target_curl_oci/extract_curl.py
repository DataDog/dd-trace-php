#!/usr/bin/env python3
"""Safely import the CI curl SDK from one immutable OCI layer."""

from __future__ import annotations

import argparse
import json
import os
import posixpath
import re
import shutil
import stat
import struct
import tarfile
from pathlib import Path, PurePosixPath


PREFIX = "usr/local/curl"


def fail(message: str) -> "NoReturn":
    raise SystemExit(message)


def clean_name(name: str) -> str:
    while name.startswith("./"):
        name = name[2:]
    path = PurePosixPath(name)
    if not name or path.is_absolute() or any(part in ("", ".", "..") for part in path.parts):
        fail(f"unsafe curl OCI member path: {name!r}")
    return path.as_posix()


def selected(name: str, prefix: str = PREFIX) -> bool:
    return name == prefix or name.startswith(prefix + "/")


def destination(output: Path, name: str, prefix: str = PREFIX) -> Path:
    relative = PurePosixPath(name).relative_to(prefix)
    result = output.joinpath(*relative.parts)
    result.parent.mkdir(parents=True, exist_ok=True)
    return result


def extract(archive: Path, output: Path, prefix: str = PREFIX) -> None:
    hardlinks: list[tuple[Path, str]] = []
    total = 0
    with tarfile.open(archive, "r:gz") as layer:
        for member in layer:
            name = clean_name(member.name)
            if not selected(name, prefix):
                continue
            target = destination(output, name, prefix)
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            if member.ischr() or member.isblk() or member.isfifo() or member.isdev():
                fail(f"special file in curl SDK layer: {name}")
            if target.exists() or target.is_symlink():
                fail(f"duplicate curl SDK member: {name}")
            if member.issym():
                link = member.linkname
                if link.startswith("/") or any(part == ".." for part in PurePosixPath(link).parts):
                    fail(f"unsafe curl SDK symlink: {name} -> {link}")
                os.symlink(link, target)
            elif member.islnk():
                hardlinks.append((target, clean_name(member.linkname)))
            elif member.isfile():
                total += member.size
                if total > 32 * 1024 * 1024:
                    fail("curl SDK layer exceeds 32 MiB")
                source = layer.extractfile(member)
                if source is None:
                    fail(f"cannot read curl SDK member: {name}")
                with source, target.open("wb") as stream:
                    shutil.copyfileobj(source, stream)
            else:
                fail(f"unsupported curl SDK member type: {name}")
    for target, link in hardlinks:
        if not selected(link, prefix):
            fail(f"curl SDK hardlink escapes prefix: {link}")
        source = destination(output, link, prefix)
        if not source.is_file():
            fail(f"curl SDK hardlink source missing: {link}")
        os.link(source, target)


def elf_machine(path: Path) -> int:
    with path.open("rb") as stream:
        header = stream.read(20)
    if len(header) != 20 or header[:6] != b"\x7fELF\x02\x01":
        fail(f"curl shared library is not little-endian ELF64: {path}")
    return struct.unpack_from("<H", header, 18)[0]


def normalize(output: Path) -> None:
    for directory, directories, files in os.walk(output, topdown=False):
        for name in sorted(files + directories):
            path = Path(directory) / name
            if path.is_symlink():
                os.utime(path, (0, 0), follow_symlinks=False)
            elif path.is_dir():
                os.chmod(path, 0o755)
                os.utime(path, (0, 0))
            else:
                executable = path.stat().st_mode & stat.S_IXUSR
                os.chmod(path, 0o755 if executable or ".so" in path.name else 0o644)
                os.utime(path, (0, 0))
    os.utime(output, (0, 0))


def validate(output: Path, arch: str) -> str:
    header = output / "include/curl/curlver.h"
    if not header.is_file():
        fail("curl SDK layer lacks include/curl/curlver.h")
    text = header.read_text(encoding="utf-8")
    match = re.search(r'^#define LIBCURL_VERSION "([^"]+)"$', text, re.MULTILINE)
    if not match or match.group(1) != "7.61.1":
        fail("curl SDK header is not version 7.61.1")
    libraries = sorted(path for path in (output / "lib").glob("libcurl.so.*") if path.is_file())
    real = sorted({path.resolve() for path in libraries})
    if len(real) != 1:
        fail(f"curl SDK expected one shared-library payload, got {real}")
    expected = {"amd64": 62, "arm64": 183}[arch]
    if elf_machine(real[0]) != expected:
        fail("curl SDK shared-library architecture mismatch")
    required_runtime = {
        "openssl": ("libssl.so.1.1", "libcrypto.so.1.1"),
        "zlib": ("libz.so.1",),
    }
    runtime = []
    for directory, names in required_runtime.items():
        root = output / "dependencies" / directory / "lib"
        for name in names:
            candidate = root / name
            if not candidate.exists():
                fail(f"curl runtime closure lacks {directory}/{name}")
            actual = candidate.resolve()
            if elf_machine(actual) != expected:
                fail(f"curl runtime dependency {name} has the wrong architecture")
            runtime.append(actual.relative_to(output).as_posix())
    relative = real[0].relative_to(output).as_posix()
    (output / "observed.json").write_text(
        json.dumps({"arch": arch, "library": relative, "runtime": sorted(runtime), "version": match.group(1)}, sort_keys=True, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )
    return relative


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--arch", choices=("amd64", "arm64"), required=True)
    parser.add_argument("--curl-archive", type=Path, required=True)
    parser.add_argument("--openssl-archive", type=Path, required=True)
    parser.add_argument("--zlib-archive", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=False)
    extract(args.curl_archive, args.output)
    openssl = args.output / "dependencies/openssl"
    zlib = args.output / "dependencies/zlib"
    openssl.mkdir(parents=True)
    zlib.mkdir(parents=True)
    extract(args.openssl_archive, openssl, "usr/local/openssl")
    extract(args.zlib_archive, zlib, "usr/local/zlib")
    validate(args.output, args.arch)
    normalize(args.output)


if __name__ == "__main__":
    main()
