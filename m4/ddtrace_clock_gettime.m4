dnl This is copied and modified from PHP's own source code for HAVE_CLOCK_GETTIME
dnl We cannot use PHP's HAVE_CLOCK_GETTIME as it may not get run depending on
dnl what configure flags were passed
AC_DEFUN([DDTRACE_CLOCK_GETTIME],
[
  have_clock_gettime=no
  AC_MSG_CHECKING([for clock_gettime])
  AC_TRY_LINK([ #include <time.h> ], [struct timespec ts; clock_gettime(CLOCK_MONOTONIC, &ts);], [
    have_clock_gettime=yes
    AC_MSG_RESULT([yes])
  ], [
    AC_MSG_RESULT([no])
  ])
  if test "$have_clock_gettime" = "no"; then
    AC_MSG_CHECKING([for clock_gettime in -lrt])
    SAVED_LIBS="$LIBS"
    LIBS="$LIBS -lrt"
    AC_TRY_LINK([ #include <time.h> ], [struct timespec ts; clock_gettime(CLOCK_MONOTONIC, &ts);], [
      have_clock_gettime=yes
      AC_MSG_RESULT([yes])
    ], [
      LIBS="$SAVED_LIBS"
      AC_MSG_RESULT([no])
    ])
  fi
  if test "$have_clock_gettime" = "yes"; then
    AC_DEFINE([DDTRACE_HAVE_CLOCK_GETTIME], 1, [do we have clock_gettime?])
  fi
])
