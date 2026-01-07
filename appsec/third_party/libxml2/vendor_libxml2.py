#!/usr/bin/env -S uv run
# /// script
# requires-python = ">=3.10"
# ///
"""
Downloads and vendors libxml2 for the appsec extension.

This script:
1. Downloads the libxml2 tarball from GNOME
2. Verifies the SHA256 checksum
3. Extracts only the necessary files for building
4. Removes everything else to minimize repository size
"""

import hashlib
import os
import re
import shutil
import sys
import tarfile
import tempfile
import urllib.request
from pathlib import Path

LIBXML2_VERSION = "2.15.1"
LIBXML2_URL = f"https://download.gnome.org/sources/libxml2/2.15/libxml2-{LIBXML2_VERSION}.tar.xz"
LIBXML2_SHA256 = "c008bac08fd5c7b4a87f7b8a71f283fa581d80d80ff8d2efd3b26224c39bc54c"

SOURCE_FILES = [
    "buf.c",
    "c14n.c",
    "catalog.c",
    "chvalid.c",
    "debugXML.c",
    "dict.c",
    "encoding.c",
    "entities.c",
    "error.c",
    "globals.c",
    "hash.c",
    "HTMLparser.c",
    "HTMLtree.c",
    "list.c",
    "nanohttp.c",
    "parser.c",
    "parserInternals.c",
    "pattern.c",
    "relaxng.c",
    "SAX2.c",
    "schematron.c",
    "threads.c",
    "tree.c",
    "uri.c",
    "valid.c",
    "xinclude.c",
    "xlink.c",
    "xmlIO.c",
    "xmlmemory.c",
    "xmlmodule.c",
    "xmlreader.c",
    "xmlregexp.c",
    "xmlsave.c",
    "xmlschemas.c",
    "xmlschemastypes.c",
    "xmlstring.c",
    "xmlwriter.c",
    "xpath.c",
    "xpointer.c",
]

HEADER_FILES = [
    "include/libxml/c14n.h",
    "include/libxml/catalog.h",
    "include/libxml/chvalid.h",
    "include/libxml/debugXML.h",
    "include/libxml/dict.h",
    "include/libxml/encoding.h",
    "include/libxml/entities.h",
    "include/libxml/globals.h",
    "include/libxml/hash.h",
    "include/libxml/HTMLparser.h",
    "include/libxml/HTMLtree.h",
    "include/libxml/list.h",
    "include/libxml/nanoftp.h",
    "include/libxml/nanohttp.h",
    "include/libxml/parser.h",
    "include/libxml/parserInternals.h",
    "include/libxml/pattern.h",
    "include/libxml/relaxng.h",
    "include/libxml/SAX.h",
    "include/libxml/SAX2.h",
    "include/libxml/schemasInternals.h",
    "include/libxml/schematron.h",
    "include/libxml/threads.h",
    "include/libxml/tree.h",
    "include/libxml/uri.h",
    "include/libxml/valid.h",
    "include/libxml/xinclude.h",
    "include/libxml/xlink.h",
    "include/libxml/xmlIO.h",
    "include/libxml/xmlautomata.h",
    "include/libxml/xmlerror.h",
    "include/libxml/xmlexports.h",
    "include/libxml/xmlmemory.h",
    "include/libxml/xmlmodule.h",
    "include/libxml/xmlreader.h",
    "include/libxml/xmlregexp.h",
    "include/libxml/xmlsave.h",
    "include/libxml/xmlschemas.h",
    "include/libxml/xmlschemastypes.h",
    "include/libxml/xmlstring.h",
    "include/libxml/xmlunicode.h",
    "include/libxml/xmlwriter.h",
    "include/libxml/xpath.h",
    "include/libxml/xpathInternals.h",
    "include/libxml/xpointer.h",
    "include/libxml/xmlversion.h.in",
]

PRIVATE_HEADERS = [
    "include/private/buf.h",
    "include/private/cata.h",
    "include/private/dict.h",
    "include/private/enc.h",
    "include/private/entities.h",
    "include/private/error.h",
    "include/private/globals.h",
    "include/private/html.h",
    "include/private/io.h",
    "include/private/lint.h",
    "include/private/memory.h",
    "include/private/parser.h",
    "include/private/regexp.h",
    "include/private/save.h",
    "include/private/string.h",
    "include/private/threads.h",
    "include/private/tree.h",
    "include/private/xinclude.h",
    "include/private/xpath.h",
]

OTHER_FILES = [
    "CMakeLists.txt",
    "config.h.cmake.in",
    "configure.ac",  # Needed to extract version numbers
    "Copyright",
    "libxml.h",  # Internal header
    "timsort.h",  # Internal header used by some source files
    "libxml2-config.cmake.cmake.in",  # CMake config template
    "libxml-2.0.pc.in",  # pkg-config template
    "xml2-config.in",  # xml2-config script template
    "VERSION",  # Version file needed by CMake in 2.15+
    # Generated include files needed by source files
    "codegen/charset.inc",
    "codegen/escape.inc",
    "codegen/html5ent.inc",
    "codegen/ranges.inc",
    "codegen/unicode.inc",
]


