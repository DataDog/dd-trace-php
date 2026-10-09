#!/bin/sh
set -eu

busybox=$1
launcher=$2
source=$3
executable=$4
output=$5

"$busybox" cp "$launcher" "$executable"
"$busybox" chmod 0755 "$executable"
"$busybox" mkdir -p "$output"
"$busybox" cp -R "$source/." "$output/"
