PHP_ARG_ENABLE(ddtrace, whether to enable Datadog tracing support,
  [  --enable-ddtrace   Enable Datadog tracing support])

PHP_ARG_WITH(ddtrace-sanitize, whether to enable AddressSanitizer for ddtrace,
  [  --with-ddtrace-sanitize Build Datadog tracing with AddressSanitizer support], no, no)

if test "$PHP_DDTRACE" != "no"; then
  AC_CHECK_SIZEOF([long])
  AC_MSG_CHECKING([for 64-bit platform])
  AS_IF([test "$ac_cv_sizeof_long" -eq 4],[
    AC_MSG_RESULT([no])
    AC_MSG_ERROR([ddtrace only supports 64-bit platforms])
  ],[
    AC_MSG_RESULT([yes])
  ])

  define(DDTRACE_BASEDIR, esyscmd(printf %s "$(dirname "__file__")"))
  m4_include(DDTRACE_BASEDIR/m4/polyfill.m4)
  m4_include(DDTRACE_BASEDIR/m4/ax_execinfo.m4)

  AX_EXECINFO

  AS_IF([test x"$ac_cv_header_execinfo_h" = xyes],
    dnl This duplicates some of AX_EXECINFO's work, but AX_EXECINFO puts the
    dnl library into LIBS, which we don't use anywhere else and am worried that
    dnl it may contain things we are not expecting aside from execinfo
    PHP_CHECK_LIBRARY(execinfo, backtrace,
      [PHP_ADD_LIBRARY(execinfo, , EXTRA_LDFLAGS)])
  )

  AC_CHECK_HEADERS([linux/securebits.h])
  AC_CHECK_HEADERS([linux/capability.h])

  if test "$PHP_DDTRACE_SANITIZE" != "no"; then
    EXTRA_LDFLAGS="-fsanitize=address"
    EXTRA_CFLAGS="-fsanitize=address -fno-omit-frame-pointer"
  fi

  DD_TRACE_VENDOR_SOURCES="\
    ext/vendor/mpack/mpack.c \
    ext/vendor/mt19937/mt19937-64.c \
    ext/vendor/zai/hook/uhook.c \
    src/dogstatsd/client.c \
  "

  DD_TRACE_COMPONENT_SOURCES="\
    components/container_id/container_id.c \
    components/sapi/sapi.c \
    components/string_view/string_view.c \
  "

  if test -z ${PHP_VERSION_ID+x}; then
    PHP_VERSION_ID=$("$PHP_CONFIG" --vernum)
  fi

  if test $PHP_VERSION_ID -lt 50500; then
    dnl PHP 5.4
    dnl ddtrace.c comes first, then everything else alphabetically
    DD_TRACE_PHP_SOURCES="ext/php5/ddtrace.c \
      ext/php5/arrays.c \
      ext/php5/auto_flush.c \
      ext/php5/circuit_breaker.c \
      ext/php5/comms_php.c \
      ext/php5/compat_string.c \
      ext/php5/coms.c \
      ext/php5/configuration.c \
      ext/php5/ddshared.c \
      ext/php5/dispatch.c \
      ext/php5/dogstatsd_client.c \
      ext/php5/engine_api.c \
      ext/php5/engine_hooks.c \
      ext/php5/excluded_modules.c \
      ext/php5/handlers_curl.c \
      ext/php5/handlers_exception.c \
      ext/php5/handlers_internal.c \
      ext/php5/handlers_pcntl.c \
      ext/php5/integrations/integrations.c \
      ext/php5/logging.c \
      ext/php5/memory_limit.c \
      ext/php5/php5_4/dispatch.c \
      ext/php5/php5_4/engine_hooks.c \
      ext/php5/priority_sampling/priority_sampling.c \
      ext/php5/random.c \
      ext/php5/request_hooks.c \
      ext/php5/serializer.c \
      ext/php5/signals.c \
      ext/php5/span.c \
      ext/php5/startup_logging.c \
      ext/php5/tracer_tag_propagation/tracer_tag_propagation.c \
    "

    ZAI_SOURCES="\
      zend_abstract_interface/config/config.c \
      zend_abstract_interface/config/config_decode.c \
      zend_abstract_interface/config/php5/config_ini.c \
      zend_abstract_interface/config/php5/config_runtime.c \
      zend_abstract_interface/env/env.c \
      zend_abstract_interface/exceptions/php5/exceptions.c \
      zend_abstract_interface/headers/php5/headers.c \
      zend_abstract_interface/hook/hook.c \
      zend_abstract_interface/json/json.c \
      zend_abstract_interface/symbols/lookup.c \
      zend_abstract_interface/symbols/call.c \
      zend_abstract_interface/sandbox/php5/sandbox.c \
      zend_abstract_interface/uri_normalization/php5/uri_normalization.c \
    "
  elif test $PHP_VERSION_ID -lt 70000; then
    dnl PHP 5.5 + PHP 5.6
    dnl ddtrace.c comes first, then everything else alphabetically
    DD_TRACE_PHP_SOURCES="ext/php5/ddtrace.c \
      ext/php5/arrays.c \
      ext/php5/auto_flush.c \
      ext/php5/circuit_breaker.c \
      ext/php5/comms_php.c \
      ext/php5/compat_string.c \
      ext/php5/coms.c \
      ext/php5/configuration.c \
      ext/php5/ddshared.c \
      ext/php5/dispatch.c \
      ext/php5/dogstatsd_client.c \
      ext/php5/engine_api.c \
      ext/php5/engine_hooks.c \
      ext/php5/excluded_modules.c \
      ext/php5/handlers_curl.c \
      ext/php5/handlers_exception.c \
      ext/php5/handlers_internal.c \
      ext/php5/handlers_pcntl.c \
      ext/php5/integrations/integrations.c \
      ext/php5/logging.c \
      ext/php5/memory_limit.c \
      ext/php5/php5/dispatch.c \
      ext/php5/php5/engine_hooks.c \
      ext/php5/priority_sampling/priority_sampling.c \
      ext/php5/random.c \
      ext/php5/request_hooks.c \
      ext/php5/serializer.c \
      ext/php5/signals.c \
      ext/php5/span.c \
      ext/php5/startup_logging.c \
      ext/php5/tracer_tag_propagation/tracer_tag_propagation.c \
    "

    ZAI_SOURCES="\
      zend_abstract_interface/config/config.c \
      zend_abstract_interface/config/config_decode.c \
      zend_abstract_interface/config/php5/config_ini.c \
      zend_abstract_interface/config/php5/config_runtime.c \
      zend_abstract_interface/env/env.c \
      zend_abstract_interface/exceptions/php5/exceptions.c \
      zend_abstract_interface/headers/php5/headers.c \
      zend_abstract_interface/hook/hook.c \
      zend_abstract_interface/json/json.c \
      zend_abstract_interface/symbols/lookup.c \
      zend_abstract_interface/symbols/call.c \
      zend_abstract_interface/sandbox/php5/sandbox.c \
      zend_abstract_interface/uri_normalization/php5/uri_normalization.c \
    "
  elif test $PHP_VERSION_ID -lt 80000; then
    dnl PHP 7.x
    dnl ddtrace.c comes first, then everything else alphabetically
    DD_TRACE_PHP_SOURCES="ext/php7/ddtrace.c \
      ext/php7/arrays.c \
      ext/php7/auto_flush.c \
      ext/php7/circuit_breaker.c \
      ext/php7/comms_php.c \
      ext/php7/compat_string.c \
      ext/php7/coms.c \
      ext/php7/configuration.c \
      ext/php7/ddshared.c \
      ext/php7/dispatch.c \
      ext/php7/dogstatsd_client.c \
      ext/php7/engine_api.c \
      ext/php7/engine_hooks.c \
      ext/php7/excluded_modules.c \
      ext/php7/handlers_curl.c \
      ext/php7/handlers_exception.c \
      ext/php7/handlers_internal.c \
      ext/php7/handlers_memcached.c \
      ext/php7/handlers_mongodb.c \
      ext/php7/handlers_mysqli.c \
      ext/php7/handlers_pcntl.c \
      ext/php7/handlers_pdo.c \
      ext/php7/handlers_phpredis.c \
      ext/php7/integrations/integrations.c \
      ext/php7/logging.c \
      ext/php7/memory_limit.c \
      ext/php7/php7/dispatch.c \
      ext/php7/php7/engine_hooks.c \
      ext/php7/priority_sampling/priority_sampling.c \
      ext/php7/random.c \
      ext/php7/request_hooks.c \
      ext/php7/serializer.c \
      ext/php7/signals.c \
      ext/php7/span.c \
      ext/php7/startup_logging.c \
      ext/php7/tracer_tag_propagation/tracer_tag_propagation.c \
    "

    ZAI_SOURCES="\
      zend_abstract_interface/config/config.c \
      zend_abstract_interface/config/config_decode.c \
      zend_abstract_interface/config/php7-8/config_ini.c \
      zend_abstract_interface/config/php7-8/config_runtime.c \
      zend_abstract_interface/env/env.c \
      zend_abstract_interface/exceptions/php7-8/exceptions.c \
      zend_abstract_interface/headers/php7-8/headers.c \
      zend_abstract_interface/hook/hook.c \
      zend_abstract_interface/json/json.c \
      zend_abstract_interface/symbols/lookup.c \
      zend_abstract_interface/symbols/call.c \
      zend_abstract_interface/sandbox/php7/sandbox.c \
      zend_abstract_interface/uri_normalization/php7-8/uri_normalization.c \
    "
  elif test $PHP_VERSION_ID -lt 90000; then
    dnl PHP 8.x
    dnl ddtrace.c comes first, then everything else alphabetically
    DD_TRACE_PHP_SOURCES="ext/php8/ddtrace.c \
      ext/php8/arrays.c \
      ext/php8/auto_flush.c \
      ext/php8/circuit_breaker.c \
      ext/php8/comms_php.c \
      ext/php8/compat_string.c \
      ext/php8/coms.c \
      ext/php8/configuration.c \
      ext/php8/ddshared.c \
      ext/php8/dispatch.c \
      ext/php8/dogstatsd_client.c \
      ext/php8/engine_api.c \
      ext/php8/engine_hooks.c \
      ext/php8/excluded_modules.c \
      ext/php8/handlers_curl.c \
      ext/php8/handlers_exception.c \
      ext/php8/handlers_internal.c \
      ext/php8/handlers_memcached.c \
      ext/php8/handlers_mongodb.c \
      ext/php8/handlers_mysqli.c \
      ext/php8/handlers_pcntl.c \
      ext/php8/handlers_pdo.c \
      ext/php8/handlers_phpredis.c \
      ext/php8/integrations/integrations.c \
      ext/php8/logging.c \
      ext/php8/memory_limit.c \
      ext/php8/php8/dispatch.c \
      ext/php8/php8/engine_hooks.c \
      ext/php8/priority_sampling/priority_sampling.c \
      ext/php8/random.c \
      ext/php8/request_hooks.c \
      ext/php8/serializer.c \
      ext/php8/signals.c \
      ext/php8/span.c \
      ext/php8/startup_logging.c \
      ext/php8/tracer_tag_propagation/tracer_tag_propagation.c \
    "
    if test $PHP_VERSION_ID -lt 80200; then
      DD_TRACE_PHP_SOURCES="$DD_TRACE_PHP_SOURCES \
        ext/php8/weakrefs.c \
      "
    fi

    ZAI_SOURCES="\
      zend_abstract_interface/config/config.c \
      zend_abstract_interface/config/config_decode.c \
      zend_abstract_interface/config/php7-8/config_ini.c \
      zend_abstract_interface/config/php7-8/config_runtime.c \
      zend_abstract_interface/env/env.c \
      zend_abstract_interface/exceptions/php7-8/exceptions.c \
      zend_abstract_interface/headers/php7-8/headers.c \
      zend_abstract_interface/hook/hook.c \
      zend_abstract_interface/json/json.c \
      zend_abstract_interface/symbols/lookup.c \
      zend_abstract_interface/symbols/call.c \
      zend_abstract_interface/sandbox/php8/sandbox.c \
      zend_abstract_interface/uri_normalization/php7-8/uri_normalization.c \
    "
  fi

  PHP_NEW_EXTENSION(ddtrace, $DD_TRACE_COMPONENT_SOURCES $ZAI_SOURCES $DD_TRACE_VENDOR_SOURCES $DD_TRACE_PHP_SOURCES, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -Wall -std=gnu11)
  PHP_ADD_BUILD_DIR($ext_builddir/ext, 1)

  PHP_CHECK_LIBRARY(rt, shm_open,
    [PHP_ADD_LIBRARY(rt, , EXTRA_LDFLAGS)])

  PHP_CHECK_LIBRARY(curl, curl_easy_setopt,
    [PHP_ADD_LIBRARY(curl, , EXTRA_LDFLAGS)],
    [AC_MSG_ERROR([cannot find or include curl])])

  AC_CHECK_HEADER(time.h, [], [AC_MSG_ERROR([Cannot find or include time.h])])

  if test "$ext_shared" = "yes"; then
    dnl Only export symbols defined in ddtrace.sym, which should all be marked as
    dnl DDTRACE_PUBLIC in their source files as well.
    EXTRA_CFLAGS="$EXTRA_CFLAGS -fvisibility=hidden"
    EXTRA_LDFLAGS="$EXTRA_LDFLAGS -export-symbols $ext_srcdir/ddtrace.sym"

    PHP_SUBST(EXTRA_CFLAGS)
    PHP_SUBST(EXTRA_LDFLAGS)
  fi

  PHP_ADD_INCLUDE([$ext_srcdir])
  PHP_ADD_INCLUDE([$ext_srcdir/ext])

  PHP_ADD_BUILD_DIR([$ext_builddir/components])
  PHP_ADD_BUILD_DIR([$ext_builddir/components/container_id])
  PHP_ADD_BUILD_DIR([$ext_builddir/components/sapi])
  PHP_ADD_BUILD_DIR([$ext_builddir/components/string_view])

  PHP_ADD_INCLUDE([$ext_srcdir/zend_abstract_interface])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/symbols])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/config])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/config/php5])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/config/php7-8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/env])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/exceptions])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/exceptions/php5])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/exceptions/php7-8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/headers])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/headers/php5])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/headers/php7-8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/hook])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/json])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox/php5])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox/php7])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox/php8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/uri_normalization])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/uri_normalization/php5])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/uri_normalization/php7-8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/zai_assert])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/zai_string])

  PHP_ADD_INCLUDE([$ext_srcdir/ext/vendor])
  PHP_ADD_BUILD_DIR([$ext_builddir/ext/vendor])

  PHP_ADD_INCLUDE([$ext_srcdir/ext/vendor/zai/hook])
  PHP_ADD_BUILD_DIR([$ext_builddir/ext/vendor/zai/hook])

  PHP_ADD_INCLUDE([$ext_srcdir/ext/vendor/mpack])
  PHP_ADD_BUILD_DIR([$ext_builddir/ext/vendor/mpack])

  PHP_ADD_INCLUDE([$ext_srcdir/ext/vendor/mt19937])
  PHP_ADD_BUILD_DIR([$ext_builddir/ext/vendor/mt19937])

  dnl TODO Move this to ext/
  PHP_ADD_INCLUDE([$ext_srcdir/src/dogstatsd])
  PHP_ADD_BUILD_DIR([$ext_builddir/src/dogstatsd])

  if test $PHP_VERSION_ID -lt 50500; then
    dnl PHP 5.4
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5/php5_4])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5/priority_sampling])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5/tracer_tag_propagation])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5/integrations])
    PHP_ADD_INCLUDE([$ext_builddir/ext/php5/integrations])
  elif test $PHP_VERSION_ID -lt 70000; then
    dnl PHP 5.5 + PHP 5.6
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5])
    dnl Temp dir until we merge dispatch.c and engine_hooks.c
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5/php5])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5/priority_sampling])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5/tracer_tag_propagation])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php5/integrations])
    PHP_ADD_INCLUDE([$ext_builddir/ext/php5/integrations])
  elif test $PHP_VERSION_ID -lt 80000; then
    dnl PHP 7.0
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php7])
    dnl Temp dir until we merge dispatch.c and engine_hooks.c
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php7/php7])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php7/priority_sampling])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php7/tracer_tag_propagation])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php7/integrations])
    PHP_ADD_INCLUDE([$ext_builddir/ext/php7/integrations])
  elif test $PHP_VERSION_ID -lt 90000; then
    dnl PHP 8.0
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php8])
    dnl Temp dir until we merge dispatch.c and engine_hooks.c
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php8/php8])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php8/priority_sampling])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php8/tracer_tag_propagation])
    PHP_ADD_BUILD_DIR([$ext_builddir/ext/php8/integrations])
    PHP_ADD_INCLUDE([$ext_builddir/ext/php8/integrations])
  fi
fi
