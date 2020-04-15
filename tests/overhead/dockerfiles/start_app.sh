#!/usr/bin/env bash

composer --working-dir=/var/www install

if [[ -z "${DD_TRACE_LIBRARY_VERSION}" ]]; then
    # Install DDTrace deb
    wget -O /datadog-php-tracer.deb https://github.com/DataDog/dd-trace-php/releases/download/${DD_TRACE_LIBRARY_VERSION}/datadog-php-tracer_${DD_TRACE_LIBRARY_VERSION}_amd64.deb
    dpkg -i /datadog-php-tracer.deb
fi

php-fpm
