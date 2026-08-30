#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${version}"

assert_file_exists "$(get_php_extension_dir)"/ddtrace.so
assert_file_not_exists "$(get_php_extension_dir)"/datadog-profiling.so
assert_no_profiler
assert_file_contains "$(get_php_conf_dir)/98-ddtrace.ini" "datadog.profiling.enabled = Off"
