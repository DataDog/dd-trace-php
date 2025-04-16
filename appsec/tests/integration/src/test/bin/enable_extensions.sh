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
#    echo datadog.appsec.helper_log_level=trace
    echo datadog.appsec.rules=/etc/recommended.json
    echo datadog.appsec.log_file=/tmp/logs/appsec.log
    echo datadog.appsec.log_level=debug
    echo datadog.appsec.rasp_enabled=1
  } >> /etc/php/php.ini
fi

if [[ -n $XDEBUG ]]; then
  echo "Enabling Xdebug" >&2
  touch /tmp/logs/xdebug.log
  chown www-data:www-data /tmp/logs/xdebug.log
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

if [[ -n $OPCACHE ]]; then
  echo "Enabling opcache" >&2
  touch /tmp/logs/opcache.log
  mkdir -p /tmp/opcache
  chown www-data:www-data /tmp/logs/opcache.log /tmp/opcache
  {
    echo zend_extension=opcache.so
    echo opcache.enable=1
    echo opcache.enable_cli=1

    echo opcache.memory_consumption=128
    echo opcache.interned_strings_buffer=8
    echo opcache.max_accelerated_files=10000
    echo opcache.max_wasted_percentage=5

    echo opcache.revalidate_freq=2
    echo opcache.validate_timestamps=1
    echo opcache.optimization_level=0x7FFFBFFF

    echo opcache.save_comments=1
    echo opcache.load_comments=1
    echo opcache.fast_shutdown=1

    echo opcache.max_file_size=0
    echo opcache.consistency_checks=0
    echo opcache.force_restart_timeout=180
    echo opcache.error_log=/tmp/logs/opcache.log
    echo opcache.log_verbosity_level=1

    echo opcache.file_cache=/tmp/opcache
    echo opcache.file_cache_only=0
    echo opcache.file_cache_consistency_checks=1

    echo opcache.jit_buffer_size=100M
    echo opcache.jit=1255
  } >> /etc/php/php.ini
fi