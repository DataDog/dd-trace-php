#!/bin/bash -e

set -x
set -o pipefail

cd /var/www

composer install --no-dev
if [[ ! -f rr ]]; then
  # Uses the github API, which is flaky
  #vendor/bin/rr get-binary
  if [[ $(arch) == "arm64" ]]; then
    ARCH="arm64"
  else
    ARCH="amd64"
  fi

  curl -Lf https://github.com/roadrunner-server/roadrunner/releases/download/v2.12.3/roadrunner-2.12.3-linux-$ARCH.tar.gz | \
    tar -xzf - --strip-components=1 roadrunner-2.12.3-linux-$ARCH/rr
fi

mkdir -p /tmp/logs/apache2
LOGS_PHP=(/tmp/logs/appsec.log /tmp/logs/helper.log /tmp/logs/php_error.log /tmp/logs/rr.log)
touch "${LOGS_PHP[@]}"

enable_extensions.sh
echo datadog.trace.cli_enabled=true >> /etc/php/php.ini

./rr serve >> /tmp/logs/rr.log 2>&1 &

tail -n +1 -F "${LOGS_PHP[@]}"

