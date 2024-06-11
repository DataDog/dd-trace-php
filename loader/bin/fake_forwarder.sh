#!/usr/bin/env bash

echo "$@" >> ${FAKE_FORWARDER_LOG_PATH:="/tmp/fake_forwarder.log"}