def download_file(url: str, dest: Path) -> None:
    """Download a file from URL to destination."""
    print(f"Downloading {url}...")
    urllib.request.urlretrieve(url, dest)
    print(f"Downloaded to {dest}")


def verify_checksum(file_path: Path, expected_sha256: str) -> bool:
    """Verify SHA256 checksum of a file."""
    print(f"Verifying checksum...")
    sha256 = hashlib.sha256()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(8192), b""):
            sha256.update(chunk)
    actual = sha256.hexdigest()
    if actual != expected_sha256:
        print(f"Checksum mismatch!")
        print(f"  Expected: {expected_sha256}")
        print(f"  Actual:   {actual}")
        return False
    print(f"Checksum verified: {actual}")
    return True


def extract_needed_files(tarball: Path, dest_dir: Path) -> None:
    """Extract only needed files from the tarball."""
    print(f"Extracting needed files to {dest_dir}...")

    needed_files = set()
    for f in SOURCE_FILES + OTHER_FILES + PRIVATE_HEADERS:
        needed_files.add(f)
    for f in HEADER_FILES:
        needed_files.add(f)

    dest_dir.mkdir(parents=True, exist_ok=True)

    with tarfile.open(tarball, "r:xz") as tar:
        # Get the top-level directory name (e.g., "libxml2-2.12.9")
        top_dir = None
        for member in tar.getmembers():
            parts = member.name.split("/")
            if len(parts) > 0:
                top_dir = parts[0]
                break

        if not top_dir:
            raise RuntimeError("Could not determine top-level directory in tarball")

        print(f"Top-level directory: {top_dir}")

        extracted_count = 0
        for member in tar.getmembers():
            # Skip the top-level directory itself
            if member.name == top_dir:
                continue

            # Get the path relative to top-level dir
            if not member.name.startswith(top_dir + "/"):
                continue
            rel_path = member.name[len(top_dir) + 1:]

            if rel_path in needed_files:
                # Extract to destination, removing top-level dir
                member_copy = tarfile.TarInfo(name=rel_path)
                member_copy.size = member.size
                member_copy.mode = member.mode

                if member.isfile():
                    dest_path = dest_dir / rel_path
                    dest_path.parent.mkdir(parents=True, exist_ok=True)
                    with tar.extractfile(member) as src:
                        with open(dest_path, "wb") as dst:
                            dst.write(src.read())
                    extracted_count += 1
                    print(f"  Extracted: {rel_path}")

        print(f"Extracted {extracted_count} files")

        missing = []
        for f in needed_files:
            if not (dest_dir / f).exists():
                missing.append(f)

        if missing:
            print(f"Warning: Missing files:")
            for f in missing:
                print(f"  {f}")


def patch_cmakelists(cmake_path: Path) -> None:
    """Patch CMakeLists.txt to remove documentation install rules."""
    print(f"Patching {cmake_path}...")

    content = cmake_path.read_text()

    # Remove man page and documentation install rules (lines 606-615 in original)
    # These try to install files from doc/ directory that we don't vendor
    lines_to_remove = [
        'install(FILES doc/xml2-config.1 DESTINATION ${CMAKE_INSTALL_MANDIR}/man1 COMPONENT documentation)',
        'install(FILES doc/xmlcatalog.1 DESTINATION ${CMAKE_INSTALL_MANDIR}/man1 COMPONENT documentation)',
        'install(FILES doc/xmllint.1 DESTINATION ${CMAKE_INSTALL_MANDIR}/man1 COMPONENT documentation)',
        'install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/libxml.m4 DESTINATION ${CMAKE_INSTALL_DATADIR}/aclocal)',
    ]

    for line in lines_to_remove:
        content = content.replace(line, "# " + line + "  # Removed by vendor script")

    # Remove the install(DIRECTORY doc/ ...) block
    content = re.sub(
        r'install\(DIRECTORY doc/.*?PATTERN "\*\.xsl" EXCLUDE\)',
        '# install(DIRECTORY doc/ ...) removed by vendor script',
        content,
        flags=re.DOTALL
    )

    cmake_path.write_text(content)
    print("  Patched: removed doc install rules")


def main() -> int:
    script_dir = Path(__file__).parent
    dest_dir = script_dir / "src"

    if dest_dir.exists():
        print(f"Removing existing {dest_dir}...")
        shutil.rmtree(dest_dir)

    with tempfile.NamedTemporaryFile(suffix=".tar.xz", delete=False) as tmp:
        tmp_path = Path(tmp.name)

    try:
        download_file(LIBXML2_URL, tmp_path)

        if not verify_checksum(tmp_path, LIBXML2_SHA256):
            return 1

        extract_needed_files(tmp_path, dest_dir)

        patch_cmakelists(dest_dir / "CMakeLists.txt")

        # Write version file for reference
        version_file = script_dir / "VERSION"
        version_file.write_text(f"{LIBXML2_VERSION}\n")
        print(f"Wrote version to {version_file}")

        total_size = sum(f.stat().st_size for f in dest_dir.rglob("*") if f.is_file())
        print(f"\nVendored libxml2 {LIBXML2_VERSION}")
        print(f"Total size: {total_size / 1024:.1f} KB")

        return 0

    finally:
        if tmp_path.exists():
            tmp_path.unlink()


if __name__ == "__main__":
    sys.exit(main())
