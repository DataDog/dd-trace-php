#!/usr/bin/env bash
# Unless explicitly stated otherwise all files in this repository are
# dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
#
# This product includes software developed at Datadog
# (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

# Unit tests of the validation of CURL * pointers through the process_vm_readv
# probe (appsec/src/extension/downstream/curl_handle_validate.c), also with the
# syscall refused by seccomp filters (EPERM, ENOSYS). Linux only.
#
# Usage: run.sh [work dir]   (CC selects the compiler; default: cc)

set -euo pipefail

CC=${CC:-cc}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SRC=$HERE/../../src/extension/downstream/curl_handle_validate.c
W=${1:-$(mktemp -d)}
mkdir -p "$W"

# no sanitizers: they don't mix with seccomp filters and RLIMIT_NOFILE games
"$CC" -std=gnu11 -O1 -g -Wall -Wextra -Werror -pthread \
  -o "$W/test_probe" "$HERE/test_probe.c" "$SRC"
"$W/test_probe"
