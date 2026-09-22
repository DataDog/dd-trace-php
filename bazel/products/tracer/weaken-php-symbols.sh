#!/bin/sh
set -eu

ar=$1
ranlib=$2
mockgen=$3
php=$4
output=$5
shift 5

case "$output" in
    /*) ;;
    *) output="$PWD/$output" ;;
esac
case "$php" in
    /*) ;;
    *) php="$PWD/$php" ;;
esac
case "$mockgen" in
    /*) ;;
    *) mockgen="$PWD/$mockgen" ;;
esac
case "$ar" in
    /*) ;;
    *) ar="$PWD/$ar" ;;
esac
case "$ranlib" in
    /*) ;;
    *) ranlib="$PWD/$ranlib" ;;
esac

work=$(mktemp -d "${TMPDIR:-/tmp}/ddtrace-weaken.XXXXXX")
trap 'rm -rf "$work"' EXIT HUP INT TERM

index=0
for object in "$@"; do
    index=$((index + 1))
    copy=$(printf '%s/%06d.o' "$work" "$index")
    cat "$object" >"$copy"
done
test "$index" -gt 0

"$mockgen" weaken-dynsym "$work"/*.o "$php"
"$ar" rcD "$output" "$work"/*.o
"$ranlib" -D "$output"
