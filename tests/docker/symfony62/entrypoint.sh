#!/bin/bash -e


LOGS_PHP=(/tmp/appsec.log /tmp/helper.log /tmp/php_error.log /tmp/php_fpm_error.log)

LOGS_APACHE=(/var/log/apache2/{access.log,error.log})
touch "${LOGS_APACHE[@]}"
chown root:adm "${LOGS_APACHE[@]}"

env | sed 's/^/export /' >> /etc/apache2/envvars

php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini
service apache2 start

mkdir -p /var/www/html/var/log/
touch /var/www/html/var/log/dev.log
chown www-data.www-data /var/www/html/var/log/dev.log
LOGS_SYMFONY=(/var/www/html/var/log/dev.log)

exec tail -F "${LOGS_PHP[@]}" "${LOGS_APACHE[@]}" "${LOGS_SYMFONY[@]}"
