#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
new_version="0.69.0"
generate_installers "${new_version}"

set +e
output=$(php ./build/packages/datadog-setup.php --php-bin php --enable-profiling)
exit_status=$?
set -e

if [ "${exit_status}" = "1" ]; then
    dashed_print "Ok: expected exit status 1." "${exit_status}"
else
    dashed_print "Error: Unexpected exit status. Should be 1." "${exit_status}"
    exit 1
fi

if [ -z "${output##*not supported*}" ]; then
    dashed_print "Ok: Output contains text 'not supported'." "${output}"
else
    dashed_print "Error: output did not contain 'not supported' as expected." "${output}"
    exit 1
fi
