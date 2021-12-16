#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.67.0"
php dd-library-php-setup.php --php-bin php --url "https://github.com/labbati/test-actions/releases/download/${new_version}/dd-library-php-x86_64-gnu.tar.gz"

assert_ddtrace_version "${new_version}"

assert_file_exists /opt/datadog/dd-library/${new_version}/dd-trace-sources/bridge/dd_wrap_autoloader.php
