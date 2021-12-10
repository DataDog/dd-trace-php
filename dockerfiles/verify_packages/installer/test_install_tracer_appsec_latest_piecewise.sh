#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace/ddappsec
assert_no_ddtrace
assert_no_ddappsec

# Install first the latest tracer
php dd-library-php-setup.php --php-bin php --no--appsec

assert_ddtrace_installed
assert_no_ddappsec
assert_file_exists "$(php_conf_dir)"/98-ddtrace.ini

# then the latest tracer
php dd-library-php-setup.php --php-bin php --no--tracer

assert_ddtrace_installed
assert_ddappsec_installed

assert_file_exists "$(php_conf_dir)"/98-ddappsec.ini
assert_file_exists "$(php_conf_dir)"/98-ddtrace.ini
assert_ddappsec_disabled
