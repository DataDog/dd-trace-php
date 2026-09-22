#!/bin/sh
set -eu

busybox=$1
launcher=$2
tools_root=$3
output=$4
scratch="${output}.work"

"$busybox" rm -rf "$scratch"
"$busybox" mkdir -p "$scratch/bin" "$scratch/share"
for command in autoconf make perl python3 sh tar uname; do
    "$busybox" cp "$launcher" "$scratch/bin/$command"
    "$busybox" chmod 0755 "$scratch/bin/$command"
done
"$busybox" cp "$launcher" "$scratch/bin/cmake"
"$busybox" chmod 0755 "$scratch/bin/cmake"
"$busybox" cp -R "$tools_root/usr/share/cmake" "$scratch/share/cmake"

export HERMETIC_TOOLS_ROOT=$tools_root
export PATH=$scratch/bin
export SHELL=$scratch/bin/sh
export CONFIG_SHELL=$scratch/bin/sh

{
    machine=$("$scratch/bin/uname" -m)
    [ "$machine" = aarch64 ] || { echo "unexpected execution architecture: $machine" >&2; exit 1; }
    printf 'uname: %s\n' "$machine"
    make_version=$("$scratch/bin/make" --version)
    printf '%s\n' "$make_version" | "$busybox" head -1
    cmake_version=$("$scratch/bin/cmake" --version)
    printf '%s\n' "$cmake_version" | "$busybox" head -1
    "$scratch/bin/perl" -Mstrict -MConfig -e '
        for (@INC, values %INC) {
            next if ref $_;
            die "non-hermetic Perl path: $_\n" unless index($_, $ENV{HERMETIC_TOOLS_ROOT}) == 0;
        }
        print "$^V\n";
    '
    LAUNCHER=$launcher "$scratch/bin/python3" -c '
import os
import struct
import subprocess
import sys

for command in (["tar", "--version"], ["autoconf", "--version"]):
    completed = subprocess.run(command, check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    print(completed.stdout.splitlines()[0])

root = os.environ["HERMETIC_TOOLS_ROOT"]
for path in [entry for entry in sys.path if entry] + [sys.prefix, os.__file__]:
    if not os.path.abspath(path).startswith(os.path.abspath(root) + os.sep):
        raise SystemExit(f"non-hermetic Python path: {path}")
print(f"python: {sys.version.split()[0]}, stdlib below execution root")

with open(os.environ["LAUNCHER"], "rb") as stream:
    header = stream.read(64)
    if header[:4] != b"\x7fELF" or header[4] != 2 or header[5] != 1:
        raise SystemExit("launcher is not ELF64 little-endian")
    if struct.unpack_from("<H", header, 18)[0] != 183:
        raise SystemExit("launcher ELF e_machine is not AArch64")
    phoff = struct.unpack_from("<Q", header, 32)[0]
    phentsize = struct.unpack_from("<H", header, 54)[0]
    phnum = struct.unpack_from("<H", header, 56)[0]
    stream.seek(phoff)
    for _ in range(phnum):
        program_header = stream.read(phentsize)
        if struct.unpack_from("<I", program_header)[0] == 3:
            raise SystemExit("static launcher unexpectedly has PT_INTERP")
print("launcher: ELF64 AArch64 static, no PT_INTERP")
'
} > "$output"

"$busybox" rm -rf "$scratch"
"$busybox" touch -h -d '@0' "$output"
