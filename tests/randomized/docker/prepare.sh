#!/usr/bin/env bash

set -e

# Install the tracers
if [ "${INSTALL_MODE}" == "package" ]; then
    # Do not enable profiling here as profiling is enabled as part of the randomized configuration
    php \
        /dd-trace-php/datadog-setup.php \
            --php-bin=all \
            --file=/tmp/library-versions/dd-library-php.tar.gz
elif [ "${INSTALL_MODE}" == "pecl" ]; then
    echo "PECL installation mode not supported yet"
    exit 1
elif [ "${INSTALL_MODE}" == "notracer" ]; then
    echo "Tracer not installed"
else
    echo "Unknown installation mode"
    exit 1
fi

php -v

# Start PHP-FPM
echo "Starting PHP-FPM"
mkdir -p /var/log/php-fpm/
chmod a+w /var/log/php-fpm/
if ldd $(which php) 2>/dev/null | grep -q libasan; then
  php-fpm -D -d datadog.trace.log_file=/results/dd_php_error.log
else
  nohup strace -ttfs 200 bash -c 'php-fpm -F -d datadog.trace.log_file=/results/dd_php_error.log 2>&3' 0<&- 3>&2 2>/results/php-fpm.strace >/dev/null &
fi
sleep 1

# Start nginx
echo "Starting nginx"
nginx
sleep 1

if [ -f /usr/lib/apache2/modules/libphp.so ]; then
    # Start Apache
    echo "Starting apache"
    command -v httpd && httpd || apachectl start
    sleep 1
fi

# php cli logs
mkdir -p /var/log/php/
chmod a+w /var/log/php/

composer --working-dir=/var/www/html install

# Avoid intermittent DNS hangs: See https://github.com/curl/curl/issues/593#issuecomment-170146252
echo "options single-request" >> /etc/resolv.conf

# Wait for problematic (host:port)s to be available
echo "Waiting for elasticsearch"
bash /scripts/wait-for.sh elasticsearch:9200 -t 60
echo "Waiting for mysql"
bash /scripts/wait-for.sh mysql:3306 -t 30
echo "Waiting for the agent"
bash /scripts/wait-for.sh agent:8126 -t 30

# Fix elastic search auto-readonly depending on available disk space to reduce flakiness
# https://www.elastic.co/guide/en/elasticsearch/reference/6.2/disk-allocator.html
curl "elasticsearch:9200/_cluster/settings?pretty" -X PUT -H 'Content-Type: application/json'  -d '
{
  "transient": {
    "cluster.routing.allocation.disk.threshold_enabled": false
  }
}'
