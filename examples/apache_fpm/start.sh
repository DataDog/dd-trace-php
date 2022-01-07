#!/usr/bin/env bash

set -e

# vim: set ft=bash:
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
BUILD_DIR=$(realpath $1)
PHP_SBIN_DIR=$(realpath $2)
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


mkdir -p "$SCRIPT_DIR"/run

cat > "$CONF_FILE" <<EOD
ServerRoot "$SCRIPT_DIR/.."
ServerName 127.0.0.1

LoadModule mpm_event_module $MODULE_DIR/mod_mpm_event.so
$({ "$HTTPD" -l | grep -qF mod_log_config.c; } || echo LoadModule log_config_module $MODULE_DIR/mod_log_config.so)
LoadModule dir_module $MODULE_DIR/mod_dir.so
LoadModule authz_core_module $MODULE_DIR/mod_authz_core.so
$({ "$HTTPD" -l | grep -qF mod_unixd.c; } || echo LoadModule unixd_module $MODULE_DIR/mod_unixd.so)
LoadModule proxy_module $MODULE_DIR/mod_proxy.so
LoadModule proxy_fcgi_module $MODULE_DIR/mod_proxy_fcgi.so

Listen *:8080
ErrorLog $ERROR_FILE
#LogLevel debug
LogLevel warn
LogFormat "%h %l %u %t \"%r\" %>s %b" common
#CustomLog $ACCESS_FILE common

PidFile $PID_FILE
<IfModule mpm_worker_module>
  ServerLimit          20
  StartServers         1
  MaxRequestWorkers    8
  MinSpareThreads      2
  MaxSpareThreads      2
  ThreadsPerChild      8
</IfModule>

<Directory />
  AllowOverride None
  Require all denied
</Directory>
DocumentRoot $SCRIPT_DIR/../webroot
<Directory "$SCRIPT_DIR/../webroot">
  Require all granted
</Directory>

ProxyPassMatch "^/(.*\\.php(/.*)?)$" "fcgi://127.0.0.1:9001$(realpath "$SCRIPT_DIR/../webroot/")"
EOD

PHP_ERROR_LOG="$SCRIPT_DIR/run/php_error.log"
FPM_ERROR_LOG="$SCRIPT_DIR/run/fpm_error.log"
FPM_PID_FILE="$SCRIPT_DIR/run/fpm.pid"
APPSEC_LOG="$SCRIPT_DIR/run/appsec.log"
HELPER_LOG="$SCRIPT_DIR/run/helper.log"

cat > "$SCRIPT_DIR/run/php.ini" <<EOD
extension=$DDAPPSEC_EXTENSION
extension=$TRACER_EXTENSION
error_log=$PHP_ERROR_LOG
error_reporting=2147483647
ddappsec.enabled=1
ddappsec.log_file=$APPSEC_LOG
ddappsec.log_level=debug
ddappsec.rules_path=$RULES_FILE
ddappsec.helper_path=$DDAPPSEC_HELPER
ddappsec.helper_socket_path=$SCRIPT_DIR/run/ddappsec.sock
ddappsec.helper_lock_path=$SCRIPT_DIR/run/ddappsec.lock
ddappsec.helper_extra_args=--log_level debug
ddappsec.helper_log_file=$SCRIPT_DIR/run/helper.log

datadog.trace.agent_flush_after_n_requests=0
datadog.env=integration
datadog.service=appsec_int_tests
EOD

cat > "$SCRIPT_DIR/run/fpm.conf" <<EOD
[global]
log_level=notice
error_log=$FPM_ERROR_LOG
pid=$FPM_PID_FILE

[www]

listen = 127.0.0.1:9001
listen.allowed_clients = 127.0.0.1

pm = dynamic
pm.start_servers = 2
pm.max_children = 80
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
pm.status_path = /status
ping.path = /ping
ping.response = pong

rlimit_core = unlimited

clear_env = no
security.limit_extensions = .php
EOD

ALL_LOG_FILES=("$ERROR_FILE" "$ACCESS_FILE" "$PHP_ERROR_LOG" "$FPM_ERROR_LOG" "$APPSEC_LOG" "$HELPER_LOG")
rm -f "${ALL_LOG_FILES[@]}"
touch "${ALL_LOG_FILES[@]}"


if ! $HTTPD -f "$CONF_FILE"; then cat "$ERROR_FILE"; fi
"$PHP_SBIN_DIR"/php-fpm -y "$SCRIPT_DIR/run/fpm.conf" -c "$SCRIPT_DIR/run/php.ini"

function term {
  kill -TERM "$(< $PID_FILE)" "$(< $FPM_PID_FILE)"
  kill -9 `pgrep -f ddappsec-helper`
}
trap term EXIT

tail -f "${ALL_LOG_FILES[@]}"
