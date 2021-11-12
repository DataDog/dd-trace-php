PHP_ARG_ENABLE([datadog-profiling],
  [whether to enable Datadog Continuous Profiler support],
  [AS_HELP_STRING([--enable-datadog-profiling],
    [Enable Datadog profiling support])],
  [no],
  [no])

PHP_DATADOG_PROFILING_CFLAGS=""

if test $PHP_VERSION_ID -ge 70100 &&  test "$PHP_DATADOG_PROFILING" = "yes"; then
  dnl When packaging this ourselves, make sure it's the static version!
  PKG_CHECK_MODULES([LIBUV], [libuv])
  PHP_EVAL_LIBLINE($LIBUV_LIBS, EXTRA_LDFLAGS)
  PHP_EVAL_INCLINE($LIBUV_CFLAGS)

  dnl When packaging this ourselves, make sure it's the static version!
  PKG_CHECK_MODULES([LIBDDPROF_FFI], [ddprof_ffi])
  PHP_EVAL_LIBLINE($LIBDDPROF_FFI_LIBS, EXTRA_LDFLAGS)
  PHP_EVAL_INCLINE($LIBDDPROF_FFI_CFLAGS)

  PHP_DATADOG_PROFILING_SOURCES="\
    components/arena/arena.c \
    components/channel/channel.c \
    components/log/log.c \
    components/queue/queue.c \
    components/stack-sample/stack-sample.c \
    components/time/time.c \
    profiling/datadog-profiling.c \
    profiling/plugins/log_plugin/log_plugin.c \
    profiling/plugins/recorder_plugin/recorder_plugin.c \
    profiling/plugins/stack_collector_plugin/stack_collector_plugin.c \
    profiling/stack-collector/stack-collector.c \
   "

  AC_CACHE_CHECK([for pthread_getcpuclockid], ac_cv_pthread_getcpuclockid,
    [
      AC_COMPILE_IFELSE(
        [AC_LANG_PROGRAM(
          [[#include <pthread.h>
            #include <time.h>]],
          [[clockid_t clockid;
            // consider the fact the API exists good enough; it may still fail at runtime,
            // and that's okay; it just needs to exist.
            (void)pthread_getcpuclockid(pthread_self(), &clockid);
          ]]
        )],
        [ac_cv_pthread_getcpuclockid=yes], [ac_cv_pthread_getcpuclockid=no])
    ])

  dnl Prior to glibc 2.17, clock_gettime was in librt, but this will not try to link.
  dnl todo: use AC_RUN_IFELSE instead
  AC_CACHE_CHECK([for clock_gettime], ac_cv_clock_gettime,
    [
      AC_COMPILE_IFELSE(
        [AC_LANG_PROGRAM(
          [[#include <time.h>]],
          [[struct timespec ts;
            return clock_gettime(CLOCK_REALTIME, &ts) == 0 ? 0 : 1;]]
        )],
        [ac_cv_clock_gettime=yes], [ac_cv_clock_gettime=no])
    ])

  AC_CACHE_CHECK([for timespec_get], ac_cv_timespec_get,
    [
      AC_COMPILE_IFELSE(
        [AC_LANG_PROGRAM(
          [[#include <time.h>]],
          [[struct timespec ts;
            return timespec_get(&ts, TIME_UTC) == TIME_UTC ? 0 : 1;]]
        )],
        [ac_cv_timespec_get=yes], [ac_cv_timespec_get=no])
    ])

  AC_CACHE_CHECK([for thread_info], ac_cv_thread_info,
    [
      AC_COMPILE_IFELSE(
        [AC_LANG_PROGRAM(
          [[#include <mach/mach_init.h>
            #include <mach/thread_act.h>]],
          [[mach_port_t thread = mach_thread_self();
            mach_msg_type_number_t count = THREAD_BASIC_INFO_COUNT;
            thread_basic_info_data_t info;
            (void)thread_info(thread, THREAD_BASIC_INFO, (thread_info_t) &info, &count);]]
        )],
        [ac_cv_thread_info=yes], [ac_cv_thread_info=no])
    ])

  if test "$ac_cv_pthread_getcpuclockid" = "yes" ; then
    PHP_DATADOG_PROFILING_CFLAGS="$PHP_DATADOG_PROFILING_CFLAGS -DDATADOG_HAVE_PTHREAD_GETCPUCLOCKID=1"
  fi

  if test "$ac_cv_clock_gettime" = "yes" ; then
    PHP_DATADOG_PROFILING_CFLAGS="$PHP_DATADOG_PROFILING_CFLAGS -DDATADOG_HAVE_CLOCK_GETTIME=1"
  fi

  if test "$ac_cv_timespec_get" = "yes" ; then
    PHP_DATADOG_PROFILING_CFLAGS="$PHP_DATADOG_PROFILING_CFLAGS -DDATADOG_HAVE_TIMESPEC_GET=1"
  fi

  if test "$ac_cv_thread_info" = "yes" ; then
    PHP_DATADOG_PROFILING_CFLAGS="$PHP_DATADOG_PROFILING_CFLAGS -DDATADOG_HAVE_THREAD_INFO=1"
  fi

  have_datadog_profiling=1
  AC_DEFINE(HAVE_DATADOG_PROFILING, 1, [whether to include the profiler])
else
  PHP_DATADOG_PROFILING_SOURCES=""
  AC_DEFINE(HAVE_DATADOG_PROFILING, 0, [whether to include the profiler])
fi
