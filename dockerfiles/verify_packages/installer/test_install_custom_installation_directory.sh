#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.68.0"
generate_installers "${new_version}"
php ./build/packages/datadog-setup.php --php-bin php --install-dir /custom/dd
assert_ddtrace_version "${new_version}"
assert_file_exists /custom/dd/dd-library/${new_version}/dd-trace-sources/bridge/dd_wrap_autoloader.php

assert_request_init_hook_exists
