#!/usr/bin/env sh

set -e

. "$(dirname "$0")/utils.sh"

assert_no_ddtrace

# Exercise a recent standalone release with matching tracer/profiler versions.
old_version="1.25.1"
fetch_setup_for_version "$old_version" /tmp
run_released_installer /tmp/datadog-setup.php --php-bin php --enable-profiling
assert_ddtrace_version "$old_version"
assert_profiler_version "$old_version"
assert_profiler_installed
php -r 'exit(extension_loaded("datadog-profiling") ? 0 : 1);'

ini_file="$(get_php_conf_dir)/98-ddtrace.ini"

# Keep the released installer's configuration intact and upgrade without
# repeating --enable-profiling. Profiling must remain enabled in combined ddtrace.
php ./build/packages/datadog-setup.php --php-bin php
version=$(cat VERSION)
assert_ddtrace_version "$version"
assert_profiler_version "$version"
assert_file_contains "$ini_file" "datadog.profiling.enabled = On"
php -r 'exit(extension_loaded("datadog-profiling") ? 1 : 0);'
