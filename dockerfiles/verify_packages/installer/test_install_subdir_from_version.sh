#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.65.1"
php dd-library-php-setup.php --php-bin=php --tracer-version="${new_version}"
assert_file_exists /opt/datadog/dd-library/${new_version}/dd-trace-sources/bridge/dd_wrap_autoloader.php
