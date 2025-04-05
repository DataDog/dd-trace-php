#!/bin/bash -e

set -x

cd /var/www

composer install --no-dev
if [[ ! -f rr ]]; then
  vendor/bin/rr get-binary
fi

mkdir -p /tmp/logs/apache2
LOGS_PHP=(/tmp/logs/appsec.log /tmp/logs/helper.log /tmp/logs/php_error.log /tmp/logs/rr.log)
touch "${LOGS_PHP[@]}"

enable_extensions.sh
echo datadog.trace.cli_enabled=true >> /etc/php/php.ini

./rr serve >> /tmp/logs/rr.log 2>&1 &

tail -n +1 -F "${LOGS_PHP[@]}"

