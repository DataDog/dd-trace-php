#!/bin/sh
set -eu

ar=$1
nm=$2
objdump=$3
archive=$4
architecture=$5
libc=$6
expected_members=$7
marker=$8

scratch=${TMPDIR:-/tmp}/tracer-full.$$
mkdir -p "$scratch/objects"
trap 'rm -rf "$scratch"' EXIT HUP INT TERM

"$ar" t "$archive" > "$scratch/members"
member_count=$(wc -l < "$scratch/members")
member_count=$(printf '%s' "$member_count" | tr -d ' ')
test "$member_count" = "$expected_members"
test "$(sort "$scratch/members" | uniq -d | wc -l | tr -d ' ')" = 0

while IFS= read -r member; do
    case "$member" in
        *.pic.o) ;;
        *)
            echo "unexpected tracer archive member: $member" >&2
            exit 1
            ;;
    esac
    "$ar" p "$archive" "$member" > "$scratch/objects/$member"
    "$objdump" -f "$scratch/objects/$member" > "$scratch/object-header"
    grep -F "architecture: $architecture" "$scratch/object-header" >/dev/null
    "$objdump" -h "$scratch/objects/$member" > "$scratch/object-sections"
    grep -E '[[:space:]]\.debug_info[[:space:]]' "$scratch/object-sections" >/dev/null
    if grep -a -E '/worker/|/home/|/tmp/' "$scratch/objects/$member" >/dev/null; then
        echo "tracer object $member embeds an absolute execution or checkout path" >&2
        exit 1
    fi
done < "$scratch/members"

"$nm" --defined-only "$archive" > "$scratch/symbols"
grep -E '[[:space:]]datadog_module_entry$' "$scratch/symbols" >/dev/null
grep -E '[[:space:]]ddtrace_coms_init_and_start_writer$' "$scratch/symbols" >/dev/null
grep -E '[[:space:]]ddtrace_compile_time_reset$' "$scratch/symbols" >/dev/null
grep -E '[[:space:]]zif_dd_trace_buffer_span$' "$scratch/symbols" >/dev/null

"$nm" "$archive" > "$scratch/references"
if test "$libc" = glibc; then
    grep -E '[[:space:]]U backtrace$' "$scratch/references" >/dev/null
    grep -E '[[:space:]]U backtrace_symbols$' "$scratch/references" >/dev/null
else
    if grep -E '[[:space:]]U (backtrace|backtrace_symbols)$' "$scratch/references" >/dev/null; then
        echo "musl tracer archive unexpectedly references the unavailable execinfo interface" >&2
        exit 1
    fi
fi
grep -E '[[:space:]]U prctl$' "$scratch/references" >/dev/null
grep -E '[[:space:]]U syscall$' "$scratch/references" >/dev/null

printf 'full tracer C %s-%s archive passed (%s members)\n' "$architecture" "$libc" "$member_count" > "$marker"
