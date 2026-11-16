#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

switch-php debug

# Initially no ddtrace
assert_no_ddtrace

# Install the profiling-capable debug module from the package under test.
version=$(cat VERSION)
uname=$(uname -a)
arch=$(if [ -z "${uname##*arm*}" ] || [ -z "${uname##*aarch*}" ]; then echo aarch64; else echo x86_64; fi)
bundle="./build/packages/dd-library-php-${version}-${arch}-linux-gnu.tar.gz"
if ! [ -f "${bundle}" ]; then
    echo "SKIPPED: this test runs only in CI as it requires the .tar.gz at a specific path"
    exit 0
fi
php ./build/packages/datadog-setup.php --php-bin php --enable-profiling --file "${bundle}"
assert_ddtrace_version "${version}"

assert_file_exists "$(get_php_extension_dir)"/ddtrace.so
assert_file_not_exists "$(get_php_extension_dir)"/datadog-profiling.so
assert_profiler_version "${version}"
assert_file_contains "$(get_php_conf_dir)/98-ddtrace.ini" "datadog.profiling.enabled = On"
