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

# Php2ShellFrankenphpWorkerTests: no datadog.appsec.rules, so that remote
# config (ASM_DD) can replace the helper's default ruleset, and a memory_limit
# its runtime allocation crosses.
if [[ -n $APPSEC_P2S_WORKER_TEST ]]; then
  sed -i '/appsec.rules=/d' /etc/php/php.ini
  echo memory_limit=32M >> /etc/php/php.ini
fi

frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile &

exec tail -n +1 -F "${LOGS_PHP[@]}"
