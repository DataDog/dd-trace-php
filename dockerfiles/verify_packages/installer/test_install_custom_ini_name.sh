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
custom_ini_file="$(get_php_conf_dir)/40-ddtrace.ini"

# remove an INI to see that indeed that ini is changed
assert_file_contains "${ini_file}" 'datadog.version'
sed -i 's/datadog\.version.*//g' "${ini_file}"
assert_file_not_contains "${ini_file}" 'datadog.version'

mv "$ini_file" "$custom_ini_file"

php ./build/packages/datadog-setup.php --php-bin php

assert_file_contains "${custom_ini_file}" 'datadog.version'

assert_file_not_exists "${ini_file}"

assert_sources_path_exists
