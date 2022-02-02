#!/usr/bin/env bash

set -e

# Install the tracers
if [ "${INSTALL_MODE}" == "package" ]; then
    # Do not enable profiling here as profiling is enabled as part of the randomized configuration
    php \
        /dd-trace-php/datadog-setup.php \
            --php-bin=all \
            --file=/tmp/library-versions/dd-library-php-x86_64-linux-gnu.tar.gz
elif [ "${INSTALL_MODE}" == "pecl" ]; then
    echo "PECL installation mode not supported yet"
    exit 1
else
    echo "Unknown installation mode"
    exit 1
fi

php -v

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

# Wait for problematic (host:port)s to be available
echo "Waiting for elasticsearch"
bash /scripts/wait-for.sh elasticsearch:9200 -t 30
echo "Waiting for mysql"
bash /scripts/wait-for.sh mysql:3306 -t 30
