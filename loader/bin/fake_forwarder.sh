#!/usr/bin/env bash
set -eo pipefail

echo "$@" >> ${FAKE_FORWARDER_LOG_PATH:="/tmp/fake_forwarder.log"}
