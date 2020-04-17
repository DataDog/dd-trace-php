#!/usr/bin/env bash

if [[ -n "${DD_TRACE_LIBRARY_VERSION}" ]]; then
    # Install DDTrace deb
    wget -O /datadog-php-tracer.deb https://github.com/DataDog/dd-trace-php/releases/download/${DD_TRACE_LIBRARY_VERSION}/datadog-php-tracer_${DD_TRACE_LIBRARY_VERSION}_amd64.deb
    dpkg -i /datadog-php-tracer.deb
fi
php-fpm -i | grep xdebug

if [[ "$XDEBUG_ENABLE_PROFILER" != "1" ]]; then
    rm /usr/local/etc/php/conf.d/xdebug.ini
fi

if [[ "$DD_TRACE_ENABLED" != "true" ]]; then
    rm /usr/local/etc/php/conf.d/ddtrace.ini
fi

chown www-data:www-data -R /var/www
sudo chmod go+w /var/www/callgrind-files

php-fpm
