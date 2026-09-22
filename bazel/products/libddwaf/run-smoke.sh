#!/bin/sh
# glibc's cache is disabled and musl has none; --library-path is therefore the
# entire dynamic-linker input for this native target smoke.
set -eu

objdump=$1
loader=$2
library_path=$3
binary=$4
marker=$5
machine=$6
elf_architecture=$7
target_triple=$8
# POSIX shells require braces for positional parameters above $8.
libc=${9}

scratch=${TMPDIR:-/tmp}/libddwaf-smoke.$$
mkdir -p "$scratch"
trap 'rm -rf "$scratch"' EXIT HUP INT TERM

actual_machine=$(uname -m)
if test "$actual_machine" != "$machine"; then
    echo "native executor is $actual_machine, expected $machine" >&2
    exit 1
fi
case "$machine:$target_triple" in
    x86_64:x86_64-*|aarch64:aarch64-*) ;;
    *) echo "executor machine $machine does not match target $target_triple" >&2; exit 1 ;;
esac
if ! test -x "$loader"; then echo "declared dynamic loader is not executable: $loader" >&2; exit 1; fi
if ! test -x "$binary"; then echo "smoke binary is not executable: $binary" >&2; exit 1; fi
"$objdump" -f "$binary" > "$scratch/binary.header"
if ! grep -F "architecture: $elf_architecture" "$scratch/binary.header" >/dev/null; then
    echo "smoke ELF architecture differs from declared target $elf_architecture" >&2
    cat "$scratch/binary.header" >&2
    exit 1
fi

# Resolve every DT_NEEDED edge recursively in the declared sysroot paths. This
# rejects host lookup, a missing target DSO, and every embedded search path.
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
    echo "undeclared dynamic dependency: $needed" >&2
    return 1
}

printf '%s\n' "$binary" > "$scratch/queue"
: > "$scratch/seen"
while IFS= read -r candidate; do
    if grep -F -x "$candidate" "$scratch/seen" >/dev/null; then
        continue
    fi
    printf '%s\n' "$candidate" >> "$scratch/seen"
    "$objdump" -p "$candidate" > "$scratch/private"
    if grep -E 'RPATH|RUNPATH' "$scratch/private" >/dev/null; then
        echo "dynamic search path is forbidden in $candidate" >&2
        exit 1
    fi
    sed -n 's/^ *NEEDED  *//p' "$scratch/private" > "$scratch/needed"
    while IFS= read -r needed; do
        test -n "$needed" || continue
        resolve_needed "$needed" >> "$scratch/queue"
    done < "$scratch/needed"
done < "$scratch/queue"

case "$libc" in
    glibc) "$loader" --inhibit-cache --library-path "$library_path" "$binary" "$marker" ;;
    musl) "$loader" --library-path "$library_path" "$binary" "$marker" ;;
    *) echo "unsupported target libc: $libc" >&2; exit 1 ;;
esac

IFS= read -r result < "$marker"
test "$result" = "libddwaf 2.0.1 object-allocation-and-rule-evaluation-ok"
