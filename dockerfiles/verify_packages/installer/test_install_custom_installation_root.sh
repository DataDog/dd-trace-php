#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.65.1"

# Verify that wrong installation dir (e.g. /) does not delete all files in root
php dd-library-php-setup.php --php-bin=php --tracer-version="${new_version}" --install-dir=/
assert_ddtrace_version "${new_version}"
assert_file_exists /dd-library/${new_version}/dd-trace-sources/bridge/dd_wrap_autoloader.php
assert_file_exists /usr/bin/tail
