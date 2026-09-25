#!/usr/bin/env python3
"""Compare native and CMake runtime ABI inventories without executing foreign ELF.

Pass --native as the native core .build directory and --reference as the old
<target>.runtime directory. Symbol multiplicities in static archives, exported
and undefined dynamic symbols, SONAME, NEEDED and the glibc floor are checked.
"""

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
import subprocess


def output(*command):
    return subprocess.check_output(command, text=True, stderr=subprocess.PIPE)


def symbols(path, dynamic=False):
    args = ["nm", "--format=posix", "--extern-only"]
    if dynamic:
        args.append("--dynamic")
    result = Counter()
    for line in output(*args, str(path)).splitlines():
        columns = line.split()
        if len(columns) >= 2 and len(columns[1]) == 1 and columns[1].isalpha():
            result[(columns[0], columns[1])] += 1
    return result


def dynamic(path):
    text = output("readelf", "--wide", "--dynamic", str(path))
    if re.search(r"\((?:RPATH|RUNPATH|TEXTREL)\)", text):
        raise AssertionError("Forbidden dynamic metadata: " + str(path))
    return sorted(re.findall(r"\((NEEDED|SONAME)\).*?\[(.*?)\]", text))


def compare(native, reference, shared, libc):
    left, right = symbols(native, shared), symbols(reference, shared)
    # CMake compiles matching static/shared libunwind units twice; symbol
    # inventories must remain identical after safe object reuse.
    assert left == right, {"native": str(native), "added": list((left - right).items())[:30], "removed": list((right - left).items())[:30]}
    if shared:
        assert dynamic(native) == dynamic(reference), (dynamic(native), dynamic(reference))
        versions = [tuple(map(int, version.split("."))) for version in re.findall(r"@GLIBC_([0-9.]+)", output("readelf", "--wide", "--dyn-syms", str(native)))]
        if libc == "glibc":
            # AArch64's first upstream glibc release is 2.17.
            assert not versions or max(versions) <= (2, 17), max(versions)
        else:
            assert not versions, "Musl library imports glibc versioned symbols"
            assert any(name.startswith("libc.musl-") for kind, name in dynamic(native) if kind == "NEEDED"), dynamic(native)
    return dict(native=str(native), reference=str(reference), symbol_entries=sum(left.values()),
                native_sha256=hashlib.sha256(native.read_bytes()).hexdigest(),
                reference_sha256=hashlib.sha256(reference.read_bytes()).hexdigest(), parity="pass")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--native", type=Path, required=True)
    parser.add_argument("--reference", type=Path, required=True)
    parser.add_argument("--arch", choices=["x86_64", "aarch64"], required=True)
    parser.add_argument("--libc", choices=["glibc", "musl"], required=True)
    parser.add_argument("--asan", type=Path, help="Native sanitizer .build directory")
    args = parser.parse_args()
    results = []
    for lib in ["libc++", "libc++abi", "libunwind"]:
        for suffix in [".a", ".so.1.0"]:
            results.append(compare(args.native / "runtimes/lib" / (lib + suffix), args.reference / "lib" / (lib + suffix), suffix != ".a", args.libc))
    builtins = "libclang_rt.builtins-" + args.arch + ".a"
    results.append(compare(args.native / "builtins/compiler-rt/lib/linux" / builtins, args.reference / "lib/linux" / builtins, False, args.libc))
    if args.asan:
        for kind, suffix in [("asan", ".so"), ("asan", ".a"), ("asan_cxx", ".a"), ("asan-preinit", ".a")]:
            name = "libclang_rt." + kind + "-" + args.arch + suffix
            results.append(compare(args.asan / "runtimes/compiler-rt/lib/linux" / name, args.reference / "lib/linux" / name, suffix == ".so", args.libc))
    print(json.dumps(results, indent=2))


if __name__ == "__main__":
    main()
