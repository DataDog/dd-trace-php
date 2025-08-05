PHP_ARG_ENABLE([dd_library_loader],
  [whether to enable dd_library_loader support],
  [AS_HELP_STRING([--enable-dd_library_loader],
    [Enable dd_library_loader support])],
  [no])

dnl Detect musl libc
AC_MSG_CHECKING([whether we are using musl libc])
if command -v ldd >/dev/null && ldd --version 2>&1 | grep -q ^musl
then
  AC_MSG_RESULT(yes)
  AC_DEFINE([__MUSL__], [1], [Define when using musl libc])
else
  AC_MSG_RESULT(no)
fi

if test "$PHP_DD_LIBRARY_LOADER" != "no"; then
  dnl In case of no dependencies
  AC_DEFINE(HAVE_DD_LIBRARY_LOADER, 1, [ Have dd_library_loader support ])

  PHP_NEW_EXTENSION(dd_library_loader, dd_library_loader.c dd_library_loader_module.c compat_php.c, $ext_shared, , , , yes)
fi
