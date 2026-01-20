#!/usr/bin/env -S uv run
# /// script
# requires-python = ">=3.10"
# ///
"""
Downloads and vendors libxml2 for the appsec extension.

This script:
1. Downloads the libxml2 tarball from GNOME
2. Verifies the SHA256 checksum (if provided)
3. Parses CMakeLists.txt to determine which files are needed
4. Extracts only the necessary files for building with our minimal config
5. Removes everything else to minimize repository size

Supports libxml2 versions 2.15.0 and later.

Our minimal build configuration (from appsec/cmake/libxml2.cmake):
- PUSH=ON, TREE=ON, THREADS=ON (conditionally)
- Everything else OFF (no HTML, XPATH, SCHEMAS, SAX1, etc.)
"""

# Private headers that are not needed for our minimal build
# These are for features we've disabled
EXCLUDED_PRIVATE_HEADERS = {
    "include/private/lint.h",      # For xmllint/shell, not library functionality
    "include/private/xinclude.h",  # For XInclude, which is disabled
}

import argparse
import hashlib
import re
import shutil
import sys
import tarfile
import tempfile
import urllib.request
from pathlib import Path

MIN_VERSION = (2, 15, 0)


def download_file(url: str, dest: Path) -> None:
    """Download a file from URL to destination."""
    print(f"Downloading {url}...")
    urllib.request.urlretrieve(url, dest)
    print(f"Downloaded to {dest}")


def verify_checksum(file_path: Path, expected_sha256: str) -> bool:
    """Verify SHA256 checksum of a file."""
    print("Verifying checksum...")
    sha256 = hashlib.sha256()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(8192), b""):
            sha256.update(chunk)
    actual = sha256.hexdigest()
    if actual != expected_sha256:
        print("Checksum mismatch!")
        print(f"  Expected: {expected_sha256}")
        print(f"  Actual:   {actual}")
        return False
    print(f"Checksum verified: {actual}")
    return True


def parse_version(version_str: str) -> tuple[int, int, int]:
    """Parse version string into tuple of (major, minor, micro)."""
    parts = version_str.split(".")
    return (int(parts[0]), int(parts[1]), int(parts[2]) if len(parts) > 2 else 0)


def parse_cmake_sources(cmake_content: str) -> set[str]:
    """Parse CMakeLists.txt to extract the base source file list (LIBXML2_SRCS)."""
    sources = set()
    srcs_match = re.search(
        r'set\s*\(\s*LIBXML2_SRCS\s+(.*?)\)',
        cmake_content,
        re.DOTALL
    )
    if srcs_match:
        srcs_block = srcs_match.group(1)
        for match in re.finditer(r'(\w+\.c)', srcs_block):
            sources.add(match.group(1))
    return sources


def parse_cmake_headers(cmake_content: str) -> set[str]:
    """Parse CMakeLists.txt to extract public header file list."""
    headers = set()
    hdrs_match = re.search(
        r'set\s*\(\s*LIBXML2_HDRS\s+(.*?)\)',
        cmake_content,
        re.DOTALL
    )
    if hdrs_match:
        hdrs_block = hdrs_match.group(1)
        for match in re.finditer(r'(include/libxml/\w+\.h)', hdrs_block):
            headers.add(match.group(1))

    # Always include xmlversion.h.in as it's the template
    headers.add("include/libxml/xmlversion.h.in")
    return headers


def detect_files_from_tarball(tar: tarfile.TarFile, top_dir: str) -> set[str]:
    """
    Detect which files to extract by parsing CMakeLists.txt from the tarball.
    Only includes files needed for our minimal build configuration.
    """
    needed_files = set()

    # Extract and parse CMakeLists.txt
    cmake_path = f"{top_dir}/CMakeLists.txt"
    cmake_member = None
    for member in tar.getmembers():
        if member.name == cmake_path:
            cmake_member = member
            break

    if not cmake_member:
        raise RuntimeError("CMakeLists.txt not found in tarball")

    cmake_content = tar.extractfile(cmake_member).read().decode('utf-8')

    # Get base sources (in 2.15+, these are the minimal core sources)
    sources = parse_cmake_sources(cmake_content)
    headers = parse_cmake_headers(cmake_content)

    needed_files.update(sources)
    needed_files.update(headers)

    # Always needed files
    always_needed = {
        "CMakeLists.txt",
        "config.h.cmake.in",
        "Copyright",
        "libxml.h",
        "libxml2-config.cmake.cmake.in",
        "libxml-2.0.pc.in",
        "xml2-config.in",
        "VERSION",
        "timsort.h",
    }
    needed_files.update(always_needed)

    # Scan tarball for additional files
    for member in tar.getmembers():
        if not member.name.startswith(top_dir + "/"):
            continue
        rel_path = member.name[len(top_dir) + 1:]

        # Private headers (include/private/*.h), excluding those we don't need
        if rel_path.startswith("include/private/") and rel_path.endswith(".h"):
            if rel_path not in EXCLUDED_PRIVATE_HEADERS:
                needed_files.add(rel_path)

        # codegen/*.inc files (character set tables, etc.)
        if rel_path.startswith("codegen/") and rel_path.endswith(".inc"):
            needed_files.add(rel_path)

    return needed_files


