#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
old_version="0.79.0"
sha256sum="35532a2b78fae131f61f89271e951b8c050a459e7d10fd579665c2669be8fdad"
destdir="/tmp"
fetch_setup_for_version "$version" "$sha256sum" "$destdir"
php "$destdir/datadog-setup.php" --php-bin php
assert_ddtrace_version "${old_version}"

# Upgrade using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${version}"

assert_request_init_hook_exists
