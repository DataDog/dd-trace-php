#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
#trace_version=$(parse_trace_version)
trace_version="0.99.0"
generate_installers "${trace_version}"
php ./build/packages/datadog-setup.php --php-bin php --extension-dir /custom-ext-dir

ls -l /custom-ext-dir

assert_file_exists /custom-ext-dir/ddtrace.so

assert_ddtrace_version "${trace_version}"
assert_profiler_installed
assert_appsec_installed

assert_request_init_hook_exists
