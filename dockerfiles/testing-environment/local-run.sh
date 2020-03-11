#!/usr/bin/env bash

set -e

echo "Starting up local testing suervisord."
echo "Tracer to be installed: ${TRACER_DOWNLOAD_URL}"

if [ ! -z ${TRACER_DOWNLOAD_URL} ]; then
    echo "Installing tracer at: ${TRACER_DOWNLOAD_URL}"
    wget -O dd-trace-php.deb ${TRACER_DOWNLOAD_URL}
    dpkg -i dd-trace-php.deb
fi

#### For dubug purposes
ulimit -c unlimited
mkdir -p /cores
chmod -R 777 /cores/
echo '/cores/core_%e.%p' | tee /proc/sys/kernel/core_pattern


supervisord

# php -S 0.0.0.0:80 /var/www/html/public/index.php
