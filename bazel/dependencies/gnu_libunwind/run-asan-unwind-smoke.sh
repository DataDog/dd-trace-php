#!/bin/sh
# Validate the GNU-libunwind ASan smoke's declared dynamic closure, then run
# its actual stepped-frame check through the target glibc loader.
set -eu

objdump=$1
loader=$2
library_path=$3
binary=$4
marker=$5
machine=$6
elf_architecture=$7
asan_soname=$8
scratch=${TMPDIR:-/tmp}/gnu-libunwind-asan.$$
mkdir -p "$scratch"
trap 'rm -rf "$scratch"' EXIT HUP INT TERM
test "$(uname -m)" = "$machine"
"$objdump" -f "$binary" >"$scratch/header"
grep -F "architecture: $elf_architecture" "$scratch/header" >/dev/null
printf '%s\n' "$binary" >"$scratch/queue"
: >"$scratch/seen"
found_asan=0
while IFS= read -r candidate; do
    grep -F -x "$candidate" "$scratch/seen" >/dev/null && continue
    printf '%s\n' "$candidate" >>"$scratch/seen"
    "$objdump" -p "$candidate" >"$scratch/dynamic"
    if grep -E 'RPATH|RUNPATH|TEXTREL' "$scratch/dynamic" >/dev/null; then
        cat "$scratch/dynamic" >&2
        exit 1
    fi
    sed -n 's/^ *NEEDED  *//p' "$scratch/dynamic" >"$scratch/needed"
    while IFS= read -r needed; do
        test -n "$needed" || continue
        test "$needed" != "$asan_soname" || found_asan=1
        resolved=
        old_ifs=$IFS; IFS=:
        for directory in $library_path; do
            if test -f "$directory/$needed"; then resolved="$directory/$needed"; break; fi
        done
        IFS=$old_ifs
        test -n "$resolved" || { echo "undeclared GNU libunwind ASan dependency: $needed" >&2; exit 1; }
        printf '%s\n' "$resolved" >>"$scratch/queue"
    done <"$scratch/needed"
done <"$scratch/queue"
test "$found_asan" = 1
"$loader" --inhibit-cache --library-path "$library_path" "$binary"
printf '%s\n' 'GNU libunwind ASan stepped-frame smoke passed' >"$marker"
