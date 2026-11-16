#!/bin/bash -e

set -x

mkdir -p /tmp/logs/
LOGS_PHP=(
  /tmp/logs/frankenphp.log
  /tmp/logs/appsec.log
  /tmp/logs/helper.log
  /tmp/logs/php_error.log
  /tmp/logs/sidecar.log
)
touch "${LOGS_PHP[@]}"

cd /var/www

export DD_TRACE_CLI_ENABLED=false
composer install --no-dev

enable_extensions.sh

frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile &

exec tail -n +1 -F "${LOGS_PHP[@]}"
