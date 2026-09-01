#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

switch-php debug

# Initially no ddtrace
assert_no_ddtrace

# Install the profiling-capable debug module using the PHP installer.
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php --enable-profiling
assert_ddtrace_version "${version}"

assert_file_exists "$(get_php_extension_dir)"/ddtrace.so
assert_file_not_exists "$(get_php_extension_dir)"/datadog-profiling.so
assert_profiler_version "${version}"
assert_file_contains "$(get_php_conf_dir)/98-ddtrace.ini" "datadog.profiling.enabled = On"
