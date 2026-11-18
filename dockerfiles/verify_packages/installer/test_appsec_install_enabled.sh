#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php --enable-appsec
assert_ddtrace_version "${version}"
assert_appsec_version "${version}"
assert_appsec_enabled

# AppSec is part of ddtrace.so, only the enabled setting is written
ini_file="$(get_php_conf_dir)/98-ddtrace.ini"
assert_file_contains "${ini_file}" "datadog.appsec.enabled = On"
assert_file_not_contains "${ini_file}" "extension = ddappsec"
assert_file_not_exists "$(get_php_extension_dir)"/ddappsec.so
assert_file_exists /opt/datadog/dd-library/${version}/etc/recommended.json
