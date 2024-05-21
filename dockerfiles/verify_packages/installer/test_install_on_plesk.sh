#!/usr/bin/env sh

set -e

apt-get update && apt-get install -y php

. "$(dirname ${0})/utils.sh"

# Install using the php installer
version=$(cat VERSION)
prefix="/opt/plesk/php/8.1"
"$prefix/bin/php" ./build/packages/datadog-setup.php --php-bin=all

assert_ddtrace_version "${version}" "$prefix/bin/php"
assert_ddtrace_version "${version}" "$prefix/sbin/php-fpm"
