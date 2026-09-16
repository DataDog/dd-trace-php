#!/bin/bash

set -ex

LOGS_PHP=(
  /tmp/logs/appsec.log
  /tmp/logs/helper.log
  /tmp/logs/php_error.log
  /tmp/logs/sidecar.log
)
LOGS_APACHE=(
  /tmp/logs/apache2/access.log
  /tmp/logs/apache2/error.log
)
touch "${LOGS_PHP[@]}" "${LOGS_APACHE[@]}"
chown apache:apache "${LOGS_PHP[@]}" "${LOGS_APACHE[@]}"

enable_extensions.sh

exec httpd -DFOREGROUND
