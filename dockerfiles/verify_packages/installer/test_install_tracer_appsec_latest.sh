#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace/ddappsec
assert_no_ddtrace
assert_no_ddappsec

# Install latest appsec and ddtrace using the php installer
php dd-library-php-setup.php --php-bin php

assert_ddtrace_installed
assert_ddappsec_installed

assert_file_exists "$(php_conf_dir)"/98-ddtrace.ini
assert_file_exists "$(php_conf_dir)"/98-ddappsec.ini
assert_ddappsec_disabled
