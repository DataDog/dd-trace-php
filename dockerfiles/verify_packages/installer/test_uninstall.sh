#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
ini_dir="$(php -i | grep '^Scan' | awk '{ print $NF }')"

# Install using the php installer
new_version="0.68.0"
generate_installers "${new_version}"
php ./build/packages/datadog-setup.php --php-bin php --enable-profiling
assert_ddtrace_version "${new_version}"
assert_profiler_version "0.3.0"

# Uninstall
php ./build/packages/datadog-setup.php --php-bin php --uninstall
assert_no_ddtrace
assert_no_profiler

# The .so files should be removed
if [ -f "${extension_dir}/ddtrace.so" ]; then
    echo "Error. File ${extension_dir}/ddtrace.so should not exist."
    exit 1
else
    echo "Ok: File ${extension_dir}/ddtrace.so has been removed."
fi
if [ -f "${extension_dir}/datadog-profiling.so" ]; then
    echo "Error. File ${extension_dir}/datadog-profiling.so should not exist."
    exit 1
else
    echo "Ok: File ${extension_dir}/datadog-profiling.so has been removed."
fi

# The INI file should NOT be removed
if [ ! -f "${ini_dir}/98-ddtrace.ini" ]; then
    echo "Error. File ${ini_dir}/98-ddtrace.ini should not be removed."
    exit 1
else
    echo "Ok: File ${ini_dir}/98-ddtrace.ini has not been removed."
fi

# extension=... in the INI file should be commented out
assert_file_contains "${ini_dir}/98-ddtrace.ini" ";extension = ddtrace.so"
assert_file_contains "${ini_dir}/98-ddtrace.ini" ";zend_extension = datadog-profiling.so"
