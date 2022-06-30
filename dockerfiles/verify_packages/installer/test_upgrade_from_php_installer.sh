#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
old_version="0.74.0"
generate_installers "${old_version}"
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${old_version}"

# Upgrade using the php installer
new_version="0.75.0"
generate_installers "${new_version}"
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${new_version}"

assert_request_init_hook_exists
