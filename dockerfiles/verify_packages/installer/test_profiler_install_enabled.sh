#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

uname=$(uname -a)
arch=$(if [ -z "${uname##*arm*}" ] || [ -z "${uname##*aarch*}" ]; then echo aarch64; else echo x86_64; fi)

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
trace_version=$(parse_trace_version)
profiling_version=$(parse_profiling_version)
file="./build/packages/dd-library-php-${trace_version}-${arch}-linux-gnu.tar.gz"
if ! [ -f $file ]; then
  trace_version="0.79.0"
  profiling_version="0.10.0"
fi
generate_installers "$trace_version"
php ./build/packages/datadog-setup.php --php-bin php --enable-profiling \
    $(! [ -f $file ] || echo --file "$file")
assert_ddtrace_version "${trace_version}"

assert_file_exists "$(get_php_extension_dir)"/datadog-profiling.so

assert_profiler_version "${profiling_version}"
