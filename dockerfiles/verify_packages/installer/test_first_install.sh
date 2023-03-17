#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version=$(awk -F\" '/#define PHP_DDTRACE_VERSION/ {print $2}' < "$(dirname ${0})/../../ext/version.h")
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${new_version}"

assert_request_init_hook_exists
