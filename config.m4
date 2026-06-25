PHP_ARG_ENABLE(ddtrace, whether to enable Datadog support,
  [  --enable-ddtrace   Enable Datadog tracing support])

PHP_ARG_ENABLE(ddtrace-tracer, whether to enable Datadog tracing support,
  [  --disable-ddtrace-tracer Disable Datadog tracing support], yes, no)

PHP_ARG_ENABLE(ddtrace-sanitize, whether to enable AddressSanitizer for ddtrace,
  [  --enable-ddtrace-sanitize Build Datadog tracing with AddressSanitizer support], no, no)

PHP_ARG_WITH(ddtrace-rust-library, the rust library is located; i.e. to compile without cargo,
  [  --with-ddtrace-rust-library Location to rust library for linking against], -, will be compiled)

PHP_ARG_WITH(ddtrace-sidecar-mockgen, binary to weaken PHP symbols in object files,
  [  --with-ddtrace-sidecar-library Location to cargo binary produced by components-rs/php_sidecar_mockgen], -, will be compiled)

PHP_ARG_WITH(ddtrace-cargo, where cargo is located for rust code compilation,
  [  --with-ddtrace-cargo Location to cargo binary for rust compilation], cargo, not found)

PHP_ARG_ENABLE(ddtrace-rust-debug, whether to compile rust in debug mode,
  [  --enable-ddtrace-rust-debug Build rust code in debug mode (significantly slower)], [[$( (if test x"$ext_shared" = x"yes"; then $GREP -q "ZEND_DEBUG 1" $("$PHP_CONFIG" --include-dir)/main/php_config.h; else test x"$PHP_DEBUG" = x"yes"; fi) && echo yes || echo no)]], [no])

