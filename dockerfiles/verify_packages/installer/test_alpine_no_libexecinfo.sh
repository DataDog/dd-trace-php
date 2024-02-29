#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="1.0.0-does-not-exist"
generate_installers "${new_version}"

set +e
output=$(php /tmp/datadog-setup.php --php-bin php)
exit_status=$?
set -e

if [ "${exit_status}" = "1" ]; then
    echo "---\nOk: expected exit status 0\n---\n${exit_status}\n---\n"
else
    echo "---\nError: Unexpected exit status. Should be 0\n---\n${exit_status}\n---\n"
    exit 1
fi

