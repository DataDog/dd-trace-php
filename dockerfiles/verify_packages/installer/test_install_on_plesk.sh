#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Install using the php installer
new_version="0.82.0"
generate_installers "${new_version}"

prefix="/opt/plesk/php/8.0"
"$prefix/bin/php" ./build/packages/datadog-setup.php --php-bin=all

assert_ddtrace_version "${new_version}" "$prefix/bin/php"
assert_ddtrace_version "${new_version}" "$prefix/sbin/php-fpm"
