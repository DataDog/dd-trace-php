#!/usr/bin/env bash
set -euo pipefail

exec </dev/null >/dev/null 2>&1

# Bound the wait even if the test runner is killed before releasing the gate.
for ((i = 0; i < 3000; ++i)); do
    if [[ -e "${FAKE_FORWARDER_RELEASE_PATH}" ]]; then
        echo "${*:2}" >> "${FAKE_FORWARDER_LOG_PATH}"
        exit 0
    fi
    sleep 0.01
done
exit 1
