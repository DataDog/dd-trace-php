#!/bin/sh
set -eu

ar=$1
nm=$2
objdump=$3
archive=$4
architecture=$5
marker=$6

scratch=${TMPDIR:-/tmp}/tracer-coms.$$
mkdir -p "$scratch"
trap 'rm -rf "$scratch"' EXIT HUP INT TERM

"$ar" t "$archive" > "$scratch/members"
test "$(cat "$scratch/members")" = coms.pic.o
"$ar" p "$archive" coms.pic.o > "$scratch/coms.pic.o"

"$objdump" -f "$scratch/coms.pic.o" > "$scratch/header"
grep -F "architecture: $architecture" "$scratch/header" >/dev/null
"$objdump" -h "$scratch/coms.pic.o" > "$scratch/sections"
grep -E '[[:space:]]\.debug_info[[:space:]]' "$scratch/sections" >/dev/null
grep -a -F 'tracer/coms.c' "$scratch/coms.pic.o" >/dev/null

"$nm" --defined-only "$scratch/coms.pic.o" > "$scratch/symbols"
grep -E '[[:space:]]ddtrace_coms_init_and_start_writer$' "$scratch/symbols" >/dev/null
grep -E '[[:space:]]ddtrace_curl_set_hostname$' "$scratch/symbols" >/dev/null

if grep -a -E '/worker/|/home/|/tmp/' "$scratch/coms.pic.o" >/dev/null; then
    echo "tracer object embeds an absolute execution or checkout path" >&2
    exit 1
fi

printf 'tracer coms %s archive passed\n' "$architecture" > "$marker"
