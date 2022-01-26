#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Fixing permissions, as this test is run in our own custom image using circleci as the executor
sudo chmod a+w ./build/packages/*

switch-php debug-zts-asan

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
    printf "Ok: expected exit status 1\n"
else
    printf "Error: Unexpected exit status ${exit_status}. Should be 1\n"
    exit 1
fi

output_last_line=$(echo "${output}" | tail -1)
if [ -z "${output_last_line##*ZTS DEBUG*}" ]; then
    printf "Ok: output contains text 'ZTS DEBUG'\n"
else
    printf "Error: Output does not contain text 'ZTS DEBUG'. Output is\n---\n${output_last_line}\n---\n"
    exit 1
fi

assert_no_ddtrace
