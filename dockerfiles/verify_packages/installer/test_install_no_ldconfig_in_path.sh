#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
PHP=$(which php)

# ldconfig is typically found in /sbin
export PATH=/usr/bin:/bin:/usr/local/bin

set +e
output=$($PHP ./build/packages/datadog-setup.php --php-bin $PHP)
exit_status=$?
set -e

assert_ddtrace_version "${version}" $PHP
