PHP_ARG_ENABLE([datadog-profiling],
  [whether to enable Datadog Continuous Profiler support],
  [AS_HELP_STRING([--enable-datadog-profiling],
    [Enable Datadog profiling support])],
  [no],
  [no])

if test $PHP_VERSION_ID -ge 70100 ||  test "$PHP_DATADOG_PROFILING" = "yes"; then
  dnl When packaging this ourselves, make sure it's the static version!
  PKG_CHECK_MODULES([LIBUV], [libuv])
  PHP_EVAL_LIBLINE($LIBUV_LIBS, EXTRA_LDFLAGS)
  PHP_EVAL_INCLINE($LIBUV_CFLAGS)

  dnl When packaging this ourselves, make sure it's the static version!
  PKG_CHECK_MODULES([LIBDDPROF_FFI], [ddprof_ffi])
  PHP_EVAL_LIBLINE($LIBDDPROF_FFI_LIBS, EXTRA_LDFLAGS)
  PHP_EVAL_INCLINE($LIBDDPROF_FFI_CFLAGS)

  PHP_DATADOG_PROFILING_SOURCES="\
    profiling/datadog-profiling.c \
    profiling/components/arena/arena.c \
    profiling/components/channel/channel.c \
    profiling/components/log/log.c \
    profiling/components/queue/queue.c \
    profiling/components/sapi/sapi.c \
    profiling/components/stack-sample/stack-sample.c \
    profiling/components/string-view/string-view.c \
    profiling/components/time/time.c \
    profiling/plugins/log_plugin/log_plugin.c \
    profiling/plugins/recorder_plugin/recorder_plugin.c \
    profiling/plugins/stack_collector_plugin/stack_collector_plugin.c \
    profiling/stack-collector/stack-collector.c \
   "

  dnl todo: detect these
  PHP_DATADOG_PROFILING_CFLAGS="-DDATADOG_HAVE_PTHREAD_GETCPUCLOCKID=1 -DDATADOG_HAVE_TIMESPEC_GET=1 -DDATADOG_HAVE_CLOCK_GETTIME=1"
  AC_DEFINE(HAVE_DATADOG_PROFILING, 1, [whether to include the profiler])
else
  PHP_DATADOG_PROFILING_SOURCES=""
  PHP_DATADOG_PROFILING_CFLAGS=""
  AC_DEFINE(HAVE_DATADOG_PROFILING, 0, [whether to include the profiler])
fi
