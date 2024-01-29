#!/bin/bash -e

set -x

mkdir -p /tmp/logs/apache2
LOGS_PHP=(/tmp/logs/appsec.log /tmp/logs/helper.log /tmp/logs/php_error.log)
touch "${LOGS_PHP[@]}"
chown www-data:www-data "${LOGS_PHP[@]}"

LOGS_APACHE=(/tmp/logs/apache2/{access.log,error.log})
touch "${LOGS_APACHE[@]}"
chown root:adm "${LOGS_APACHE[@]}"

env | sed 's/^/export /' >> /etc/apache2/envvars
sed -i 's@APACHE_LOG_DIR=.*@APACHE_LOG_DIR=/tmp/logs/apache2@' /etc/apache2/envvars
#sed -i 's/\$HTTPD \${APACHE_ARGUMENTS} -k "\$ARGV"/\0 -X \&/' /usr/sbin/apache2ctl

enable_extensions.sh

service apache2 start

exec tail -F "${LOGS_PHP[@]}" "${LOGS_APACHE[@]}"
