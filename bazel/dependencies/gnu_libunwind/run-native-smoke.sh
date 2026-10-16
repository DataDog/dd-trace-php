#!/bin/sh
set -eu
[ "$#" = 3 ] || { echo "usage: run-native-smoke.sh BUSYBOX BINARY MARKER" >&2; exit 2; }
busybox=$1
binary=$2
marker=$3
"$busybox" sh -c 'exec "$1"' sh "$binary"
printf '%s\n' 'GNU libunwind local cursor smoke passed' >"$marker"
