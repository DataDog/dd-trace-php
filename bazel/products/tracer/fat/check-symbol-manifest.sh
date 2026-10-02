#!/bin/sh
set -eu

busybox=$1
expected=$2
marker=$3
shift 3

scratch="${marker}.actual"
: >"$scratch"
for source in "$@"; do
    "$busybox" cat "$source" >>"$scratch"
done
"$busybox" cmp "$expected" "$scratch"
"$busybox" rm -f "$scratch"
printf 'ddtrace fat export manifest matches config.m4 inputs\n' >"$marker"
