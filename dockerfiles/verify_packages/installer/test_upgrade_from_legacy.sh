#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# 0.74.0 doesn't exist on arm
uname=$(uname -a)
if [ -z "${uname##*arm*}" ] || [ -z "${uname##*aarch*}" ]; then
  exit 0
fi

# Initially no ddtrace
assert_no_ddtrace

# Install using the legacy method
old_version="0.64.0"
install_legacy_ddtrace "${old_version}"
assert_ddtrace_version "${old_version}"

# Upgrade using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php
assert_ddtrace_version "${new_version}"

# Assert that there are no deprecation warnings from old ddtrace.request_init_hook
if [ -z "$(php --ri ddtrace | grep 'use DD_TRACE_REQUEST_INIT_HOOK instead')" ]; then
    echo "\nOk: request init hook param has been updated\n"
else
    echo "\nError: request init hook param has not been updated\n---\n$(php --ri ddtrace)\n---\n"
    exit 1
fi

assert_sources_path_exists
