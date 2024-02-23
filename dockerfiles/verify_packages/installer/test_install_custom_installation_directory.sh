#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php --install-dir /custom/dd
assert_ddtrace_version "${version}"
assert_file_exists /custom/dd/dd-library/${version}/dd-trace-sources/bridge/dd_wrap_autoloader.php

assert_request_init_hook_exists
