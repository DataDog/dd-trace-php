#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
php build/packages/dd-library-php-x86_64-linux-gnu.php --php-bin php
assert_current_ddtrace_version

assert_request_init_hook_exists
