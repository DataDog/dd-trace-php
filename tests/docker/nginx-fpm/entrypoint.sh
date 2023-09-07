#!/bin/bash -e

LOGS_PHP=(/tmp/appsec.log /tmp/helper.log /tmp/php_error.log /tmp/php_fpm_error.log)

LOGS_NGINX=(/var/log/nginx/{access.log,error.log})
touch "${LOGS_NGINX[@]}"
chown root:adm "${LOGS_NGINX[@]}"

php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini
service nginx start

exec tail -F "${LOGS_PHP[@]}" "${LOGS_NGINX[@]}"
