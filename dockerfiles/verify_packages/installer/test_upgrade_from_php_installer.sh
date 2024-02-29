#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
old_version="0.79.0"
destdir="/tmp"
fetch_setup_for_version "$old_version" "$destdir"
php "$destdir/datadog-setup.php" --php-bin php
assert_ddtrace_version "${old_version}"

# Upgrade using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${version}"

assert_request_init_hook_exists
