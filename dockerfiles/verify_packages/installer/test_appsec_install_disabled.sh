#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

if ! is_appsec_installable; then
  exit 0
fi

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php

assert_ddtrace_version "${version}"
assert_appsec_version "${version}"
assert_appsec_disabled

assert_file_exists "$(get_php_extension_dir)"/ddappsec.so
assert_file_exists /opt/datadog/dd-library/${version}/bin/ddappsec-helper
assert_file_exists /opt/datadog/dd-library/${version}/etc/recommended.json
