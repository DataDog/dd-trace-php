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

php ./build/packages/datadog-setup.php --enable-appsec --php-bin php
assert_appsec_version 0.2.0
# Appsec requires ddtrace to be enabled, otherwise it crashes with missing symbols.
assert_ddtrace_version $new_version
