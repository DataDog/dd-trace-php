dnl config.m4 for extension ddog_php_experiment

dnl Comments in this file start with the string 'dnl'.
dnl Remove where necessary.

dnl If your extension references something external, use 'with':

dnl PHP_ARG_WITH([ddog_php_experiment],
dnl   [for ddog_php_experiment support],
dnl   [AS_HELP_STRING([--with-ddog_php_experiment],
dnl     [Include ddog_php_experiment support])])

dnl Otherwise use 'enable':

PHP_ARG_ENABLE([ddog_php_experiment],
  [whether to enable ddog_php_experiment support],
  [AS_HELP_STRING([--enable-ddog_php_experiment],
    [Enable ddog_php_experiment support])],
  [no])

if test "$PHP_DDOG_PHP_EXPERIMENT" != "no"; then
  dnl Write more examples of tests here...

  dnl Remove this code block if the library does not support pkg-config.
  dnl PKG_CHECK_MODULES([LIBFOO], [foo])
  dnl PHP_EVAL_INCLINE($LIBFOO_CFLAGS)
  dnl PHP_EVAL_LIBLINE($LIBFOO_LIBS, DDOG_PHP_EXPERIMENT_SHARED_LIBADD)

  dnl If you need to check for a particular library version using PKG_CHECK_MODULES,
  dnl you can use comparison operators. For example:
  dnl PKG_CHECK_MODULES([LIBFOO], [foo >= 1.2.3])
  dnl PKG_CHECK_MODULES([LIBFOO], [foo < 3.4])
  dnl PKG_CHECK_MODULES([LIBFOO], [foo = 1.2.3])

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-ddog_php_experiment -> check with-path
  dnl SEARCH_PATH="/usr/local /usr"     # you might want to change this
  dnl SEARCH_FOR="/include/ddog_php_experiment.h"  # you most likely want to change this
  dnl if test -r $PHP_DDOG_PHP_EXPERIMENT/$SEARCH_FOR; then # path given as parameter
  dnl   DDOG_PHP_EXPERIMENT_DIR=$PHP_DDOG_PHP_EXPERIMENT
  dnl else # search default path list
  dnl   AC_MSG_CHECKING([for ddog_php_experiment files in default path])
  dnl   for i in $SEARCH_PATH ; do
  dnl     if test -r $i/$SEARCH_FOR; then
  dnl       DDOG_PHP_EXPERIMENT_DIR=$i
  dnl       AC_MSG_RESULT(found in $i)
  dnl     fi
  dnl   done
  dnl fi
  dnl
  dnl if test -z "$DDOG_PHP_EXPERIMENT_DIR"; then
  dnl   AC_MSG_RESULT([not found])
  dnl   AC_MSG_ERROR([Please reinstall the ddog_php_experiment distribution])
  dnl fi

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-ddog_php_experiment -> add include path
  dnl PHP_ADD_INCLUDE($DDOG_PHP_EXPERIMENT_DIR/include)

  dnl Remove this code block if the library supports pkg-config.
  dnl --with-ddog_php_experiment -> check for lib and symbol presence
  dnl LIBNAME=DDOG_PHP_EXPERIMENT # you may want to change this
  dnl LIBSYMBOL=DDOG_PHP_EXPERIMENT # you most likely want to change this

  dnl If you need to check for a particular library function (e.g. a conditional
  dnl or version-dependent feature) and you are using pkg-config:
  dnl PHP_CHECK_LIBRARY($LIBNAME, $LIBSYMBOL,
  dnl [
  dnl   AC_DEFINE(HAVE_DDOG_PHP_EXPERIMENT_FEATURE, 1, [ ])
  dnl ],[
  dnl   AC_MSG_ERROR([FEATURE not supported by your ddog_php_experiment library.])
  dnl ], [
  dnl   $LIBFOO_LIBS
  dnl ])

  dnl If you need to check for a particular library function (e.g. a conditional
  dnl or version-dependent feature) and you are not using pkg-config:
  dnl PHP_CHECK_LIBRARY($LIBNAME, $LIBSYMBOL,
  dnl [
  dnl   PHP_ADD_LIBRARY_WITH_PATH($LIBNAME, $DDOG_PHP_EXPERIMENT_DIR/$PHP_LIBDIR, DDOG_PHP_EXPERIMENT_SHARED_LIBADD)
  dnl   AC_DEFINE(HAVE_DDOG_PHP_EXPERIMENT_FEATURE, 1, [ ])
  dnl ],[
  dnl   AC_MSG_ERROR([FEATURE not supported by your ddog_php_experiment library.])
  dnl ],[
  dnl   -L$DDOG_PHP_EXPERIMENT_DIR/$PHP_LIBDIR -lm
  dnl ])
  dnl
  dnl PHP_SUBST(DDOG_PHP_EXPERIMENT_SHARED_LIBADD)

  dnl In case of no dependencies
  AC_DEFINE(HAVE_DDOG_PHP_EXPERIMENT, 1, [ Have ddog_php_experiment support ])

  PHP_NEW_EXTENSION(ddog_php_experiment, ddog_php_experiment.c, $ext_shared,,-Wall -std=gnu11)
fi
