#!/bin/sh
set -eu

validator=$1
debuglink_validator=$2
objdump=$3
nm=$4
objcopy=$5
binary=$6
debug=$7
expected_symbols=$8
architecture=$9
libc=${10}
marker=${11}

python3 "$validator" "$objdump" "$nm" "$binary" "$debug" \
    "$expected_symbols" "$architecture" "$libc"
python3 "$debuglink_validator" "$objcopy" "$binary" "$debug"
printf 'ddtrace fat ELF validation passed\n' >"$marker"
