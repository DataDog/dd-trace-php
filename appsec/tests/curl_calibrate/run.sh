#!/usr/bin/env bash
# Unless explicitly stated otherwise all files in this repository are
# dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
#
# This product includes software developed at Datadog
# (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

# The calibration of libcurl's write, header and read callback slots
# (appsec/src/extension/downstream/curl_calibrate.c) against the libcurl that
# dlopen("libcurl.so.4") finds, and against a fake libcurl for the refusals.
# Only a real libcurl shows that the scan finds its private struct fields, so
# run it also against other builds: LD_LIBRARY_PATH=<prefix>/lib run.sh.
# Linux only.
#
# Usage: run.sh [work dir]   (CC selects the compiler; default: cc)

set -euo pipefail

CC=${CC:-cc}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SRC=$HERE/../../src/extension/downstream/curl_calibrate.c
SLOTS=$HERE/../../src/extension/downstream/curl_slots.c
W=${1:-$(mktemp -d)}
mkdir -p "$W"

if [[ $(uname -s) != Linux ]]; then
  echo "skip: Linux only"
  exit 0
fi

"$CC" -std=gnu11 -O1 -g -Wall -Wextra -Werror \
  -o "$W/harness" "$HERE/harness.c" "$SRC" "$SLOTS" -ldl
"$W/harness"
