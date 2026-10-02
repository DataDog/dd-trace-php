#!/bin/sh
# Copy declared inputs into an independent header/resource/install tree.
set -eu
busybox=$1
output=$2
manifest=$3
"$busybox" mkdir -p "$output"
while IFS="$(printf '\t')" read -r source relative; do
    destination=$output/$relative
    "$busybox" mkdir -p "$("$busybox" dirname "$destination")"
    "$busybox" cp -RL "$source" "$destination"
done < "$manifest"
"$busybox" find "$output" -type d -exec "$busybox" chmod 0755 '{}' ';'
"$busybox" find "$output" -type f -exec "$busybox" chmod 0644 '{}' ';'
"$busybox" find "$output" -exec "$busybox" touch -h -d @0 '{}' ';'
