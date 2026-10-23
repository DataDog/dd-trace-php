# /// script
# requires-python = ">=3.12"
# ///
"""Prepare the pinned anyxml source with our parser patches."""

import hashlib
import io
import pathlib
import shutil
import subprocess
import tarfile
import tempfile
import urllib.request

HERE = pathlib.Path(__file__).resolve().parent
VERSION = "0.15.1"
# The published crate checksum, retained from the original Cargo.lock.
CHECKSUM = "afa1726059014aac226f0aabef098daf5aca059affcaf683bc931cc76152c321"
PATCHES = (
    "internal-parameter-entities.patch",
    "resource-limits.patch",
    "standalone-whitespace.patch",
    "skipped-dtd-entities.patch",
    "character-reference-events.patch",
)


def main():
    fingerprints = [
        hashlib.sha256((HERE / name).read_bytes()).hexdigest()
        for name in PATCHES
    ]
    vendor = HERE / "vendor"
    source = vendor / "anyxml"
    stamp = source / ".prepared"
    identity = "\n".join([VERSION, CHECKSUM, *fingerprints]) + "\n"
    if stamp.exists() and stamp.read_text() == identity:
        return

    vendor.mkdir(exist_ok=True)
    archive = vendor / f"anyxml-{VERSION}.crate"
    if archive.exists():
        data = archive.read_bytes()
    else:
        url = f"https://static.crates.io/crates/anyxml/anyxml-{VERSION}.crate"
        with urllib.request.urlopen(url, timeout=30) as response:
            data = response.read()
    if hashlib.sha256(data).hexdigest() != CHECKSUM:
        raise RuntimeError("anyxml archive checksum does not match Cargo.lock")
    archive.write_bytes(data)

    with tempfile.TemporaryDirectory(dir=vendor) as temporary:
        with tarfile.open(fileobj=io.BytesIO(data)) as crate:
            crate.extractall(temporary, filter="data")
        prepared = pathlib.Path(temporary) / f"anyxml-{VERSION}"
        for name in PATCHES:
            subprocess.run(
                ["patch", "--batch", "--fuzz=0", "-p1", "-i", str(HERE / name)],
                cwd=prepared,
                check=True,
            )
        (prepared / ".prepared").write_text(identity)
        if source.exists():
            shutil.rmtree(source)
        prepared.rename(source)


if __name__ == "__main__":
    main()
