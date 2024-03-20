#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

switch-php debug-zts-asan

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
set +e
output=$(php ./build/packages/datadog-setup.php --php-bin php)
exit_status=$?
set -e

if [ "${exit_status}" = "1" ]; then
    printf "Ok: expected exit status 1\n"
else
    printf "Error: Unexpected exit status ${exit_status}. Should be 1\n"
    exit 1
fi

output_last_line=$(echo "${output}" | tail -1)
if [ -z "${output_last_line##*debug-zts*}" ]; then
    printf "Ok: output contains text 'debug-zts'\n"
else
    printf "Error: Output does not contain text 'debug-zts'. Output is\n---\n${output_last_line}\n---\n"
    exit 1
fi

assert_no_ddtrace
