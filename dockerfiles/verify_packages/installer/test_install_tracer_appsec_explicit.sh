#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

assert_no_ddtrace
assert_no_ddappsec

php dd-library-php-setup.php --php-bin php --tracer-version 0.67.0 --appsec-version 0.1.0

assert_ddtrace_installed
assert_ddappsec_installed
assert_ddappsec_enabled
