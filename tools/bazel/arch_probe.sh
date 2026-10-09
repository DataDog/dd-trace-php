#!/bin/sh
set -eu

execroot=$PWD
case "$1" in
    /*) out=$1 ;;
    *) out=$execroot/$1 ;;
esac
expected=$2
actual=$("$HERMETIC_BUSYBOX" uname -m)
test "$actual" = "$expected" || {
    echo "execution architecture mismatch: expected $expected, got $actual" >&2
    exit 1
}
printf '%s\n' "$actual" > "$out"
