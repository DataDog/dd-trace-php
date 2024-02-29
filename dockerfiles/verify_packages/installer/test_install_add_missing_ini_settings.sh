#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${version}"

ini_file="$(get_php_conf_dir)/98-ddtrace.ini"

assert_file_contains "${ini_file}" 'datadog.trace.request_init_hook'
assert_file_contains "${ini_file}" 'datadog.version'

# Removing an enabled property and a commented out property
sed -i 's/datadog\.trace\.request_init_hook.*//g' "${ini_file}"
sed -i 's/datadog\.version.*//g' "${ini_file}"

assert_file_not_contains "${ini_file}" 'datadog.trace.request_init_hook'
assert_file_not_contains "${ini_file}" 'datadog.version'

php ./build/packages/datadog-setup.php --php-bin php

assert_file_contains "${ini_file}" 'datadog.trace.request_init_hook'
assert_file_contains "${ini_file}" 'datadog.version'

assert_request_init_hook_exists
