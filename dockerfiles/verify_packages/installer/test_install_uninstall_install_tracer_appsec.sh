#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
ini_dir="$(php -i | grep '^Scan' | awk '{ print $NF }')"

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php

# Uninstall
php ./build/packages/datadog-setup.php --php-bin php --uninstall
assert_no_ddtrace
assert_no_appsec
assert_no_profiler

php ./build/packages/datadog-setup.php --enable-appsec --php-bin php
assert_ddtrace_version $version
assert_appsec_version $version
assert_appsec_enabled

# AppSec is part of ddtrace.so
assert_file_not_exists "${extension_dir}/ddappsec.so"
