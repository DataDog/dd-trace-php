PHP_ARG_ENABLE([dd_library_loader],
  [whether to enable dd_library_loader support],
  [AS_HELP_STRING([--enable-dd_library_loader],
    [Enable dd_library_loader support])],
  [no])

if test "$PHP_DD_LIBRARY_LOADER" != "no"; then
  dnl In case of no dependencies
  AC_DEFINE(HAVE_DD_LIBRARY_LOADER, 1, [ Have dd_library_loader support ])

  PHP_NEW_EXTENSION(dd_library_loader, dd_library_loader.c compat_php.c, $ext_shared, , , , yes)
fi
