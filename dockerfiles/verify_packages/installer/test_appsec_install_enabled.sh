#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.68.2"
generate_installers "${new_version}"
php ./build/packages/datadog-setup.php --php-bin php --enable-appsec
assert_ddtrace_version "${new_version}"

assert_file_exists "$(get_php_extension_dir)"/ddappsec.so

assert_appsec_version 0.2.0
assert_file_exists /opt/datadog/dd-library/${new_version}/bin/ddappsec-helper
assert_file_exists /opt/datadog/dd-library/${new_version}/etc/recommended.json
