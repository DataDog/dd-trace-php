#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
php build/packages/dd-library-php-x86_64-linux-gnu.php --php-bin php
assert_current_ddtrace_version

assert_request_init_hook_exists

# Profiler should not be installer
assert_no_profiler
output=$(php -v 2>&1)

if [ -z "${output##*Cannot load datadog-profiling*}" ]; then
    echo "---\nError: Profiler should not be linked in INI file\n---\n${output}\n---\n"
    exit 1
else
    echo "---\nOk: Profiler is not be linked in INI file\n---\n${output}\n---\n"
fi
