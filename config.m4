PHP_ARG_ENABLE(ddtrace, whether to enable Datadog tracing support,
  [  --enable-ddtrace   Enable Datadog tracing support])

PHP_ARG_WITH(ddtrace-sanitize, whether to enable AddressSanitizer for ddtrace,
  [  --with-ddtrace-sanitize Build Datadog tracing with AddressSanitizer support], no, no)

if test "$PHP_DDTRACE" != "no"; then
  m4_include([m4/polyfill.m4])
  m4_include([m4/ax_execinfo.m4])

  AX_EXECINFO

  AS_IF([test x"$ac_cv_header_execinfo_h" = xyes],
    dnl This duplicates some of AX_EXECINFO's work, but AX_EXECINFO puts the
    dnl library into LIBS, which we don't use anywhere else and am worried that
    dnl it may contain things we are not expecting aside from execinfo
    PHP_CHECK_LIBRARY(execinfo, backtrace,
      [PHP_ADD_LIBRARY(execinfo, , EXTRA_LDFLAGS)])
  )

  if test "$PHP_DDTRACE_SANITIZE" != "no"; then
    PHP_ADD_LIBRARY(asan, , EXTRA_LDFLAGS)
    EXTRA_CFLAGS="-fsanitize=address -fno-omit-frame-pointer"
    PHP_SUBST(EXTRA_CFLAGS)
  fi

  dnl ddtrace.c comes first, then everything else alphabetically
  DD_TRACE_PHP_SOURCES="src/ext/ddtrace.c \
    src/dogstatsd/client.c \
    src/ext/arrays.c \
    src/ext/circuit_breaker.c \
    src/ext/comms_php.c \
    src/ext/compat_string.c \
    src/ext/coms.c \
    src/ext/configuration.c \
    src/ext/configuration_php_iface.c \
    src/ext/ddtrace_string.c \
    src/ext/dispatch.c \
    src/ext/dispatch_setup.c \
    src/ext/dogstatsd_client.c \
    src/ext/engine_hooks.c \
    src/ext/env_config.c \
    src/ext/logging.c \
    src/ext/memory_limit.c \
    src/ext/mpack/mpack.c \
    src/ext/random.c \
    src/ext/request_hooks.c \
    src/ext/serializer.c \
    src/ext/signals.c \
    src/ext/span.c \
    src/ext/third-party/mt19937-64.c \
  "

  PHP_VERSION=$($PHP_CONFIG --vernum)

  if test $PHP_VERSION -lt 50500; then
    DD_TRACE_PHP_VERSION_SPECIFIC_SOURCES="\
      src/ext/php5_4/auto_flush.c \
      src/ext/php5_4/blacklist.c \
      src/ext/php5_4/dispatch.c \
      src/ext/php5_4/engine_hooks.c \
      src/ext/php5_4/handlers_curl.c \
    "
  elif test $PHP_VERSION -lt 70000; then
    DD_TRACE_PHP_VERSION_SPECIFIC_SOURCES="\
      src/ext/php5/auto_flush.c \
      src/ext/php5/blacklist.c \
      src/ext/php5/dispatch.c \
      src/ext/php5/engine_hooks.c \
      src/ext/php5/handlers_curl.c \
    "
  elif test $PHP_VERSION -lt 80000; then
    DD_TRACE_PHP_VERSION_SPECIFIC_SOURCES="\
      src/ext/php7/auto_flush.c \
      src/ext/php7/blacklist.c \
      src/ext/php7/dispatch.c \
      src/ext/php7/engine_api.c \
      src/ext/php7/engine_hooks.c \
      src/ext/php7/handlers_curl.c \
    "
  else
    DD_TRACE_PHP_VERSION_SPECIFIC_SOURCES=""
  fi

  PHP_NEW_EXTENSION(ddtrace, $DD_TRACE_PHP_SOURCES $DD_TRACE_PHP_VERSION_SPECIFIC_SOURCES, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -Wall -std=gnu11)
  PHP_ADD_BUILD_DIR($ext_builddir/src/ext, 1)

  PHP_CHECK_LIBRARY(rt, shm_open,
    [PHP_ADD_LIBRARY(rt, , EXTRA_LDFLAGS)])

  PHP_CHECK_LIBRARY(curl, curl_easy_setopt,
    [PHP_ADD_LIBRARY(curl, , EXTRA_LDFLAGS)],
    [AC_MSG_ERROR([cannot find or include curl])])

  AC_CHECK_HEADER(time.h, [], [AC_MSG_ERROR([Cannot find or include time.h])])
  PHP_SUBST(EXTRA_LDFLAGS)

  PHP_ADD_INCLUDE([$ext_srcdir])
  PHP_ADD_INCLUDE([$ext_srcdir/src/ext])

  PHP_ADD_INCLUDE([$ext_srcdir/src/ext/mpack])
  PHP_ADD_BUILD_DIR([$ext_builddir/src/ext/mpack])

  PHP_ADD_INCLUDE([$ext_srcdir/src/dogstatsd])
  PHP_ADD_BUILD_DIR([$ext_builddir/src/dogstatsd])

  if test $PHP_VERSION -lt 50500; then
    PHP_ADD_BUILD_DIR([$ext_builddir/src/ext/php5_4])
  elif test $PHP_VERSION -lt 70000; then
    PHP_ADD_BUILD_DIR([$ext_builddir/src/ext/php5])
  elif test $PHP_VERSION -lt 80000; then
    PHP_ADD_BUILD_DIR([$ext_builddir/src/ext/php7])
  fi
  PHP_ADD_BUILD_DIR([$ext_builddir/src/ext/third-party])
fi
