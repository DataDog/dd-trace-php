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

enable_extensions.sh

frankenphp php-server -a -v -r /test-resources/public > /tmp/logs/frankenphp.log 2>&1 &

exec tail -n +1 -F "${LOGS_PHP[@]}"
