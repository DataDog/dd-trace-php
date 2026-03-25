#!/bin/bash

set -ex

# Create log directories and files (matches nginx-fpm/entrypoint.sh)
mkdir -p /tmp/logs
LOGS_PHP=(
  /tmp/logs/appsec.log
  /tmp/logs/helper.log
  /tmp/logs/php_error.log
  /tmp/logs/php_fpm_error.log
  /tmp/logs/sidecar.log
)
touch "${LOGS_PHP[@]}"
chown linux_user "${LOGS_PHP[@]}"

# Enable extensions (writes ddtrace/ddappsec to php.ini)
enable_extensions.sh

# Start PHP-FPM in the background with custom php.ini location
php-fpm -c /etc/php &

# Start nginx in the foreground
exec nginx -g 'daemon off;'
