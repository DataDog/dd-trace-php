#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
# Verify that wrong installation dir (e.g. /) does not delete all files in root
php build/packages/dd-library-php-x86_64-linux-gnu.php --php-bin php --install-dir /
assert_file_exists /dd-library/$(ddtrace_current_version)/dd-trace-sources/bridge/dd_wrap_autoloader.php

# Making sure a clean install to root / does not rm -rf everything
assert_file_exists /usr/bin/tail

assert_request_init_hook_exists
