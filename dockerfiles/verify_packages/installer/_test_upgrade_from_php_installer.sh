#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
old_version="0.64.0"
php dd-library-php-setup.php --php-bin php --version "${old_version}"
assert_ddtrace_version "${old_version}"

# Upgrade using the php installer
new_version="0.68.0"
php dd-library-php-setup.php --php-bin php --version "${new_version}"
assert_ddtrace_version "${new_version}"

assert_request_init_hook_exists
