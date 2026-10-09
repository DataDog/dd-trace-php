#!/bin/sh
set -eu

objdump=$1
loader=$2
library_path=$3
binary=$4
marker=$5
machine=$6
elf_architecture=$7
asan_soname=$8

scratch=${TMPDIR:-/tmp}/llvm-asan-smoke.$$
mkdir -p "$scratch"
trap 'rm -rf "$scratch"' EXIT HUP INT TERM

test "$(uname -m)" = "$machine"
test -x "$loader"
test -x "$binary"
"$objdump" -f "$binary" >"$scratch/binary.header"
grep -F "architecture: $elf_architecture" "$scratch/binary.header" >/dev/null

resolve_needed() {
    needed=$1
    old_ifs=$IFS
    IFS=:
    for directory in $library_path; do
        if test -f "$directory/$needed"; then
            IFS=$old_ifs
            printf '%s\n' "$directory/$needed"
            return 0
        fi
    done
    IFS=$old_ifs
    echo "undeclared ASan smoke dependency: $needed" >&2
    return 1
}

printf '%s\n' "$binary" >"$scratch/queue"
: >"$scratch/seen"
found_asan=0
while IFS= read -r candidate; do
    if grep -F -x "$candidate" "$scratch/seen" >/dev/null; then
        continue
    fi
    printf '%s\n' "$candidate" >>"$scratch/seen"
    "$objdump" -p "$candidate" >"$scratch/dynamic"
    if grep -E 'RPATH|RUNPATH|TEXTREL' "$scratch/dynamic" >/dev/null; then
        echo "forbidden dynamic metadata in $candidate" >&2
        exit 1
    fi
    sed -n 's/^ *NEEDED  *//p' "$scratch/dynamic" >"$scratch/needed"
    while IFS= read -r needed; do
        test -n "$needed" || continue
        test "$needed" != "$asan_soname" || found_asan=1
        resolve_needed "$needed" >>"$scratch/queue"
    done <"$scratch/needed"
done <"$scratch/queue"
test "$found_asan" -eq 1

ASAN_OPTIONS=symbolize=0:halt_on_error=1 \
    "$loader" --inhibit-cache --library-path "$library_path" "$binary" clean

set +e
ASAN_OPTIONS=symbolize=0:halt_on_error=1 \
    "$loader" --inhibit-cache --library-path "$library_path" "$binary" overflow \
    >"$scratch/overflow.stdout" 2>"$scratch/overflow.stderr"
overflow_status=$?
set -e
test "$overflow_status" -ne 0
grep -F 'ERROR: AddressSanitizer: heap-buffer-overflow' "$scratch/overflow.stderr" >/dev/null

printf '%s\n' "AddressSanitizer clean execution and heap-overflow detection passed" >"$marker"
