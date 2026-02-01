PHP_ARG_ENABLE([datadog-profiling],
  [whether to enable Datadog profiling support],
  [AS_HELP_STRING([--enable-datadog-profiling],
    [Enable Datadog profiling support])],
  [no])

AC_ARG_ENABLE([datadog-profiling-debug],
  [AS_HELP_STRING([--enable-datadog-profiling-debug],
    [Build Datadog profiling with the Cargo debug profile])],
  [PHP_DATADOG_PROFILING_DEBUG=$enableval],
  [PHP_DATADOG_PROFILING_DEBUG=no])

PHP_ARG_WITH([datadog-profiling-cargo],
  [path to cargo binary],
  [AS_HELP_STRING([--with-datadog-profiling-cargo=PATH],
    [Path to cargo binary for Rust compilation])],
  [cargo],
  [no])

PHP_ARG_WITH([datadog-profiling-cargo-features],
  [cargo feature list],
  [AS_HELP_STRING([--with-datadog-profiling-cargo-features=LIST],
    [Comma or space-separated Cargo feature list for the profiler crate])],
  [],
  [no])

if test "$PHP_DATADOG_PROFILING" != "no"; then
  PHP_ALWAYS_SHARED([PHP_DATADOG_PROFILING])
  AC_DEFINE([HAVE_DATADOG_PROFILING], [1], [Have Datadog profiling support])

  if test "$PHP_DATADOG_PROFILING_CARGO" != "cargo"; then
    if test -x "$PHP_DATADOG_PROFILING_CARGO"; then
      DD_PROFILING_CARGO="$PHP_DATADOG_PROFILING_CARGO"
    else
      AC_MSG_ERROR([$PHP_DATADOG_PROFILING_CARGO is not an executable])
    fi
  else
    AC_CHECK_TOOL([DD_PROFILING_CARGO], [cargo], [:])
    AS_IF([test "$DD_PROFILING_CARGO" = ":"],
      [AC_MSG_ERROR([Please install cargo before configuring, or specify it with --with-datadog-profiling-cargo=])])
  fi
  PHP_SUBST([DD_PROFILING_CARGO])

  if test "$PHP_DATADOG_PROFILING_DEBUG" != "no"; then
    DD_PROFILING_CARGO_PROFILE="debug"
    DD_PROFILING_CARGO_ARGS=""
  else
    DD_PROFILING_CARGO_PROFILE="release"
    DD_PROFILING_CARGO_ARGS="--release"
  fi
  PHP_SUBST([DD_PROFILING_CARGO_PROFILE])
  PHP_SUBST([DD_PROFILING_CARGO_ARGS])

  if test "$PHP_DATADOG_PROFILING_CARGO_FEATURES" = "no"; then
    DD_PROFILING_CARGO_FEATURES=""
  else
    DD_PROFILING_CARGO_FEATURES="$PHP_DATADOG_PROFILING_CARGO_FEATURES"
  fi
  PHP_SUBST([DD_PROFILING_CARGO_FEATURES])

  DATADOG_PHP_VERSION_ID="$($PHP_CONFIG --vernum 2>/dev/null)"
  AS_VAR_IF([DATADOG_PHP_VERSION_ID],,
    [AC_MSG_ERROR([Failed to determine PHP version id (php-config --vernum)])])
  PHP_SUBST([DATADOG_PHP_VERSION_ID])

  PHP_ADD_MAKEFILE_FRAGMENT([Makefile.frag])
fi
