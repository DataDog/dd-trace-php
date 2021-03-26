#!/usr/bin/env bash

set -e

echo "Tracer to be installed: ${TRACER_DOWNLOAD_URL}"

if [ ! -z ${TRACER_DOWNLOAD_URL} ]; then
    echo "Installing tracer at: ${TRACER_DOWNLOAD_URL}"
    curl -L --output dd-trace-php.deb ${TRACER_DOWNLOAD_URL}
    dpkg -i dd-trace-php.deb
fi

DD_TRACE_CLI_ENABLED=true \
    DD_AGENT_HOST=agent \
    DD_SERVICE=local-cli-relenv \
    DD_ENV=relenv-local \
    DD_TRACE_GENERATE_ROOT_SPAN=false \
    php script.php
