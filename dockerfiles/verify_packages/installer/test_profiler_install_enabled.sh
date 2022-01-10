#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
php build/packages/dd-library-php-x86_64-linux-gnu.php --php-bin php --enable-profiling
assert_current_ddtrace_version

assert_file_exists "$(get_php_extension_dir)"/datadog-profiling.so

assert_profiler_version 0.3.0
