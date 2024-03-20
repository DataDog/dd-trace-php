#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

version=$(cat VERSION)

useradd -m datadog -p datadog
usermod -a -G datadog datadog

set +e
output=$(su datadog -c "php /app/build/packages/datadog-setup.php --php-bin php")
exit_status=$?
set -e

if [ "${exit_status}" = "1" ]; then
    echo "---\nOk: expected exit status 1\n"
else
    echo "---\nError: Unexpected exit status. Should be 1\n---\n${exit_status}\n---\n"
    exit 1
fi

if [ -z "${output##*Cannot create directory \'/opt/datadog/dd-library/${version}/dd-trace-sources\'*}" ]; then
    echo "Ok: Output contains - Cannot create directory '/opt/datadog/dd-library/${version}/dd-trace-sources'"
else
    echo "Error: Output does not contain - Cannot create directory '/opt/datadog/dd-library/${version}/dd-trace-sources'\n---\n${output}\n---\n"
    exit 1
fi
