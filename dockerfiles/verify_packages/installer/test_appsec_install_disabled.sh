#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.75.0"
generate_installers "${new_version}"
php ./build/packages/datadog-setup.php --php-bin php

assert_ddtrace_version "${new_version}"
assert_appsec_version 0.2.0
assert_appsec_disabled

assert_file_exists "$(get_php_extension_dir)"/ddappsec.so
assert_file_exists /opt/datadog/dd-library/${new_version}/bin/ddappsec-helper
assert_file_exists /opt/datadog/dd-library/${new_version}/etc/recommended.json
