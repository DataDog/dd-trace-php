#!/bin/bash -e

set -x
mkdir -p /tmp/logs/nginx

LOGS_PHP=(
  /tmp/logs/appsec.log
  /tmp/logs/helper.log
  /tmp/logs/php_error.log
  /tmp/logs/php_fpm_error.log
  /tmp/logs/sidecar.log
)
touch "${LOGS_PHP[@]}"
chown www-data "${LOGS_PHP[@]}"

LOGS_NGINX=(/var/log/nginx/{access.log,error.log})
touch "${LOGS_NGINX[@]}"
chown root:adm "${LOGS_NGINX[@]}"

enable_extensions.sh

php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini
service nginx start

exec tail -n +1 -F "${LOGS_PHP[@]}" "${LOGS_NGINX[@]}"
