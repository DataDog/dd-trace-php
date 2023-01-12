#!/bin/bash -e


LOGS_PHP=(/tmp/appsec.log /tmp/helper.log /tmp/php_error.log)
#touch "${LOGS_PHP[@]}"
#chown www-data:www-data "${LOGS_PHP[@]}"

LOGS_APACHE=(/var/log/apache2/{access.log,error.log})
touch "${LOGS_APACHE[@]}"
chown root:adm "${LOGS_APACHE[@]}"

env | sed 's/^/export /' >> /etc/apache2/envvars

service apache2 start

exec tail -F "${LOGS_PHP[@]}" "${LOGS_APACHE[@]}"
