#!/usr/bin/env bash
# Unless explicitly stated otherwise all files in this repository are
# dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
#
# This product includes software developed at Datadog
# (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

# Unit tests of the PHP-agnostic core of the downstream curl collector
# (appsec/src/extension/downstream/curl_collect.c): heads, hops and the phase
# rule. Built with AddressSanitizer and UndefinedBehaviorSanitizer when the
# compiler supports them.
#
# Usage: run.sh [work dir]   (CC selects the compiler; default: cc)

set -euo pipefail

CC=${CC:-cc}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SRC=$HERE/../../src/extension/downstream/curl_collect.c
W=${1:-$(mktemp -d)}
mkdir -p "$W"

CFLAGS=(-std=c11 -O1 -g -Wall -Wextra -Werror)
SAN=(-fsanitize=address,undefined -fno-sanitize-recover=all
  -fno-omit-frame-pointer)
if echo 'int main(void){return 0;}' |
  "$CC" "${SAN[@]}" -x c -o "$W/san_probe" - >/dev/null 2>&1 &&
  "$W/san_probe" >/dev/null 2>&1; then
  CFLAGS+=("${SAN[@]}")
  echo "building with ${SAN[*]}"
else
  echo "sanitizers unavailable; building without them"
fi

"$CC" "${CFLAGS[@]}" -o "$W/test_curl_collect" \
  "$HERE/test_curl_collect.c" "$SRC"
ASAN_OPTIONS=detect_leaks=1:abort_on_error=0 "$W/test_curl_collect"
