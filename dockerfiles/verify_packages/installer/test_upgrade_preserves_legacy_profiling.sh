#!/usr/bin/env sh

set -e

. "$(dirname "$0")/utils.sh"

assert_no_ddtrace

# A released standalone profiler is enabled by its extension line and defaults
# to profiling on, even with only a commented profiling.enabled setting.
old_version="0.79.0"
fetch_setup_for_version "$old_version" /tmp
run_released_installer /tmp/datadog-setup.php --php-bin php --enable-profiling
ini_file="$(get_php_conf_dir)/98-ddtrace.ini"
sed -i 's/^[;[:space:]]*datadog\.profiling\.enabled[[:space:]]*=.*/;datadog.profiling.enabled = 1/' "$ini_file"
assert_profiler_version "$old_version"
assert_profiler_installed

# Upgrade without repeating --enable-profiling: the combined package must
# translate the active legacy extension line into an explicit enabled setting.
php ./build/packages/datadog-setup.php --php-bin php
version=$(cat VERSION)
assert_ddtrace_version "$version"
assert_profiler_version "$version"
assert_file_contains "$ini_file" "datadog.profiling.enabled = On"
php -r 'exit(extension_loaded("datadog-profiling") ? 1 : 0);'
