#!/usr/bin/env sh

set -e

apk add --no-cache bash curl libexecinfo php7

echo "Installing dd-trace-php using the OS-specific package installer"

set +e
apk add --no-cache $(pwd)/build/packages/*.apk --allow-untrusted
exit_status=$?
set -e

if [ "${exit_status}" = "1" ]; then
    printf "Ok: expected exit status 1 (json module is missing)\n"
else
    printf "Error: Unexpected exit status ${exit_status}. Should be 1\n"
    exit 1
fi

output_last_line=$(echo "${output}" | tail -1)
if [ -z "${output_last_line##*json*}" ]; then
    printf "Ok: output contains text 'json'\n"
else
    printf "Error: Output does not contain text 'json'. Output is\n---\n${output_last_line}\n---\n"
    exit 1
fi
