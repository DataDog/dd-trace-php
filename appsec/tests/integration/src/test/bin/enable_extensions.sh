#!/bin/bash -e

{
  echo error_log=/tmp/logs/php_error.log
  echo display_errors=0
  echo error_reporting=-1
  echo max_execution_time=1800
} >> /etc/php/php.ini

HELPER_PATH=/appsec/libddappsec-helper.so
if [[ -n $USE_HELPER_RUST ]]; then
  echo "Using Rust helper" >&2
  HELPER_PATH=/helper-rust/libddappsec-helper.so
elif [[ -f /helper-rust/libddappsec-helper.so ]]; then
  # Copy Rust helper for the redirection mechanism
  # (DD_APPSEC_HELPER_RUST_REDIRECTION defaults to true on PHP >= 8.5)
  ln -sf /helper-rust/libddappsec-helper.so \
    "$(dirname "$HELPER_PATH")/libddappsec-helper-rust.so"
fi

if [[ -n $USE_SSI ]]; then
  echo "Enabling SSI loader" >&2
  # SSI initialization is slower per worker (loading libddtrace_php.so).
  # Raise MaxRequestWorkers so health-check retries don't exhaust the pool.
  if [[ -f /etc/apache2/mods-enabled/mpm_prefork.conf ]]; then
    sed -i 's/MaxRequestWorkers[[:space:]]\+[0-9]\+/MaxRequestWorkers 16/' \
        /etc/apache2/mods-enabled/mpm_prefork.conf
  fi
  PHP_API=$(php -r 'echo PHP_EXTENSION_DIR;' | sed 's/.*-//')
  EXT_SUFFIX=$(php -r 'echo ZEND_DEBUG_BUILD ? "-debug" : "";')
  PKG=/tmp/dd-package
  mkdir -p "$PKG/loader" "$PKG/trace/ext/$PHP_API" "$PKG/appsec/ext/$PHP_API" "$PKG/appsec/lib"
  ln -s /tracer-ssi/libddtrace_php.so "$PKG/loader/libddtrace_php.so"
  ln -s /tracer-ssi/ddtrace.so "$PKG/trace/ext/$PHP_API/ddtrace${EXT_SUFFIX}.so"
  ln -s /appsec/ddappsec.so "$PKG/appsec/ext/$PHP_API/ddappsec${EXT_SUFFIX}.so"
  ln -s /appsec/libddappsec-helper.so "$PKG/appsec/lib/libddappsec-helper.so"
  ln -s /project/src "$PKG/trace/src"
  HELPER_PATH=/tmp/dd-package/appsec/lib/libddappsec-helper.so
  {
    echo "zend_extension=/loader-ssi/dd_library_loader.so"
    echo datadog.trace.generate_root_span=true
    echo datadog.trace.log_level=debug
  } >> /etc/php/php.ini
  ENABLE_APPSEC=true
elif [[ -f /project/tmp/build_extension/modules/ddtrace.so ]]; then
  echo "Enabling ddtrace" >&2
  {
    echo extension=/project/tmp/build_extension/modules/ddtrace.so
    echo datadog.trace.sources_path=/project/src
    echo datadog.trace.generate_root_span=true
    echo datadog.trace.log_level=debug
  } >> /etc/php/php.ini
  if [[ -f /appsec/ddappsec.so && -d /project ]]; then
    echo extension=/appsec/ddappsec.so >> /etc/php/php.ini
    ENABLE_APPSEC=true
  fi
fi

if [[ $ENABLE_APPSEC == true ]]; then
  echo "Enabling ddappsec" >&2
  {
    echo datadog.appsec.enabled=true
    echo datadog.appsec.helper_path=$HELPER_PATH
    echo datadog.appsec.helper_log_file=/tmp/logs/helper.log
    echo datadog.appsec.helper_log_level=debug
    echo datadog.appsec.rules=/etc/recommended.json
    echo datadog.appsec.log_file=/tmp/logs/appsec.log
    echo datadog.appsec.log_level=debug
    echo datadog.appsec.rasp_enabled=1
    echo datadog.appsec.testing_invalid_command=1
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
