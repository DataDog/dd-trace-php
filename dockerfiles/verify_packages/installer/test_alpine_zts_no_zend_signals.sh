#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

apk add libgcc

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --enable-profiling --php-bin php
assert_ddtrace_version "${version}"
assert_profiler_version "${version}"
assert_appsec_version "${version}"

assert_sources_path_exists
