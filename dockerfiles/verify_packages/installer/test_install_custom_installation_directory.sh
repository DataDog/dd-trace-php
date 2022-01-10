#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
php build/packages/dd-library-php-x86_64-linux-gnu.php --php-bin php --install-dir /custom/dd
assert_file_exists /custom/dd/dd-library/$(ddtrace_current_version)/dd-trace-sources/bridge/dd_wrap_autoloader.php

assert_request_init_hook_exists
