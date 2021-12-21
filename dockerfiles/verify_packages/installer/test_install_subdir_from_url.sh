#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.68.0"
php dd-library-php-setup.php --php-bin php --url "https://github.com/DataDog/dd-trace-php/releases/download/${new_version}/dd-library-php-x86_64-linux-gnu.tar.gz"

assert_ddtrace_version "${new_version}"

assert_file_exists /opt/datadog/dd-library/${new_version}/dd-trace-sources/bridge/dd_wrap_autoloader.php

assert_request_init_hook_exists
