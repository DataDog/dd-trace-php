#!/usr/bin/env bash
set -euo pipefail

# 1st argument is always "library_entrypoint", the 2nd is the json payload
echo "${@:2}" >> ${FAKE_FORWARDER_LOG_PATH:="/tmp/fake_forwarder.log"}
