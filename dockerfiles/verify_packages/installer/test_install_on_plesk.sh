#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Install using the php installer
new_version="0.75.0"
generate_installers "${new_version}"
/opt/plesk/php/7.4/bin/php ./build/packages/datadog-setup.php --php-bin=all

assert_ddtrace_version "${new_version}" /opt/plesk/php/7.4/bin/php
assert_ddtrace_version "${new_version}" /opt/plesk/php/7.4/sbin/php-fpm
assert_ddtrace_version "${new_version}" /opt/plesk/php/8.0/bin/php
assert_ddtrace_version "${new_version}" /opt/plesk/php/8.0/sbin/php-fpm
