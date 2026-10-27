#!/bin/bash -e

set -x

mkdir -p /tmp/logs
logs=(
  /tmp/logs/appsec.log
  /tmp/logs/helper.log
  /tmp/logs/php_error.log
  /tmp/logs/php_server.log
  /tmp/logs/sidecar.log
)
touch "${logs[@]}"

enable_extensions.sh
echo datadog.trace.cli_enabled=true >> /etc/php/php.ini

php -S 0.0.0.0:80 -t /var/www/public \
  >> /tmp/logs/php_server.log 2>&1 &

exec tail -n +1 -F "${logs[@]}"
