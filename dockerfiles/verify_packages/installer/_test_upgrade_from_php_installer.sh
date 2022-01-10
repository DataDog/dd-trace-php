#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
curl -OL https://github.com/DataDog/dd-trace-php/releases/download/0.64.1/datadog-php-tracer-0.64.1.x86_64.tar.gz
php dd-library-php-setup.php --php-bin php --file datadog-php-tracer-0.64.1.x86_64.tar.gz
assert_ddtrace_version "0.64.1"

# Upgrade using the php installer
php build/packages/dd-library-php-x86_64-linux-gnu.php --php-bin php
assert_ddtrace_version "${new_version}"

assert_request_init_hook_exists
