#!/usr/bin/env bash

set -e

# Steps are and excerpt from https://fromdual.com/hunting-the-core

ulimit -c unlimited

echo '/tmp/corefiles/core' > /proc/sys/kernel/core_pattern

echo 1 > /proc/sys/fs/suid_dumpable

echo "All settings changes, now restart the server."
