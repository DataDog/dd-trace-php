#!/usr/bin/env bash

set -uo pipefail

build_log=$(mktemp)
trap 'rm -f "$build_log"' EXIT

"$@" 2>&1 | tee "$build_log"
build_status=${PIPESTATUS[0]}

if [ "$build_status" -eq 0 ]; then
    exit 0
fi

# GitLab retries exit 75. Restrict this to errors that indicate a transient
# GitHub/libddwaf download failure; missing releases and build errors should
# retain the original status and fail immediately.
github_server_error='Failed to download archive from https://github\.com/'
github_server_error+='.*: 5[0-9]{2} '
transport_error='Failed to (download archive|write archive entry contents'
transport_error+=' to file):.*reqwest::Error.*'
transport_error+='(ConnectionReset|ConnectionAborted|TimedOut|IncompleteMessage)'
if grep -Eq "$github_server_error|$transport_error" "$build_log"; then
    echo "Transient GitHub download failure; exiting 75 for GitLab retry." >&2
    exit 75
fi

exit "$build_status"
