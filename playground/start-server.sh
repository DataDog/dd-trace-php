#!/usr/bin/env bash

set -e

SCRIPT_DIR="$( cd "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )"
PROJECT_ROOT=$(dirname "$SCRIPT_DIR")
REQUEST_INIT_HOOK="$PROJECT_ROOT/bridge/dd_wrap_autoloader.php"

echo "Script directory: ${SCRIPT_DIR}"
echo "Project root: ${PROJECT_ROOT}"
echo "Serving with request init hook: ${REQUEST_INIT_HOOK}"

echo "Installing the latest extension"
composer --working-dir="${PROJECT_ROOT}" install-ext
echo "Done installing the extension"

DD_AGENT_HOST=agent \
    DD_TRACE_DEBUG=true \
    php \
    -d error_log=/dev/stderr \
    -d ddtrace.request_init_hook=${REQUEST_INIT_HOOK} \
    -S 0.0.0.0:8000 \
    -t "${SCRIPT_DIR}"
