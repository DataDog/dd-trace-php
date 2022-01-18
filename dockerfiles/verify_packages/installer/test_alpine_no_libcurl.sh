#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

apk add php7

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.68.0"
generate_installers "${new_version}"

set +e
output=$(php ./build/packages/datadog-setup.php --php-bin php)
exit_status=$?
set -e

if [ "${exit_status}" = "1" ]; then
    echo "---\nOk: expected exit status 1\n---\n${exit_status}\n---\n"
else
    echo "---\nError: Unexpected exit status. Should be 1\n---\n${exit_status}\n---\n"
    exit 1
fi

if [ -z "${output##*libcurl*}" ]; then
    echo "Ok: Output contains text 'libcurl'"
else
    echo "---\nError: Output does not contain text 'libcurl'\n---\n${output}\n---\n"
    exit 1
fi
