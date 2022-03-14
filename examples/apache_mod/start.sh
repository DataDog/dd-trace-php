#!/usr/bin/env bash

set -e

# vim: set ft=bash:
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
BUILD_DIR=$(realpath $1)
PHP_APACHE_MODULE=$(realpath $2)
DDAPPSEC_EXTENSION=$(realpath $3)
TRACER_EXTENSION=$(realpath $4)
DDAPPSEC_HELPER=$(realpath $5)
RULES_FILE=$(realpath $6)
CONF_FILE=$SCRIPT_DIR/run/apache.cfg
PID_FILE=$SCRIPT_DIR/run/apache.pid
ERROR_FILE=$SCRIPT_DIR/run/error.log
ACCESS_FILE=$SCRIPT_DIR/run/access.log
MODULE_DIR=$(apxs -q LIBEXECDIR)
if which httpd > /dev/null; then
  HTTPD=httpd
else
  HTTPD=apache2
fi
if [[ $TRACER_EXTENSION =~ non-zts ]]; then
  MPM=prefork
else
  MPM=worker
fi


mkdir -p "$SCRIPT_DIR"/run

cat > "$CONF_FILE" <<EOD
ServerRoot "$SCRIPT_DIR/.."
ServerName 127.0.0.1

LoadModule mpm_${MPM}_module $MODULE_DIR/mod_mpm_${MPM}.so
$({ "$HTTPD" -l | grep -qF mod_log_config.c; } || echo LoadModule log_config_module $MODULE_DIR/mod_log_config.so)
LoadModule dir_module $MODULE_DIR/mod_dir.so
LoadModule authz_core_module $MODULE_DIR/mod_authz_core.so
$({ "$HTTPD" -l | grep -qF mod_unixd.c; } || echo LoadModule unixd_module $MODULE_DIR/mod_unixd.so)

Listen *:8080
ErrorLog $ERROR_FILE
LogLevel debug
LogFormat "%h %l %u %t \"%r\" %>s %b" common
CustomLog $ACCESS_FILE common

PidFile $PID_FILE
<IfModule mpm_worker_module>
  ServerLimit          1
  StartServers         1
  MaxRequestWorkers    8
  MinSpareThreads      2
  MaxSpareThreads      2
  ThreadsPerChild      8
</IfModule>
<IfModule mpm_prefork_module>
  StartServers             1
  MinSpareServers       1
  MaxSpareServers      1
  MaxRequestWorkers     1
  MaxConnectionsPerChild   0
</IfModule>

<Directory />
  AllowOverride None
  Require all denied
</Directory>
DocumentRoot $SCRIPT_DIR/../webroot
<Directory "$SCRIPT_DIR/../webroot">
  Require all granted
</Directory>

LoadModule php$(grep -q libphp7 <<< "$PHP_APACHE_MODULE" && echo 7)_module "$PHP_APACHE_MODULE"
<FilesMatch \.php$>
    SetHandler application/x-httpd-php
</FilesMatch>
<FilesMatch "\.phps$">
    SetHandler application/x-httpd-php-source
</FilesMatch>

PHPIniDir "$SCRIPT_DIR/run/php.ini"
EOD

PHP_ERROR_LOG="$SCRIPT_DIR/run/php_error.log"
APPSEC_LOG="$SCRIPT_DIR/run/appsec.log"
HELPER_LOG="$SCRIPT_DIR/run/helper.log"
cat > "$SCRIPT_DIR/run/php.ini" <<EOD
extension=$DDAPPSEC_EXTENSION
extension=$TRACER_EXTENSION
error_log=$PHP_ERROR_LOG
error_reporting=2147483647
datadog.appsec.enabled=1
datadog.appsec.log_file=$APPSEC_LOG
datadog.appsec.log_level=debug
datadog.appsec.rules=$RULES_FILE
datadog.appsec.helper_path=$DDAPPSEC_HELPER
datadog.appsec.helper_socket_path=$SCRIPT_DIR/run/ddappsec.sock
datadog.appsec.helper_lock_path=$SCRIPT_DIR/run/ddappsec.lock
datadog.appsec.helper_extra_args=--log_level debug
datadog.appsec.helper_log_file=$SCRIPT_DIR/run/helper.log

datadog.trace.agent_flush_after_n_requests=0
datadog.env=integration
datadog.service=appsec_int_tests
EOD

ALL_LOG_FILES=("$ERROR_FILE" "$ACCESS_FILE" "$PHP_ERROR_LOG" "$APPSEC_LOG" "$HELPER_LOG")
rm -f "${ALL_LOG_FILES[@]}"
touch "${ALL_LOG_FILES[@]}"


if [[ -n $LLDB ]]; then
  exec lldb -- $HTTPD -f "$CONF_FILE" -X
elif [[ -n $GDB ]]; then
  exec gdb --args $HTTPD -f "$CONF_FILE" -X
else
  if ! $HTTPD -f "$CONF_FILE"; then cat "$ERROR_FILE"; fi
fi

function term_httpd {
  kill -TERM "$(< $PID_FILE)"
  kill -9 `pgrep -f ddappsec-helper`
}
trap term_httpd EXIT

tail -f "${ALL_LOG_FILES[@]}"
