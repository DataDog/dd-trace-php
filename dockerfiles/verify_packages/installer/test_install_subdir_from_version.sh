#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php

assert_ddtrace_version "${version}"

assert_file_exists /opt/datadog/dd-library/${version}/dd-trace-sources/src/bridge/_files_api.php

assert_sources_path_exists
