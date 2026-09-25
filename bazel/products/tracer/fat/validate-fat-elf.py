#!/usr/bin/env python3
"""Fail-closed validation for a published monolithic ddtrace extension."""

import pathlib
import re
import subprocess
import sys


def run(*args: str) -> str:
    return subprocess.run(args, check=True, stdout=subprocess.PIPE, text=True).stdout


objdump, nm, binary_name, debug_name, symbols_name, architecture, libc = sys.argv[1:]
binary = pathlib.Path(binary_name)
debug = pathlib.Path(debug_name)

header = run(objdump, "-f", str(binary))
expected_format = {
    "aarch64": "elf64-littleaarch64",
    "x86_64": "elf64-x86-64",
}[architecture]
if "file format " + expected_format not in header:
    raise SystemExit(f"wrong ELF architecture: expected {expected_format!r}\n{header}")
start = re.search(r"start address:? 0x([0-9a-fA-F]+)", header)
if not start or int(start.group(1), 16) == 0:
    raise SystemExit("fat ddtrace.so lacks the sidecar ELF entry point")
entry_address = int(start.group(1), 16)

# The published binary intentionally retains only its public symbol table.
# The split-debug companion preserves local symbols at the same addresses, so
# use it to prove that e_entry names the hidden sidecar entry implementation.
symbols = run(nm, "--defined-only", "--format=posix", str(debug))
entry_symbols = []
for line in symbols.splitlines():
    fields = line.split()
    if len(fields) >= 3 and fields[0] == "ddog_spawn_direct_entry":
        entry_symbols.append(int(fields[2], 16))
if entry_symbols != [entry_address]:
    raise SystemExit(
        "ELF entry does not resolve exactly to local ddog_spawn_direct_entry: "
        f"entry=0x{entry_address:x} symbols={entry_symbols!r}"
    )

dynamic = run(objdump, "-p", str(binary))
sonames = re.findall(r"^\s*SONAME\s+(\S+)\s*$", dynamic, re.MULTILINE)
if sonames != ["ddtrace.so"]:
    raise SystemExit(f"unexpected SONAME records: {sonames!r}")
for forbidden in ("RPATH", "RUNPATH", "TEXTREL"):
    if re.search(rf"^\s*{forbidden}\b", dynamic, re.MULTILINE):
        raise SystemExit(f"published ddtrace.so contains forbidden {forbidden}")

needed = set(re.findall(r"^\s*NEEDED\s+(\S+)\s*$", dynamic, re.MULTILINE))
required = {"libcurl.so.4"}
if libc == "glibc":
    required |= {"libc.so.6", "libdl.so.2", "libm.so.6", "libpthread.so.0", "librt.so.1"}
else:
    required |= {"libc.musl-aarch64.so.1" if architecture == "aarch64" else "libc.musl-x86_64.so.1"}
missing = required - needed
if missing:
    raise SystemExit(f"missing required DT_NEEDED entries: {sorted(missing)!r}")

versions = [(int(a), int(b)) for a, b in re.findall(rb"GLIBC_(\d+)\.(\d+)", binary.read_bytes())]
if libc == "glibc" and versions and max(versions) > (2, 17):
    raise SystemExit(f"GLIBC requirement exceeds 2.17: {max(versions)!r}")

defined_output = run(nm, "-D", "--defined-only", "--format=posix", str(binary))
defined = {line.split()[0] for line in defined_output.splitlines() if line.split()}
expected = {
    line.strip()
    for line in pathlib.Path(symbols_name).read_text(encoding="utf-8").splitlines()
    if line.strip()
}
if defined != expected:
    raise SystemExit(
        "dynamic export mismatch: missing=%r extra=%r"
        % (sorted(expected - defined), sorted(defined - expected))
    )

undefined = run(nm, "-D", "--undefined-only", "--format=posix", str(binary))
for php_symbol in ("_emalloc", "zend_alter_ini_entry_chars", "zend_error"):
    match = re.search(rf"^{re.escape(php_symbol)}\s+(\S+)", undefined, re.MULTILINE)
    if not match:
        raise SystemExit(f"expected unresolved PHP ABI symbol is absent: {php_symbol}")
    if match.group(1).lower() != "w":
        raise SystemExit(f"PHP ABI symbol was not weakened for standalone sidecar startup: {php_symbol}")

binary_sections = run(objdump, "-h", str(binary))
debug_sections = run(objdump, "-h", str(debug))
if ".gnu_debuglink" not in binary_sections or ".debug_info" in binary_sections:
    raise SystemExit("published ddtrace.so was not split and debug-linked")
if ".debug_info" not in debug_sections:
    raise SystemExit("ddtrace.so.debug lacks DWARF debug information")

for path in (binary, debug):
    contents = path.read_bytes()
    for forbidden in (rb"/home/", rb"/worker/", rb"/execroot/", rb"/bazel-out/"):
        # Require the absolute path to begin a NUL-delimited string.  Product
        # fixtures legitimately contain relative values such as
        # "usr/home/user/...", which are not build-machine paths.
        if re.search(rb"(?:^|\x00)" + forbidden, contents):
            raise SystemExit(f"{path.name} contains forbidden build path {forbidden!r}")
