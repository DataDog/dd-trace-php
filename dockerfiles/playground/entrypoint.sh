#!/bin/bash -e
# Playground entrypoint: enable extensions, run the fixture's docker-init.sh,
# start apache2 in the foreground, and tail the logs.
#
# Env in (set by docker-compose from the Makefile):
#   APPSEC    1 (default) or 0 — toggles DD_APPSEC_ENABLED. The extension is
#             always loaded; this env just gates its runtime activation.
#   TELEMETRY 0 (default) or 1 — toggles DD_INSTRUMENTATION_TELEMETRY_ENABLED.

set -x

mkdir -p /tmp/logs /tmp/logs/apache2
touch /tmp/logs/appsec.log /tmp/logs/helper.log /tmp/logs/php_error.log \
      /tmp/logs/sidecar.log /tmp/logs/apache2/access.log /tmp/logs/apache2/error.log
chown www-data:www-data /tmp/logs/appsec.log /tmp/logs/helper.log \
      /tmp/logs/php_error.log /tmp/logs/sidecar.log

# php.ini basics (shared with the appsec integration-test harness).
{
  echo error_log=/tmp/logs/php_error.log
  echo display_errors=0
  echo error_reporting=-1
  echo max_execution_time=1800
} >> /etc/php/php.ini

# Tracer: always loaded. The .so is produced by the repo's `make all` target,
# which the host-side Makefile ran before `docker compose up`.
{
  echo extension=/project/tmp/build_extension/modules/ddtrace.so
  echo datadog.trace.sources_path=/project/src
  echo datadog.trace.generate_root_span=true
  echo datadog.trace.log_level=debug
} >> /etc/php/php.ini

# AppSec: extension loaded, enablement gated by DD_APPSEC_ENABLED (set via
# compose from the APPSEC env). This matches how production users toggle it.
{
  echo extension=/appsec/ddappsec.so
  echo datadog.appsec.helper_log_file=/tmp/logs/helper.log
  echo datadog.appsec.helper_log_level=debug
  echo datadog.appsec.rules=/etc/recommended.json
  echo datadog.appsec.log_file=/tmp/logs/appsec.log
  echo datadog.appsec.log_level=debug
  echo datadog.appsec.rasp_enabled=1
} >> /etc/php/php.ini

# Fixture setup. Each framework's docker-init.sh handles its own quirks
# (composer install, SQLite seed, config overrides, etc).
if [[ -f /var/www/docker-init.sh ]]; then
  echo "Running fixture docker-init.sh" >&2
  bash /var/www/docker-init.sh
fi

# Apache logs under /tmp so they're easy to tail without touching the image.
sed -i 's@APACHE_LOG_DIR=.*@APACHE_LOG_DIR=/tmp/logs/apache2@' /etc/apache2/envvars
# Pass container env to apache workers.
env | sed 's/^/export /' >> /etc/apache2/envvars

service apache2 start

exec tail -n +1 -F /tmp/logs/php_error.log /tmp/logs/appsec.log \
     /tmp/logs/helper.log /tmp/logs/sidecar.log \
     /tmp/logs/apache2/access.log /tmp/logs/apache2/error.log
