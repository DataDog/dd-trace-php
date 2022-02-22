#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.68.0"
generate_installers "${new_version}"

# Verify that wrong installation dir (e.g. /) does not delete all files in root
php ./build/packages/datadog-setup.php --php-bin php --install-dir /
assert_ddtrace_version "${new_version}"
assert_file_exists /dd-library/${new_version}/dd-trace-sources/bridge/dd_wrap_autoloader.php

# Making sure a clean install to root / does not rm -rf everything
assert_file_exists /usr/bin/tail

assert_request_init_hook_exists
