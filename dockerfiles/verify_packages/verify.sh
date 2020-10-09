#!/bin/sh

set -e

# Installing
sh $(pwd)/dockerfiles/verify_packages/${OS_NAME}/install.sh

# We attempt in this order the following binary names:
#    1. php
#    2. php7 (some alpine versions install php 7.x from main repo to this binary)
#    3. php5 (some alpine versions install php 5.x from main repo to this binary)
DD_TRACE_PHP_BIN=$(command -v php || true)
if [ -z "$DD_TRACE_PHP_BIN" ]; then
    DD_TRACE_PHP_BIN=$(command -v php7 || true)
fi
if [ -z "$DD_TRACE_PHP_BIN" ]; then
    DD_TRACE_PHP_BIN=$(command -v php5 || true)
fi

PHP_INDEX=$(pwd)/dockerfiles/verify_packages/index.php

DD_AGENT_HOST=request-replayer DD_TRACE_AGENT_PORT=80 DD_TRACE_DEBUG=true DD_TRACE_CLI_ENABLED=true ${DD_TRACE_PHP_BIN} ${PHP_INDEX}
sleep 1

TRACES=$(curl -s -L request-replayer/replay)

echo "Received traces: ${TRACES}"

# sh compatible way to do string contains
test "${TRACES#*trace_id}" != "$TRACES" && echo "SUCCESS: TRACE SUCCESSFULLY RECEIVED BY THE AGENT" && exit 0

echo "ERROR: response does not contains the work trace_id"
echo "${TRACES}"
exit 1
