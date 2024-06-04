dnl config.m4 for extension dd_library_loader

dnl Comments in this file start with the string 'dnl'.
dnl Remove where necessary.

dnl If your extension references something external, use 'with':

dnl PHP_ARG_WITH([dd_library_loader],
dnl   [for dd_library_loader support],
dnl   [AS_HELP_STRING([--with-dd_library_loader],
dnl     [Include dd_library_loader support])])

dnl Otherwise use 'enable':

PHP_ARG_ENABLE([dd_library_loader],
  [whether to enable dd_library_loader support],
  [AS_HELP_STRING([--enable-dd_library_loader],
    [Enable dd_library_loader support])],
  [no])

if test "$PHP_DD_LIBRARY_LOADER" != "no"; then
  dnl Write more examples of tests here...

  dnl Remove this code block if the library does not support pkg-config.
  dnl PKG_CHECK_MODULES([LIBFOO], [foo])
  dnl PHP_EVAL_INCLINE($LIBFOO_CFLAGS)
  dnl PHP_EVAL_LIBLINE($LIBFOO_LIBS, DD_LIBRARY_LOADER_SHARED_LIBADD)

  dnl If you need to check for a particular library version using PKG_CHECK_MODULES,
  dnl you can use comparison operators. For example:
  dnl PKG_CHECK_MODULES([LIBFOO], [foo >= 1.2.3])
  dnl PKG_CHECK_MODULES([LIBFOO], [foo < 3.4])
  dnl PKG_CHECK_MODULES([LIBFOO], [foo = 1.2.3])

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-dd_library_loader -> check with-path
  dnl SEARCH_PATH="/usr/local /usr"     # you might want to change this
  dnl SEARCH_FOR="/include/dd_library_loader.h"  # you most likely want to change this
  dnl if test -r $PHP_DD_LIBRARY_LOADER/$SEARCH_FOR; then # path given as parameter
  dnl   DD_LIBRARY_LOADER_DIR=$PHP_DD_LIBRARY_LOADER
  dnl else # search default path list
  dnl   AC_MSG_CHECKING([for dd_library_loader files in default path])
  dnl   for i in $SEARCH_PATH ; do
  dnl     if test -r $i/$SEARCH_FOR; then
  dnl       DD_LIBRARY_LOADER_DIR=$i
  dnl       AC_MSG_RESULT(found in $i)
  dnl     fi
  dnl   done
  dnl fi
  dnl
  dnl if test -z "$DD_LIBRARY_LOADER_DIR"; then
  dnl   AC_MSG_RESULT([not found])
  dnl   AC_MSG_ERROR([Please reinstall the dd_library_loader distribution])
  dnl fi

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-dd_library_loader -> add include path
  dnl PHP_ADD_INCLUDE($DD_LIBRARY_LOADER_DIR/include)

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-dd_library_loader -> check for lib and symbol presence
  dnl LIBNAME=DD_LIBRARY_LOADER # you may want to change this
  dnl LIBSYMBOL=DD_LIBRARY_LOADER # you most likely want to change this

  dnl If you need to check for a particular library function (e.g. a conditional
  dnl or version-dependent feature) and you are using pkg-config:
  dnl PHP_CHECK_LIBRARY($LIBNAME, $LIBSYMBOL,
  dnl [
  dnl   AC_DEFINE(HAVE_DD_LIBRARY_LOADER_FEATURE, 1, [ ])
  dnl ],[
  dnl   AC_MSG_ERROR([FEATURE not supported by your dd_library_loader library.])
  dnl ], [
  dnl   $LIBFOO_LIBS
  dnl ])

  dnl If you need to check for a particular library function (e.g. a conditional
  dnl or version-dependent feature) and you are not using pkg-config:
  dnl PHP_CHECK_LIBRARY($LIBNAME, $LIBSYMBOL,
  dnl [
  dnl   PHP_ADD_LIBRARY_WITH_PATH($LIBNAME, $DD_LIBRARY_LOADER_DIR/$PHP_LIBDIR, DD_LIBRARY_LOADER_SHARED_LIBADD)
  dnl   AC_DEFINE(HAVE_DD_LIBRARY_LOADER_FEATURE, 1, [ ])
  dnl ],[
  dnl   AC_MSG_ERROR([FEATURE not supported by your dd_library_loader library.])
  dnl ],[
  dnl   -L$DD_LIBRARY_LOADER_DIR/$PHP_LIBDIR -lm
  dnl ])
  dnl
  dnl PHP_SUBST(DD_LIBRARY_LOADER_SHARED_LIBADD)

  dnl In case of no dependencies
  AC_DEFINE(HAVE_DD_LIBRARY_LOADER, 1, [ Have dd_library_loader support ])

  PHP_NEW_EXTENSION(dd_library_loader, dd_library_loader.c compat_php.c, $ext_shared)
fi
