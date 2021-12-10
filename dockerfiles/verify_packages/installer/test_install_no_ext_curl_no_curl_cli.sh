#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

apk add php7 php7-json libcurl libexecinfo php7-openssl

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.65.1"
php dd-library-php-setup.php --php-bin php --tracer-version "${new_version}"
assert_ddtrace_version "${new_version}"
