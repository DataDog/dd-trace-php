PHP_ARG_ENABLE(ddtrace, whether to enable Datadog tracing support,[  --enable-ddtrace   Enable Datadog training support])

PHP_ARG_WITH(ddtrace-sanitize, whether to enable AddressSanitizer for ddtrace,[  --with-ddtrace-sanitize Build Datadog tracing with AddressSanitizer support], no, no)

if test "$PHP_DDTRACE" != "no"; then
  if test "$PHP_DDTRACE_SANITIZE" != "no"; then
    EXTRA_LDFLAGS="-lasan"
	  EXTRA_CFLAGS="-fsanitize=address -fno-omit-frame-pointer"
	  PHP_SUBST(EXTRA_LDFLAGS)
    PHP_SUBST(EXTRA_CFLAGS)
  fi

  PHP_NEW_EXTENSION(ddtrace, src/ext/ddtrace.c src/ext/memory_limit.c src/ext/configuration.c src/ext/configuration_php_iface.c src/ext/circuit_breaker.c src/ext/dispatch_setup.c src/ext/dispatch.c src/ext/third-party/mt19937-64.c src/ext/random.c src/ext/coms.c src/ext/coms_curl.c src/ext/coms_debug.c src/ext/request_hooks.c src/ext/compat_zend_string.c src/ext/dispatch_compat_php5.c src/ext/dispatch_compat_php7.c src/ext/backtrace.c src/ext/logging.c src/ext/env_config.c src/ext/serializer.c src/ext/span.c src/ext/trace.c src/ext/mpack/mpack.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -Wall -std=gnu11)
  PHP_ADD_BUILD_DIR($ext_builddir/src/ext, 1)

  PHP_CHECK_LIBRARY(rt, shm_open, [EXTRA_LDFLAGS="$EXTRA_LDFLAGS -lrt"])
  PHP_CHECK_LIBRARY(curl, curl_easy_setopt, [EXTRA_LDFLAGS="$EXTRA_LDFLAGS -lcurl"])
  PHP_SUBST(EXTRA_LDFLAGS)

  PHP_ADD_INCLUDE($ext_builddir)
  PHP_ADD_INCLUDE($ext_builddir/src/ext)
  PHP_ADD_INCLUDE($ext_builddir/src/ext/mpack)
fi
