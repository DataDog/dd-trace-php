#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
ini_dir="$(php -i | grep '^Scan' | awk '{ print $NF }')"

# Install using the php installer
new_version="0.75.0"
generate_installers "${new_version}"
php ./build/packages/datadog-setup.php --php-bin php

# Uninstall
php ./build/packages/datadog-setup.php --php-bin php --uninstall
assert_no_ddtrace
assert_no_appsec
assert_no_profiler

php ./build/packages/datadog-setup.php --enable-profiling --php-bin php

assert_ddtrace_version 0.75.0
assert_profiler_version 0.6.1

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
if [ -f "${extension_dir}/ddtrace.so" ]; then
    echo "Ok: File ${extension_dir}/ddtrace.so exists."
else
    echo "Error. File ${extension_dir}/ddtrace.so should exist."
    exit 1
fi
