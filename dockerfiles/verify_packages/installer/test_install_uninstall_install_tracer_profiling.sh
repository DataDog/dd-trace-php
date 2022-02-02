#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
ini_dir="$(php -i | grep '^Scan' | awk '{ print $NF }')"

# Install using the php installer
new_version="0.68.2"
generate_installers "${new_version}"
php ./build/packages/datadog-setup.php --php-bin php

# Uninstall
php ./build/packages/datadog-setup.php --php-bin php --uninstall
assert_no_ddtrace
assert_no_appsec
assert_no_profiler

php ./build/packages/datadog-setup.php --enable-profiling --php-bin php
# The current decision, that can be improved, is to not enable ddtrace again in case the user had manually disabled it.
assert_no_ddtrace
# Profiling should be enabled and it can work without ddtrace
assert_profiler_version 0.3.0

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
if [ -f "${extension_dir}/ddtrace.so" ]; then
    echo "Ok: File ${extension_dir}/ddtrace.so exists."
else
    echo "Error. File ${extension_dir}/ddtrace.so should exist."
    exit 1
fi
