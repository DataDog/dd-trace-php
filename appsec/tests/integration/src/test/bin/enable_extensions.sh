#!/bin/bash -e

{
  echo error_log=/tmp/logs/php_error.log
  echo display_errors=0
  echo error_reporting=-1
  echo max_execution_time=1800
} >> /etc/php/php.ini

if [[ -f /project/tmp/build_extension/modules/ddtrace.so ]]; then
  echo "Enabling ddtrace" >&2
  {
    echo extension=/project/tmp/build_extension/modules/ddtrace.so
    echo datadog.trace.sources_path=/project/src
    echo datadog.trace.generate_root_span=true
    echo datadog.trace.sidecar_trace_sender=0
  } >> /etc/php/php.ini
fi

if [[ -f /appsec/ddappsec.so && -d /project ]]; then
  echo "Enabling ddappsec" >&2
  {
    echo extension=/appsec/ddappsec.so
    echo datadog.appsec.enabled=true
    echo datadog.appsec.helper_path=/appsec/libddappsec-helper.so
    echo datadog.appsec.helper_log_file=/tmp/logs/helper.log
    echo datadog.appsec.helper_log_level=info
    echo datadog.appsec.rules=/etc/recommended.json
    echo datadog.appsec.log_file=/tmp/logs/appsec.log
    echo datadog.appsec.log_level=debug
  } >> /etc/php/php.ini
fi

if [[ -n $XDEBUG ]]; then
  echo "Enabling Xdebug" >&2
  {
    echo zend_extension = xdebug.so
    echo xdebug.mode = debug
    echo xdebug.start_with_request = yes
    echo xdebug.client_host = host.testcontainers.internal
    echo xdebug.client_port = 9003
    echo xdebug.log = /tmp/logs/xdebug.log
    # for xdebug 2
    echo xdebug.remote_enable = 1
    echo xdebug.remote_host = host.testcontainers.internal
    echo xdebug.remote_port = 9003
    echo xdebug.remote_autostart = 1
    echo xdebug.remote_mode = req
    echo xdebug.default_enable = 0
    echo xdebug.profiler_enable = 0
    echo xdebug.auto_trace = 0
    echo xdebug.coverage_enable = 0
    echo xdebug.remote_log = /tmp/logs/xdebug.log
  } >> /etc/php/php.ini
fi
