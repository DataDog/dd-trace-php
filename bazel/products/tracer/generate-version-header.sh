#!/bin/sh
set -eu

version_file=$1
output=$2
direct_output=$3
version=$(cat "$version_file")
case "$version" in
    *[!0-9A-Za-z.+~-]*|'')
        echo "invalid extension version: $version" >&2
        exit 1
        ;;
esac
umask 022
mkdir -p "$(dirname "$output")"
cat >"$output" <<EOF
#ifndef DDTRACE_VERSION_H
#define DDTRACE_VERSION_H
#define PHP_DDTRACE_VERSION "$version"
#endif
EOF
chmod 0644 "$output"
cp "$output" "$direct_output"
chmod 0644 "$direct_output"
