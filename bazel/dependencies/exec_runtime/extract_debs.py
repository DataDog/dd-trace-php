#!/usr/bin/env python3
"""Extract checksum-verified Debian archives into one normalized runtime root."""

import argparse
import io
import os
import shutil
import stat
import tarfile


def ar_members(path):
    with open(path, "rb") as archive:
        if archive.read(8) != b"!<arch>\n":
            raise ValueError(f"{path}: invalid ar signature")
        while True:
            header = archive.read(60)
            if not header:
                return
            if len(header) != 60 or header[58:] != b"`\n":
                raise ValueError(f"{path}: malformed ar member header")
            name = header[:16].decode("ascii").strip().rstrip("/")
            size = int(header[48:58].decode("ascii").strip())
            data = archive.read(size)
            if len(data) != size:
                raise ValueError(f"{path}: truncated ar member {name}")
            if size % 2:
                archive.read(1)
            yield name, data


def safe_path(root, name):
    normalized = os.path.normpath(name)
    if os.path.isabs(name) or normalized == ".." or normalized.startswith("../"):
        raise ValueError(f"unsafe archive path: {name!r}")
    return os.path.join(root, normalized)


def extract_data(root, data):
    deferred_links = []
    with tarfile.open(fileobj=io.BytesIO(data), mode="r:*") as archive:
        for member in archive:
            output = safe_path(root, member.name)
            if member.isdir():
                os.makedirs(output, exist_ok=True)
                continue
            if member.ischr() or member.isblk() or member.isfifo():
                raise ValueError(f"unsupported special file: {member.name!r}")
            os.makedirs(os.path.dirname(output), exist_ok=True)
            if os.path.lexists(output):
                if os.path.isdir(output) and not os.path.islink(output):
                    shutil.rmtree(output)
                else:
                    os.unlink(output)
            if member.issym():
                if os.path.isabs(member.linkname):
                    target = safe_path(root, member.linkname.lstrip("/"))
                    linkname = os.path.relpath(target, os.path.dirname(output))
                else:
                    linkname = member.linkname
                resolved = os.path.normpath(os.path.join(os.path.dirname(member.name), member.linkname))
                if not os.path.isabs(member.linkname):
                    safe_path(root, resolved)
                os.symlink(linkname, output)
            elif member.islnk():
                deferred_links.append((output, safe_path(root, member.linkname)))
            elif member.isfile():
                source = archive.extractfile(member)
                if source is None:
                    raise ValueError(f"missing file payload: {member.name!r}")
                with open(output, "wb") as destination:
                    shutil.copyfileobj(source, destination)
                os.chmod(output, stat.S_IMODE(member.mode))
                os.utime(output, (0, 0), follow_symlinks=False)
            else:
                raise ValueError(f"unsupported tar entry: {member.name!r}")
    for output, target in deferred_links:
        if not os.path.isfile(target):
            raise ValueError(f"hardlink target was not extracted: {target!r}")
        os.link(target, output)


def normalize(root):
    for directory, subdirectories, files in os.walk(root, topdown=False):
        subdirectories.sort()
        files.sort()
        for name in files:
            path = os.path.join(directory, name)
            if not os.path.islink(path):
                os.utime(path, (0, 0), follow_symlinks=False)
        os.chmod(directory, 0o755)
        os.utime(directory, (0, 0), follow_symlinks=False)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", required=True)
    parser.add_argument("archives", nargs="+")
    arguments = parser.parse_args()
    root = os.path.abspath(arguments.output)
    os.makedirs(root, exist_ok=True)
    for path in arguments.archives:
        members = dict(ar_members(path))
        if members.get("debian-binary") != b"2.0\n":
            raise ValueError(f"{path}: unsupported Debian archive version")
        data_names = [name for name in members if name.startswith("data.tar.")]
        if len(data_names) != 1:
            raise ValueError(f"{path}: expected one data archive, found {data_names}")
        extract_data(root, members[data_names[0]])
    normalize(root)


if __name__ == "__main__":
    main()
