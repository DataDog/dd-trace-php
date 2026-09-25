#!/bin/sh
set -eu

expected_arch=$1
busybox=$2
smoke=$3
marker=$4

actual_arch=$($busybox uname -m)
if [ "$actual_arch" != "$expected_arch" ]; then
    echo "LLVM runtime native probe expected $expected_arch executor, got $actual_arch" >&2
    exit 1
fi

"$smoke"
printf 'architecture=%s\nsmoke=pass\n' "$actual_arch" >"$marker"
