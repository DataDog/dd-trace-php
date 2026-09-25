#!/bin/sh
# Check a selected matrix record's declared header SDK files without executing target code.
set -eu

manifest= record= root=
while [ "$#" -gt 0 ]; do
    case "$1" in
        --manifest) manifest=$2; shift 2 ;;
        --record) record=$2; shift 2 ;;
        --root) root=$2; shift 2 ;;
        *) echo "unknown artifact validation argument: $1" >&2; exit 2 ;;
    esac
done
[ -n "$manifest" ] && [ -n "$record" ] && [ -n "$root" ]

artifacts=$(jq -er --arg record "$record" '
  .records[] | select(.name == $record) | select(.supported) | .expected_artifacts[]
' "$manifest")
[ -n "$artifacts" ] || { echo "matrix record has no supported artifacts: $record" >&2; exit 1; }
for artifact in $artifacts; do
    case "$artifact" in
        sdk/*) ;;
        *) echo "unsafe declared artifact path: $artifact" >&2; exit 1 ;;
    esac
    [ -f "$root/$artifact" ] || { echo "missing declared PHP artifact: $record/$artifact" >&2; exit 1; }
done
