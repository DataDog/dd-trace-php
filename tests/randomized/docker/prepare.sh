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
mkdir -p /var/log/php-fpm/
chmod a+w /var/log/php-fpm/
php-fpm -D
sleep 1

# Start nginx
echo "Starting nginx"
nginx
sleep 1

# Start Apache
echo "Starting apache"
httpd
sleep 1

composer --working-dir=/var/www/html install

# Wait for preblematic (host:port)s to be available here.
echo "Waiting for elasticsearch"
bash /scripts/wait-for.sh elasticsearch:9200 -t 30
