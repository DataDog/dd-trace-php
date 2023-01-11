#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.78.0"
generate_installers "${new_version}"

PHP=$(which php)

# ldconfig is typically found in /sbin
export PATH=/usr/bin:/bin:/usr/local/bin

set +e
output=$($PHP ./build/packages/datadog-setup.php --php-bin $PHP)
exit_status=$?
set -e

assert_ddtrace_version "${new_version}" $PHP