PHP_ARG_ENABLE(ddtrace-rust-library-split, whether to not link the rust library against the extension at compile time,
  [  --enable-ddtrace-rust-library-split Do not build nor link against the rust code], no, no)

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
  m4_include(DDTRACE_BASEDIR/m4/threads.m4)

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
  AC_CHECK_HEADERS([valgrind/valgrind.h])

  dnl
  m4_ifndef([_LT_CHECK_OBJDIR], AC_LIBTOOL_OBJDIR, _LT_CHECK_OBJDIR)

  if test "$PHP_DDTRACE_RUST_LIBRARY" = "-" || test "$PHP_DDTRACE_SIDECAR_MOCKGEN" = "-"; then
    if test -n "$PHP_DDTRACE_CARGO" && test "$PHP_DDTRACE_CARGO" != "cargo"; then
      if test -x "$PHP_DDTRACE_CARGO"; then
        DDTRACE_CARGO="$PHP_DDTRACE_CARGO"
      else
        AC_MSG_ERROR([$PHP_DDTRACE_CARGO is not an executable])
      fi
    else
      AC_CHECK_TOOL(DDTRACE_CARGO, cargo, [:])
      AS_IF([test "$DDTRACE_CARGO" = ":"], [AC_MSG_ERROR([Please install cargo before configuring, or specify it with --with-ddtrace-cargo=])])
    fi
    PHP_SUBST(DDTRACE_CARGO)
  fi

  if test "$PHP_DDTRACE_SANITIZE" != "no"; then
    dnl gcc needs -lasan, clang needs -shared-libsan
    ac_cflags=$LDFLAGS
    LDFLAGS=-shared-libsan
    AC_RUN_IFELSE([AC_LANG_SOURCE([[int main(void) { return (0); }]])],[found=yes],[found=no],[found=yes])
    LDFLAGS=$ac_ldflags

    EXTRA_LDFLAGS="-fsanitize=address $(if test $found = "yes"; then echo "-shared-libsan -lclang_rt.asan-$(uname -m)"; else echo "-lasan"; fi)"
    EXTRA_CFLAGS="-fsanitize=address -fno-omit-frame-pointer"
  fi

  CFLAGS="$CFLAGS -fms-extensions"
  EXTRA_CFLAGS="$EXTRA_CFLAGS -fms-extensions"

  rm="rm -f"
  lt_simple_link_test_code='int main(void){return(0);}'
  AC_MSG_CHECKING([whether --undefined-version is a valid linker argument])
  AC_LIBTOOL_LINKER_OPTION([whether --undefined-version is a valid linker argument],
    lt_cv_ddtrace_undefined_version,
    [-Wl,--undefined-version],
    [LDFLAGS="$LDFLAGS -Wl,--undefined-version"])

  lt_simple_compile_test_code="int some_variable = 0;"
  Xsed="$SED -e 1s/^X//"
  AC_LIBTOOL_COMPILER_OPTION([whether -Wno-microsoft-anon-tag is a valid compiler argument],
    lt_cv_ddtrace_no_microsoft_anon_tag,
    [-Wno-microsoft-anon-tag], [],
    [
      CFLAGS="$CFLAGS -Wno-microsoft-anon-tag"
      EXTRA_CFLAGS="$EXTRA_CFLAGS -Wno-microsoft-anon-tag"
    ])

  DD_TRACE_VENDOR_SOURCES="\
    tracer/vendor/mpack/mpack.c \
    tracer/vendor/mt19937/mt19937-64.c \
    src/dogstatsd/client.c \
  "

  DATADOG_COMPONENT_SOURCES="\
    components/log/log.c \
    components/sapi/sapi.c \
    components/string_view/string_view.c \
  "

  if test -z ${PHP_VERSION_ID+x}; then
    PHP_VERSION_ID=$("$PHP_CONFIG" --vernum)
  fi

  EXTRA_DATADOG_SOURCES=""

  if test $PHP_VERSION_ID -lt 70000; then
    dnl PHP 5
    echo "PHP 5 is not supported on this branch. Use the PHP-5 branch to build PHP 5."
    exit 1
  elif test $PHP_VERSION_ID -lt 80000; then
    dnl PHP 7.x

    EXTRA_TRACER_SOURCES="tracer/handlers_curl_php7.c"

    if test $PHP_VERSION_ID -lt 70300; then
      EXTRA_DATADOG_SOURCES="$EXTRA_DATADOG_SOURCES \
        ext/zend_hrtime.c"
    fi

    EXTRA_ZAI_SOURCES="\
      zend_abstract_interface/interceptor/php7/interceptor.c \
      zend_abstract_interface/interceptor/php7/resolver.c \
      zend_abstract_interface/sandbox/php7/sandbox.c \
    "
  elif test $PHP_VERSION_ID -lt 90000; then
    dnl PHP 8.x
    EXTRA_TRACER_SOURCES="\
        tracer/handlers_curl.c \
        tracer/hook/uhook_attributes.c \
        tracer/hook/uhook_otel.c \
    "
    ZAI_RESOLVER_SUFFIX=""

    if test $PHP_VERSION_ID -lt 80200; then
      ZAI_RESOLVER_SUFFIX="_pre-8_2"
      EXTRA_TRACER_SOURCES="$EXTRA_TRACER_SOURCES \
        tracer/weakrefs.c"

      EXTRA_DATADOG_SOURCES="$EXTRA_DATADOG_SOURCES \
        ext/patch_zend_call_known_function.c"
    fi

    if test $PHP_VERSION_ID -ge 80100; then
      EXTRA_TRACER_SOURCES="$EXTRA_TRACER_SOURCES \
        tracer/handlers_fiber.c"
    fi

    EXTRA_ZAI_SOURCES="\
      zend_abstract_interface/interceptor/php8/interceptor.c \
      zend_abstract_interface/interceptor/php8/resolver$ZAI_RESOLVER_SUFFIX.c \
      zend_abstract_interface/jit_utils/jit_blacklist.c \
      zend_abstract_interface/sandbox/php8/sandbox.c \
    "
  fi

  dnl datadog.c/ddtrace.c comes first, then everything else alphabetically
  DATADOG_PHP_SOURCES="$EXTRA_DATADOG_SOURCES \
    ext/datadog.c
    ext/agent_info.c \
    ext/compat_getrandom.c \
    ext/configuration.c \
    ext/crashtracking_frames.c \
    ext/endpoints.c \
    ext/excluded_modules.c \
    ext/git.c \
    ext/handlers_api.c \
    ext/handlers_pcntl.c \
    ext/handlers_signal.c \
    ext/logging.c \
    ext/otel_config.c \
    ext/phpinfo.c \
    ext/process_tags.c \
    ext/remote_config.c \
    ext/sidecar.c \
    ext/signals.c \
    ext/startup_logging.c \
    ext/string_utils.c \
    ext/target_metadata.c \
    ext/telemetry.c \
    ext/threads.c \
  "

  DATADOG_TRACER_SOURCES="$EXTRA_TRACER_SOURCES \
    tracer/ddtrace.c \
    tracer/asm_event.c \
    tracer/auto_flush.c \
    tracer/autoload_php_files.c \
    tracer/code_origins.c \
    tracer/collect_backtrace.c \
    tracer/comms_php.c \
    tracer/coms.c \
    tracer/distributed_tracing_headers.c \
    tracer/dogstatsd_client.c \
    tracer/endpoint_guessing.c \
    tracer/engine_api.c \
    tracer/engine_hooks.c \
    tracer/exception_serialize.c \
    tracer/ffe.c \
    tracer/functions.c \
    tracer/git_metadata.c \
    tracer/handlers_exception.c \
    tracer/handlers_httpstreams.c \
    tracer/handlers_internal.c \
    tracer/handlers_kafka.c \
    tracer/inferred_proxy_headers.c \
    tracer/integrations/exec_integration.c \
    tracer/integrations/integrations.c \
    tracer/ip_extraction.c \
    tracer/live_debugger.c \
    tracer/limiter/limiter.c \
    tracer/memory_limit.c \
    tracer/otel_context.c \
    tracer/tracer_otel_config.c \
    tracer/priority_sampling/priority_sampling.c \
    tracer/profiling.c \
    tracer/random.c \
    tracer/rule_matching.c \
    tracer/serializer.c \
    tracer/standalone_limiter.c \
    tracer/span.c \
    tracer/span_stats.c \
    tracer/trace_filter.c \
    tracer/trace_source.c \
    tracer/tracer_startup_logging.c \
    tracer/tracer_tag_propagation/tracer_tag_propagation.c \
    tracer/tracer_telemetry.c \
    tracer/user_request.c \
    tracer/weak_resources.c \
    tracer/hook/uhook.c \
    tracer/hook/uhook_legacy.c \
  "

  ZAI_SOURCES="$EXTRA_ZAI_SOURCES \
    zend_abstract_interface/config/config.c \
    zend_abstract_interface/config/config_decode.c \
    zend_abstract_interface/config/config_ini.c \
    zend_abstract_interface/config/config_stable_file.c \
    zend_abstract_interface/config/config_runtime.c \
    zend_abstract_interface/env/env.c \
    zend_abstract_interface/exceptions/exceptions.c \
    zend_abstract_interface/headers/headers.c \
    zend_abstract_interface/hook/hook.c \
    zend_abstract_interface/json/json.c \
    zend_abstract_interface/sandbox/call.c \
    zend_abstract_interface/uri_normalization/uri_normalization.c \
    zend_abstract_interface/zai_string/string.c \
  "

  DATADOG_EXTENSION_FLAGS="-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -Wall -std=gnu11"

  ALL_DATADOG_SOURCES=" \
    $DATADOG_PHP_SOURCES \
    $ZAI_SOURCES \
    $DATADOG_COMPONENT_SOURCES \
  "

  if test "$PHP_DDTRACE_TRACER" != "no"; then
    ALL_DATADOG_SOURCES="$ALL_DATADOG_SOURCES \
      $DD_TRACE_VENDOR_SOURCES \
      $DATADOG_TRACER_SOURCES \
      $DD_TRACE_PHP_SOURCES
    "

    DATADOG_EXTENSION_FLAGS="$DATADOG_EXTENSION_FLAGS -DDDTRACE"
  fi

  PHP_NEW_EXTENSION(ddtrace, $ALL_DATADOG_SOURCES, $ext_shared,, $DATADOG_EXTENSION_FLAGS)
  PHP_ADD_BUILD_DIR($ext_builddir/ext, 1)

  dnl sidecar requires us to be linked against libm for pow and powf and librt for shm_* functions
  AC_CHECK_LIBM
  EXTRA_LDFLAGS="$EXTRA_LDFLAGS $LIBM"
  dnl as well as explicitly for pthread_atfork
  PTHREADS_CHECK
  EXTRA_CFLAGS="$EXTRA_CFLAGS $ac_cv_pthreads_cflags"
  EXTRA_LIBS="$EXTRA_LIBS -l$ac_cv_pthreads_lib"
  PHP_CHECK_LIBRARY(rt, shm_open,
    [EXTRA_LDFLAGS="$EXTRA_LDFLAGS -lrt"; DDTRACE_SHARED_LIBADD="${DDTRACE_SHARED_LIBADD:-} -lrt"])

  dnl rust imports these, so we need them to link
  case $host_os in
   darwin*)
    EXTRA_LDFLAGS="$EXTRA_LDFLAGS -framework CoreFoundation -framework Security"
    PHP_ADD_FRAMEWORK([CoreFoundation])
    PHP_ADD_FRAMEWORK([Security])
    PHP_SUBST(EXTRA_LDFLAGS)
  esac

  PHP_CHECK_LIBRARY(curl, curl_easy_setopt,
    [PHP_ADD_LIBRARY(curl, , EXTRA_LDFLAGS)],
    [AC_MSG_ERROR([cannot find or include curl])])

  AC_CHECK_HEADER(time.h, [], [AC_MSG_ERROR([Cannot find or include time.h])])

  if test "$ext_shared" = "yes"; then
    dnl Only export symbols defined in datadog.sym, which should all be marked as
    dnl DATADOG_PUBLIC in their source files as well.
    DDTRACE_EXPORT_SYMBOLS="$ext_srcdir/datadog.sym"
    case $host_os in
      linux*)
        AX_CHECK_COMPILE_FLAG([-mtls-dialect=gnu2],
          [EXTRA_CFLAGS="$EXTRA_CFLAGS -mtls-dialect=gnu2"],
          [],
          [],
          [])
      ;;
      *)
        dnl otel_thread_ctx_v1 is a Linux-only TLS symbol; omit it where tls_shim.c is not compiled.
        DDTRACE_EXPORT_SYMBOLS="$ext_builddir/datadog.sym"
        $GREP -v '^otel_thread_ctx_v1$' "$ext_srcdir/datadog.sym" > "$DDTRACE_EXPORT_SYMBOLS"
      ;;
    esac
    EXTRA_CFLAGS="$EXTRA_CFLAGS -fvisibility=hidden"
    EXTRA_LDFLAGS="$EXTRA_LDFLAGS -export-symbols $DDTRACE_EXPORT_SYMBOLS -flto -fuse-linker-plugin"

    dnl On Linux: set the ELF entry point so ddtrace.so can be exec'd directly by ld.so
    dnl for sidecar spawning (no trampoline binary, no memfd, no temp files).
    case $host_os in
      linux*)
        EXTRA_LDFLAGS="$EXTRA_LDFLAGS -Wl,-e,ddog_spawn_direct_entry"
      ;;
    esac

    PHP_SUBST(EXTRA_CFLAGS)
    PHP_SUBST(EXTRA_LDFLAGS)
    PHP_SUBST(DDTRACE_SHARED_LIBADD)
  fi

  cat <<EOT >"$ext_srcdir/ext/version.h"
