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
    src/dogstatsd/client.c \
  "

  DD_TRACE_COMPONENT_SOURCES="\
    components/container_id/container_id.c \
    components/sapi/sapi.c \
    components/string_view/string_view.c \
    components/uuid/uuid.c \
  "

  if test -z ${PHP_VERSION_ID+x}; then
    PHP_VERSION_ID=$("$PHP_CONFIG" --vernum)
  fi

  if test $PHP_VERSION_ID -lt 70000; then
    dnl PHP 5
    echo "PHP 5 is not supported on this branch. Use the PHP-5 branch to build PHP 5."
    exit 1
  elif test $PHP_VERSION_ID -lt 80000; then
    dnl PHP 7.x

    EXTRA_PHP_SOURCES="ext/handlers_curl_php7.c"

    EXTRA_ZAI_SOURCES="\
      zend_abstract_interface/interceptor/php7/interceptor.c \
      zend_abstract_interface/interceptor/php7/resolver.c \
      zend_abstract_interface/sandbox/php7/sandbox.c \
    "
  elif test $PHP_VERSION_ID -lt 90000; then
    dnl PHP 8.x
    EXTRA_PHP_SOURCES="ext/handlers_curl.c"
    ZAI_RESOLVER_SUFFIX=""

    if test $PHP_VERSION_ID -lt 80200; then
      ZAI_RESOLVER_SUFFIX="_pre-8_2"
      EXTRA_PHP_SOURCES="$EXTRA_PHP_SOURCES \
        ext/weakrefs.c"
    fi

    if test $PHP_VERSION_ID -ge 80100; then
      EXTRA_PHP_SOURCES="$EXTRA_PHP_SOURCES \
        ext/handlers_fiber.c"
    fi

    EXTRA_ZAI_SOURCES="\
      zend_abstract_interface/interceptor/php8/interceptor.c \
      zend_abstract_interface/interceptor/php8/resolver$ZAI_RESOLVER_SUFFIX.c \
      zend_abstract_interface/sandbox/php8/sandbox.c \
    "
  fi

  dnl ddtrace.c comes first, then everything else alphabetically
  DD_TRACE_PHP_SOURCES="$EXTRA_PHP_SOURCES \
    ext/ddtrace.c \
    ext/arrays.c \
    ext/auto_flush.c \
    ext/circuit_breaker.c \
    ext/comms_php.c \
    ext/compat_string.c \
    ext/coms.c \
    ext/configuration.c \
    ext/ddshared.c \
    ext/dogstatsd_client.c \
    ext/engine_api.c \
    ext/engine_hooks.c \
    ext/excluded_modules.c \
    ext/handlers_api.c \
    ext/handlers_exception.c \
    ext/handlers_internal.c \
    ext/handlers_pcntl.c \
    ext/integrations/integrations.c \
    ext/ip_extraction.c \
    ext/logging.c \
    ext/memory_limit.c \
    ext/limiter/limiter.c \
    ext/priority_sampling/priority_sampling.c \
    ext/profiling.c \
    ext/random.c \
    ext/request_hooks.c \
    ext/serializer.c \
    ext/signals.c \
    ext/span.c \
    ext/startup_logging.c \
    ext/tracer_tag_propagation/tracer_tag_propagation.c \
    ext/hook/uhook.c \
    ext/hook/uhook_legacy.c \
    \
  "

  ZAI_SOURCES="$EXTRA_ZAI_SOURCES \
    zend_abstract_interface/config/config.c \
    zend_abstract_interface/config/config_decode.c \
    zend_abstract_interface/config/config_ini.c \
    zend_abstract_interface/config/config_runtime.c \
    zend_abstract_interface/env/env.c \
    zend_abstract_interface/exceptions/exceptions.c \
    zend_abstract_interface/headers/headers.c \
    zend_abstract_interface/hook/hook.c \
    zend_abstract_interface/json/json.c \
    zend_abstract_interface/symbols/lookup.c \
    zend_abstract_interface/symbols/call.c \
    zend_abstract_interface/uri_normalization/uri_normalization.c \
  "

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
  PHP_ADD_BUILD_DIR([$ext_builddir/components/uuid])

  PHP_ADD_INCLUDE([$ext_srcdir/zend_abstract_interface])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/symbols])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/config])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/env])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/exceptions])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/headers])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/hook])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/interceptor])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/interceptor/php7])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/interceptor/php8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/json])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox/php7])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox/php8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/uri_normalization])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/zai_assert])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/zai_string])

  PHP_ADD_BUILD_DIR([$ext_builddir/ext/hook])

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

  PHP_ADD_BUILD_DIR([$ext_builddir/ext])
  PHP_ADD_BUILD_DIR([$ext_builddir/ext/limiter])
  PHP_ADD_BUILD_DIR([$ext_builddir/ext/priority_sampling])
  PHP_ADD_BUILD_DIR([$ext_builddir/ext/tracer_tag_propagation])
  PHP_ADD_BUILD_DIR([$ext_builddir/ext/integrations])
  PHP_ADD_INCLUDE([$ext_builddir/ext/integrations])
fi
