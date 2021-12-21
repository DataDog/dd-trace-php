#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

apk add php7 php7-json libcurl libexecinfo curl

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.68.0"
php dd-library-php-setup.php --php-bin php --version "${new_version}"
assert_ddtrace_version "${new_version}"

assert_request_init_hook_exists
