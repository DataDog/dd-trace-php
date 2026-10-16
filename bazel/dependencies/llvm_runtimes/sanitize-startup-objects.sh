#!/bin/sh
set -eu

objcopy=$1
objdump=$2
shift 2

test "$#" -gt 0
test $(($# % 2)) -eq 0

while [ "$#" -gt 0 ]; do
    source_object=$1
    output_object=$2
    shift 2

    "$objcopy" --strip-debug "$source_object" "$output_object"
    sections=$($objdump -h "$output_object")
    if printf '%s\n' "$sections" | grep -E '[.]debug_|[.]zdebug_' >/dev/null; then
        echo "target startup object still contains debug sections: $output_object" >&2
        exit 1
    fi
done
