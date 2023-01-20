#!/usr/bin/env bash

set -e

# Steps based on https://fromdual.com/hunting-the-core

ulimit -c unlimited

echo '/tmp/corefiles/core' > /proc/sys/kernel/core_pattern

echo 1 > /proc/sys/fs/suid_dumpable

echo "All settings changes, now restart the server."

export ASAN_OPTIONS=abort_on_error=1:disable_coredump=0:unmap_shadow_on_exit=1
