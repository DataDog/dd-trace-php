#!/bin/sh

set -e

if [ -z "${PHP_INSTALL_DIR}" ]; then
    echo "Please set PHP_INSTALL_DIR"
    exit 1
fi

for phpVer in $(ls ${PHP_INSTALL_DIR}); do
    echo "Installing ddtrace on PHP ${phpVer}..."
    switch-php $phpVer

    # Installing dd-trace-php
    INSTALL_TYPE="${INSTALL_TYPE:-php_installer}"
    if [ "$INSTALL_TYPE" = "native_package" ]; then
        echo "Installing dd-trace-php using the OS-specific package installer"
        rpm -Uvh /build_src/build/packages/*.rpm
        php --ri=ddtrace

        # Uninstall the tracer
        rpm -e datadog-php-tracer
        rm -f /opt/datadog-php/etc/ddtrace.ini
    else
        echo "Installing dd-trace-php using the new PHP installer"
        php /build_src/dd-library-php-setup.php --file /build_src/build/packages/dd-library-php-x86_64-linux-musl.tar.gz --php-bin all
    fi
done
