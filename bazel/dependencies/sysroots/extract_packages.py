#!/usr/bin/env python3
"""Safely overlays checksum-verified RPM or APK payloads into a sysroot."""

import argparse
import bz2
import gzip
import io
import lzma
import os
import shutil
import stat
import struct
import sys
import tarfile
import zlib


_RPM_HEADER_MAGIC = b"\x8e\xad\xe8\x01"
_CPIO_MAGICS = (b"070701", b"070702")
_APK_METADATA = (".SIGN.", ".PKGINFO", ".INSTALL", ".pre-", ".post-")


class ExtractionError(Exception):
    pass


def _safe_destination(root, member):
    member = member.replace("\\", "/")
    while member.startswith("./"):
        member = member[2:]
    if not member or member == ".":
        return None
    if member.startswith("/"):
        raise ExtractionError("absolute archive path: %s" % member)
    parts = member.split("/")
    if any(part in ("", ".", "..") for part in parts):
        raise ExtractionError("non-normal archive path: %s" % member)
    destination = os.path.abspath(os.path.join(root, *parts))
    if os.path.commonpath((root, destination)) != root:
        raise ExtractionError("archive path escapes output: %s" % member)
    return destination


def _ensure_parent(root, destination):
    relative = os.path.relpath(os.path.dirname(destination), root)
    current = root
    if relative == ".":
        return
    for component in relative.split(os.sep):
        current = os.path.join(current, component)
        if os.path.lexists(current):
            if os.path.islink(current) or not os.path.isdir(current):
                raise ExtractionError("archive parent is not a directory: %s" % current)
        else:
            os.mkdir(current, 0o755)


def _remove_existing(path):
    if not os.path.lexists(path):
        return
    if os.path.isdir(path) and not os.path.islink(path):
        shutil.rmtree(path)
    else:
        os.unlink(path)


def _set_metadata(path, mode):
    if not os.path.islink(path):
        os.chmod(path, mode & 0o7777)
        os.utime(path, (0, 0))


def _write_directory(root, path, mode):
    if os.path.lexists(path) and (os.path.islink(path) or not os.path.isdir(path)):
        _remove_existing(path)
    _ensure_parent(root, path)
    os.makedirs(path, exist_ok=True)
    _set_metadata(path, mode or 0o755)


def _write_file(root, path, data, mode):
    _ensure_parent(root, path)
    _remove_existing(path)
    with open(path, "wb") as output:
        output.write(data)
    _set_metadata(path, mode or 0o644)


def _write_symlink(root, path, target):
    if "\x00" in target:
        raise ExtractionError("NUL in symlink target for %s" % path)
    _ensure_parent(root, path)
    _remove_existing(path)
    os.symlink(target, path)


def _write_hardlink(root, path, target):
    _ensure_parent(root, path)
    if not os.path.isfile(target) or os.path.islink(target):
        raise ExtractionError("hardlink target is not a regular file: %s" % target)
    _remove_existing(path)
    os.link(target, path)


def _normalize_tree(root):
    for directory, directories, files in os.walk(root, topdown=False, followlinks=False):
        for name in directories + files:
            path = os.path.join(directory, name)
            os.utime(path, (0, 0), follow_symlinks=False)
        os.utime(directory, (0, 0), follow_symlinks=False)


def _rpm_header_end(data, offset):
    if data[offset : offset + 4] != _RPM_HEADER_MAGIC:
        raise ExtractionError("invalid RPM header at offset %d" % offset)
    if offset + 16 > len(data):
        raise ExtractionError("truncated RPM header")
    index_count, store_size = struct.unpack(">II", data[offset + 8 : offset + 16])
    end = offset + 16 + index_count * 16 + store_size
    if end > len(data):
        raise ExtractionError("truncated RPM header store")
    return end


def _rpm_payload(data):
    if len(data) < 112 or data[:4] != b"\xed\xab\xee\xdb":
        raise ExtractionError("invalid RPM lead")
    signature_end = _rpm_header_end(data, 96)
    main_start = (signature_end + 7) & ~7
    payload_start = _rpm_header_end(data, main_start)
    payload = data[payload_start:]
    if payload.startswith(b"\xfd7zXZ\x00"):
        return lzma.decompress(payload)
    if payload.startswith(b"\x1f\x8b"):
        return gzip.decompress(payload)
    if payload.startswith(b"BZh"):
        return bz2.decompress(payload)
    raise ExtractionError("unsupported RPM payload compression")


