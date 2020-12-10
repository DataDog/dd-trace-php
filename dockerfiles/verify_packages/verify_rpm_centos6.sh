#!/bin/sh

set -e

if [ -z "${PHP_INSTALL_DIR}" ]; then
    echo "Please set PHP_INSTALL_DIR"
    exit 1
fi

for phpVer in $(ls ${PHP_INSTALL_DIR}); do
    echo "Installing ddtrace on PHP ${phpVer}..."
    switch-php $phpVer
    rpm -Uvh /build_src/build/packages/*.rpm
    php --ri=ddtrace

    # Uninstall the tracer
    rpm -e datadog-php-tracer
    rm -f /opt/datadog-php/etc/ddtrace.ini
done
