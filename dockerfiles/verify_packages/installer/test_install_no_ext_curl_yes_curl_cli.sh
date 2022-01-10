#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

apk add php7 php7-json libcurl libexecinfo curl

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
php build/packages/dd-library-php-x86_64-linux-gnu.php --php-bin php

assert_request_init_hook_exists
