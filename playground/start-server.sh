#!/usr/bin/env bash

set -e

SCRIPT_DIR="$( cd "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )"
PROJECT_ROOT=$(dirname "$SCRIPT_DIR")
SRC_ROOT="$PROJECT_ROOT/src"

echo "Script directory: ${SCRIPT_DIR}"
echo "Project root: ${PROJECT_ROOT}"
echo "Serving with tracer sources path: ${SRC_ROOT}"

echo "Installing the latest extension"
make -C "${PROJECT_ROOT}" sudo debug install install_ini
echo "Done installing the extension"

DD_AGENT_HOST=agent \
    DD_TRACE_DEBUG=true \
    DD_AUTOLOAD_NO_COMPILE=true \
    php \
    -d error_log=/dev/stderr \
    -d datadog.trace.sources_path=${SRC_ROOT} \
    -S 0.0.0.0:8000 \
    -t "${SCRIPT_DIR}"