def _cpio_entries(payload):
    offset = 0
    while True:
        if offset + 110 > len(payload):
            raise ExtractionError("truncated CPIO header")
        header = payload[offset : offset + 110]
        if header[:6] not in _CPIO_MAGICS:
            raise ExtractionError("invalid CPIO magic at offset %d" % offset)
        try:
            fields = [int(header[index : index + 8], 16) for index in range(6, 110, 8)]
        except ValueError as error:
            raise ExtractionError("invalid CPIO header field") from error
        inode, mode, _uid, _gid, links, _mtime, size = fields[:7]
        name_size = fields[11]
        offset += 110
        name_bytes = payload[offset : offset + name_size]
        if len(name_bytes) != name_size or not name_bytes.endswith(b"\x00"):
            raise ExtractionError("truncated CPIO pathname")
        name = os.fsdecode(name_bytes[:-1])
        offset = (offset + name_size + 3) & ~3
        contents = payload[offset : offset + size]
        if len(contents) != size:
            raise ExtractionError("truncated CPIO data for %s" % name)
        offset = (offset + size + 3) & ~3
        if name == "TRAILER!!!":
            return
        yield name, inode, mode, links, contents


def extract_rpm(archive, root):
    with open(archive, "rb") as rpm:
        entries = list(_cpio_entries(_rpm_payload(rpm.read())))

    hardlinks = {}
    for name, inode, mode, links, contents in entries:
        destination = _safe_destination(root, name)
        if destination is None:
            continue
        kind = stat.S_IFMT(mode)
        if kind == stat.S_IFDIR:
            _write_directory(root, destination, mode)
        elif kind == stat.S_IFLNK:
            _write_symlink(root, destination, os.fsdecode(contents))
        elif kind == stat.S_IFREG:
            if links > 1:
                group = hardlinks.setdefault(inode, {"source": None, "pending": []})
                if contents:
                    _write_file(root, destination, contents, mode)
                    group["source"] = destination
                    for pending, _pending_mode in group["pending"]:
                        _write_hardlink(root, pending, destination)
                    group["pending"] = []
                elif group["source"]:
                    _write_hardlink(root, destination, group["source"])
                else:
                    group["pending"].append((destination, mode))
            else:
                _write_file(root, destination, contents, mode)
        else:
            raise ExtractionError("unsupported CPIO file type for %s" % name)

    for group in hardlinks.values():
        if group["source"] is None and group["pending"]:
            source, source_mode = group["pending"].pop(0)
            _write_file(root, source, b"", source_mode)
            for pending, _pending_mode in group["pending"]:
                _write_hardlink(root, pending, source)


def _gzip_members(data):
    remaining = data
    while remaining:
        decoder = zlib.decompressobj(16 + zlib.MAX_WBITS)
        unpacked = decoder.decompress(remaining) + decoder.flush()
        if not decoder.eof:
            raise ExtractionError("truncated APK gzip member")
        yield unpacked
        if not decoder.unused_data or len(decoder.unused_data) == len(remaining):
            return
        remaining = decoder.unused_data


def _is_apk_metadata(name):
    normalized = name[2:] if name.startswith("./") else name
    return normalized.startswith(_APK_METADATA)


def _extract_apk_tar(data, root):
    with tarfile.open(fileobj=io.BytesIO(data), mode="r:") as archive:
        for member in archive:
            if _is_apk_metadata(member.name):
                continue
            destination = _safe_destination(root, member.name)
            if destination is None:
                continue
            if member.isdir():
                _write_directory(root, destination, member.mode)
            elif member.isfile():
                extracted = archive.extractfile(member)
                if extracted is None:
                    raise ExtractionError("missing APK file contents: %s" % member.name)
                _write_file(root, destination, extracted.read(), member.mode)
            elif member.issym():
                _write_symlink(root, destination, member.linkname)
            elif member.islnk():
                target = _safe_destination(root, member.linkname)
                if target is None:
                    raise ExtractionError("empty APK hardlink target: %s" % member.name)
                _write_hardlink(root, destination, target)
            else:
                raise ExtractionError("unsupported APK file type for %s" % member.name)


def extract_apk(archive, root):
    with open(archive, "rb") as package:
        members = list(_gzip_members(package.read()))
    if not members:
        raise ExtractionError("APK contains no gzip members")
    for member in members:
        _extract_apk_tar(member, root)


def main(argv):
    parser = argparse.ArgumentParser()
    parser.add_argument("--format", choices=("apk", "rpm"), required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("archives", nargs="+")
    args = parser.parse_args(argv)

    root = os.path.abspath(args.output)
    os.makedirs(root, exist_ok=True)
    extractor = extract_apk if args.format == "apk" else extract_rpm
    for archive in args.archives:
        extractor(archive, root)
    _normalize_tree(root)
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1:]))
    except (ExtractionError, OSError, tarfile.TarError, ValueError) as error:
        print("extract_packages.py: %s" % error, file=sys.stderr)
        sys.exit(1)