def extract_needed_files(tarball: Path, dest_dir: Path) -> None:
    """Extract only needed files from the tarball."""
    print(f"Extracting needed files to {dest_dir}...")

    dest_dir.mkdir(parents=True, exist_ok=True)

    with tarfile.open(tarball, "r:xz") as tar:
        # Get the top-level directory name (e.g., "libxml2-2.15.1")
        top_dir = None
        for member in tar.getmembers():
            parts = member.name.split("/")
            if len(parts) > 0:
                top_dir = parts[0]
                break

        if not top_dir:
            raise RuntimeError("Could not determine top-level directory in tarball")

        print(f"Top-level directory: {top_dir}")

        # Detect which files we need
        needed_files = detect_files_from_tarball(tar, top_dir)
        print(f"Detected {len(needed_files)} files to extract")

        extracted_count = 0
        for member in tar.getmembers():
            if member.name == top_dir:
                continue

            if not member.name.startswith(top_dir + "/"):
                continue
            rel_path = member.name[len(top_dir) + 1:]

            if rel_path in needed_files:
                if member.isfile():
                    dest_path = dest_dir / rel_path
                    dest_path.parent.mkdir(parents=True, exist_ok=True)
                    with tar.extractfile(member) as src:
                        with open(dest_path, "wb") as dst:
                            dst.write(src.read())
                    extracted_count += 1
                    print(f"  Extracted: {rel_path}")

        print(f"Extracted {extracted_count} files")


def get_version_url(version: str) -> str:
    """Get the download URL for a given libxml2 version."""
    major_minor = ".".join(version.split(".")[:2])
    return f"https://download.gnome.org/sources/libxml2/{major_minor}/libxml2-{version}.tar.xz"


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Download and vendor libxml2 for appsec extension"
    )
    parser.add_argument(
        "version",
        help="libxml2 version to vendor (e.g., 2.15.1)"
    )
    parser.add_argument(
        "--sha256",
        help="Expected SHA256 checksum (optional, skips verification if not provided)"
    )

    args = parser.parse_args()

    # Check minimum version
    version_tuple = parse_version(args.version)
    if version_tuple < MIN_VERSION:
        print(f"Error: libxml2 version {args.version} is not supported.")
        print(f"Minimum supported version is {'.'.join(map(str, MIN_VERSION))}")
        return 1

    script_dir = Path(__file__).parent
    dest_dir = script_dir / "src"

    if dest_dir.exists():
        print(f"Removing existing {dest_dir}...")
        shutil.rmtree(dest_dir)

    with tempfile.NamedTemporaryFile(suffix=".tar.xz", delete=False) as tmp:
        tmp_path = Path(tmp.name)

    try:
        url = get_version_url(args.version)
        download_file(url, tmp_path)

        if args.sha256:
            if not verify_checksum(tmp_path, args.sha256):
                return 1
        else:
            sha256 = hashlib.sha256()
            with open(tmp_path, "rb") as f:
                for chunk in iter(lambda: f.read(8192), b""):
                    sha256.update(chunk)
            print(f"SHA256: {sha256.hexdigest()}")

        extract_needed_files(tmp_path, dest_dir)

        # Write version file for reference
        version_file = script_dir / "VERSION"
        version_file.write_text(f"{args.version}\n")
        print(f"Wrote version to {version_file}")

        total_size = sum(f.stat().st_size for f in dest_dir.rglob("*") if f.is_file())
        print(f"\nVendored libxml2 {args.version}")
        print(f"Total size: {total_size / 1024:.1f} KB")

        return 0

    finally:
        if tmp_path.exists():
            tmp_path.unlink()


if __name__ == "__main__":
    sys.exit(main())
