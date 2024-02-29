#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

apk add php7 php7-json libcurl libgcc php7-openssl

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${version}"

assert_request_init_hook_exists
