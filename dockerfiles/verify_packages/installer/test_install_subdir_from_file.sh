#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

if [ "$CIRCLECI" = "true" ]; then
    "SKIPPED: this test runs only in CI as it requires the .tar.gz at a specific path"
    exit 0
fi

# Install using the php installer
php dd-library-php-setup.php --php-bin=php --tracer-file="build/packages/*.tar.gz"
assert_file_exists /opt/datadog/dd-library/*/dd-trace-sources/bridge/dd_wrap_autoloader.php
