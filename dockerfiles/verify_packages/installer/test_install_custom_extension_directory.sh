#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
trace_version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php --extension-dir /custom-ext-dir --enable-profiling

assert_file_exists /custom-ext-dir/ddtrace.so

assert_ddtrace_version "${trace_version}"
assert_profiler_installed
assert_appsec_installed

assert_sources_path_exists
