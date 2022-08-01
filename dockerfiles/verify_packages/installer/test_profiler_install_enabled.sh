#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
trace_version=$(parse_trace_version)
profiling_version=$(parse_profiling_version)
generate_installers "$trace_version"
php ./build/packages/datadog-setup.php --php-bin php --enable-profiling \
    --file "./build/packages/dd-library-php-${trace_version}-x86_64-linux-gnu.tar.gz"
assert_ddtrace_version "${trace_version}"

assert_file_exists "$(get_php_extension_dir)"/datadog-profiling.so

assert_profiler_version "${profiling_version}"
