#!/bin/sh

set -xe

# Installing
sh /install.sh

DD_AGENT_HOST=request-replayer DD_TRACE_AGENT_PORT=80 DD_TRACE_DEBUG=true DD_TRACE_CLI_ENABLED=true php /index.php
sleep 1

TRACES=$(curl -s -L request-replayer/replay)
# sh compatible way to do string contains
test "${TRACES#*trace_id}" != "$TRACES" && exit 0

echo "Error: response does not contains the work trace_id"
echo "${TRACES}"
exit 1
