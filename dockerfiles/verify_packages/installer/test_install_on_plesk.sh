#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Install using the php installer
new_version="0.82.0"
generate_installers "${new_version}"
/opt/plesk/php/8.0/bin/php ./build/packages/datadog-setup.php --php-bin=all

# Around 2022-12-20, plesk/plesk:18.0 was updated and the PHP 7.4 install
# was no longer present. I don't know if this is a temporary regression, or if
# it's purposefully pulled since PHP 7.4 went EOL.
#assert_ddtrace_version "${new_version}" /opt/plesk/php/7.4/bin/php
#assert_ddtrace_version "${new_version}" /opt/plesk/php/7.4/sbin/php-fpm
assert_ddtrace_version "${new_version}" /opt/plesk/php/8.0/bin/php
assert_ddtrace_version "${new_version}" /opt/plesk/php/8.0/sbin/php-fpm
