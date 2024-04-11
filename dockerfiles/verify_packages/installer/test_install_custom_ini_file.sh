#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
trace_version="0.99.0"
generate_installers "${trace_version}"
custom_ini_file="$(get_php_conf_dir)/40-ddtrace.ini"
php ./build/packages/datadog-setup.php --php-bin php --ini "$custom_ini_file" --enable-profiling

assert_ddtrace_version "${trace_version}"
assert_profiler_installed
assert_appsec_installed

assert_request_init_hook_exists