#ifndef PHP_DDTRACE_VERSION
#define PHP_DDTRACE_VERSION "$(cat "$ext_srcdir/VERSION")"
#endif
EOT

  PHP_ADD_INCLUDE([$ext_srcdir])

  PHP_ADD_BUILD_DIR([$ext_builddir/components-rs])

  PHP_ADD_BUILD_DIR([$ext_builddir/components])
  PHP_ADD_BUILD_DIR([$ext_builddir/components/log])
  PHP_ADD_BUILD_DIR([$ext_builddir/components/sapi])
  PHP_ADD_BUILD_DIR([$ext_builddir/components/string_view])
  PHP_ADD_BUILD_DIR([$ext_builddir/components/uuid])

  PHP_ADD_INCLUDE([$ext_srcdir/zend_abstract_interface])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/config])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/env])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/exceptions])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/headers])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/hook])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/interceptor])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/interceptor/php7])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/interceptor/php8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/jit_utils])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/json])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox/php7])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/sandbox/php8])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/uri_normalization])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/zai_assert])
  PHP_ADD_BUILD_DIR([$ext_builddir/zend_abstract_interface/zai_string])

  PHP_ADD_BUILD_DIR([$ext_builddir/tracer/hook])

  PHP_ADD_INCLUDE([$ext_srcdir/tracer/vendor])
  PHP_ADD_BUILD_DIR([$ext_builddir/tracer/vendor])

  PHP_ADD_INCLUDE([$ext_srcdir/tracer/vendor/mpack])
  PHP_ADD_BUILD_DIR([$ext_builddir/tracer/vendor/mpack])

  PHP_ADD_INCLUDE([$ext_srcdir/tracer/vendor/mt19937])
  PHP_ADD_BUILD_DIR([$ext_builddir/tracer/vendor/mt19937])

  dnl TODO Drop this in favor of sidecar
  PHP_ADD_INCLUDE([$ext_srcdir/src/dogstatsd])
  PHP_ADD_BUILD_DIR([$ext_builddir/src/dogstatsd])

  PHP_ADD_BUILD_DIR([$ext_builddir/ext])

  PHP_ADD_BUILD_DIR([$ext_builddir/tracer])
  PHP_ADD_BUILD_DIR([$ext_builddir/tracer/limiter])
  PHP_ADD_BUILD_DIR([$ext_builddir/tracer/priority_sampling])
  PHP_ADD_BUILD_DIR([$ext_builddir/tracer/tracer_tag_propagation])
  PHP_ADD_BUILD_DIR([$ext_builddir/tracer/integrations])
  PHP_ADD_INCLUDE([$ext_builddir/tracer/integrations])

  dnl Avoid cleaning rust artifacts with make clean (cargo is really good at detecting changes - and rust files are not dependent on php environment).
  dnl However, for users who really want to clean, there's always make distclean, which will flatly remove the whole target/ directory.
  AC_DEFUN([DDTRACE_GEN_GLOBAL_MAKEFILE_WRAP], [
    pushdef([PHP_GEN_GLOBAL_MAKEFILE], [
      popdef([PHP_GEN_GLOBAL_MAKEFILE])
      PHP_GEN_GLOBAL_MAKEFILE
      [sed -i $({ sed --version 2>&1 || echo ''; } | grep GNU >/dev/null || echo "''") -e '/.*\.[ao] /{s/| xargs rm -f/! -path ".\/target*\/*" | xargs rm -f/'$'\n}' -e '/^distclean:/a\'$'\n\t''rm -rf target/ target_mockgen/' Makefile]
      DDTRACE_GEN_GLOBAL_MAKEFILE_WRAP
    ])
  ])
  DDTRACE_GEN_GLOBAL_MAKEFILE_WRAP

  cat <<'EOT' >> Makefile.fragments
./modules/ddtrace.a: $(shared_objects_ddtrace) $(DDTRACE_SHARED_DEPENDENCIES)
	$(LIBTOOL) --mode=link $(CC) -static $(COMMON_FLAGS) $(CFLAGS_CLEAN) $(EXTRA_CFLAGS) $(LDFLAGS)  -o $@ -avoid-version -prefer-pic -module $(shared_objects_ddtrace)
EOT

  if test "$ext_shared" = "yes"; then
    all_object_files=$(for src in $ALL_DATADOG_SOURCES; do printf ' %s' "${src%?}lo"; done)
    all_object_files_absolute=$(for src in $ALL_DATADOG_SOURCES; do printf ' $(builddir)/%s' "$(dirname "$src")/$objdir/$(basename "${src%?}o")"; done)
    php_binary=$("$PHP_CONFIG" --php-binary)
    if test "$PHP_DDTRACE_SIDECAR_MOCKGEN" != "-"; then
      ddtrace_mockgen_invocation="HOST= TARGET= $PHP_DDTRACE_SIDECAR_MOCKGEN"
    else
      ddtrace_mockgen_invocation="cd \"$ext_srcdir/components-rs/php_sidecar_mockgen\"; HOST= TARGET= CARGO_TARGET_DIR=\$(builddir)/target_mockgen/ \$(DDTRACE_CARGO) run"
    fi
  fi

  if test "$PHP_DDTRACE_RUST_LIBRARY_SPLIT" != "no"; then
    ddtrace_rust_lib=""
  elif test "$PHP_DDTRACE_RUST_LIBRARY" != "-"; then
    ddtrace_rust_lib="$PHP_DDTRACE_RUST_LIBRARY"
  else
    dnl consider it debug if -g is specified (but not -g0)
    ddtrace_cargo_profile=$(test "$PHP_DDTRACE_RUST_DEBUG" != "no" && echo debug || echo tracer-release)
    ddtrace_rust_lib="\$(builddir)/target/$ddtrace_cargo_profile/libdatadog_php.a"

    cat <<EOT >> Makefile.fragments
$ddtrace_rust_lib: $( (find "$ext_srcdir/components-rs" -name "*.rs" -o -name "Cargo.toml"; find "$ext_srcdir/../../libdatadog" -name "*.rs" -not -path "*/target/*"; find "$ext_srcdir/libdatadog" -name "*.rs" -not -path "*/target/*") 2>/dev/null | tr '\n' ' ' )
	(cd "$ext_srcdir"; CARGO_TARGET_DIR=\$(builddir)/target/ SHARED=$(test "$ext_shared" = "yes" && echo 1) PROFILE="$ddtrace_cargo_profile" host_os="$host_os" DDTRACE_CARGO="\$(DDTRACE_CARGO)" $(if test "$PHP_DDTRACE_SANITIZE" != "no"; then echo COMPILE_ASAN=1; fi) sh ./compile_rust.sh \$(shell echo "\$(MAKEFLAGS)" | $EGREP -o "[[-]]j[[0-9]]+"))
EOT
  fi

  dnl Weaken PHP-origin symbols in all .o files before the link step so that
  dnl the resulting .so/.a naturally has weak references.
  if test "$ext_shared" = "yes"; then
    _ddtrace_weaken_tmp=$(mktemp)
    cat > "$_ddtrace_weaken_tmp" << WEAKEN
	($ddtrace_mockgen_invocation weaken-dynsym $all_object_files_absolute $php_binary)
WEAKEN
    sed -i $({ sed --version 2>&1 || echo ''; } | grep GNU >/dev/null || echo "''") -e "/\/ddtrace\.la:\ \\$/r $_ddtrace_weaken_tmp" Makefile.objects
    dnl run weaken only once, create a dependency on .la for .a
    echo "./modules/ddtrace.a: | ./ddtrace.la" >> Makefile.fragments
    rm -f "$_ddtrace_weaken_tmp"
  fi

  if test "$ext_shared" = "shared" || test "$ext_shared" = "yes"; then
    shared_objects_ddtrace="$ddtrace_rust_lib $shared_objects_ddtrace"
  else
    PHP_GLOBAL_OBJS="$ddtrace_rust_lib $PHP_GLOBAL_OBJS"
  fi

  echo "$EXTRA_LDFLAGS $EXTRA_CFLAGS" > ddtrace.ldflags
fi
