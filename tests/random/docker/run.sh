#!/usr/bin/env bash

set -e

# Install the tracers
if [ "${INSTALL_MODE}" == "package" ]; then
    tar -xf /tmp/tracer-versions/ddtrace-test.tar.gz -C /
    sh /opt/datadog-php/bin/post-install.sh
elif [ "${INSTALL_MODE}" == "pecl" ]; then
    echo "PECL installation mode not supported yet"
    exit 1
else
    echo "Unknown installation mode"
    exit 1
fi

# Start PHP-FPM
echo "Starting PHP-FPM"
php-fpm -D

# Start nginx
echo "Starting nginx"
nginx

echo "Waiting ..."
sleep 1

composer --working-dir=/var/www/html install

echo "GET http://localhost" | vegeta attack -format=http -duration=2s -keepalive=true -max-workers=5 -rate=0 | tee results.bin | vegeta report --type=json --output=/results/${TEST_SCENARIO}.json
