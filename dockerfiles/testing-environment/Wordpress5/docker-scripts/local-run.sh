#!/usr/bin/env bash

set -e

echo "Starting up local testing suervisor."
echo "Tracer to be installed: ${TRACER_DOWNLOAD_URL}"

if [ ! -z ${TRACER_DOWNLOAD_URL} ]; then
    echo "Installing tracer at: ${TRACER_DOWNLOAD_URL}"
    wget -O dd-trace-php.deb ${TRACER_DOWNLOAD_URL}
    dpkg -i dd-trace-php.deb
fi

supervisord
