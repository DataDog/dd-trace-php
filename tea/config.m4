PHP_ARG_ENABLE([tea],, [--enable-tea=shared|static|yes|no], [no], [no])

if test "$PHP_TEA" != "no"; then
  AC_MSG_CHECKING([Tea build configuration])

  PHP_TEA_TYPE=none

  case "${PHP_TEA}" in
    yes|shared)
        PHP_TEA_TYPE=shared
    ;;
    static)
        PHP_TEA_TYPE=static
    ;;
    *)
        PHP_TEA_TYPE=none
    ;;
  esac

  if test "${PHP_TEA_TYPE}" != "none"; then
    AC_DEFINE(HAVE_TEA, 1, [ ])

    PHP_TEA_INCLUDE="sapi/tea/include"
    PHP_TEA_HEADERS="common.h error.h exceptions.h extension.h frame.h sapi.h testing/catch2.hpp"
    PHP_TEA_CFLAGS="-I${abs_srcdir}/${PHP_TEA_INCLUDE}"
    PHP_TEA_FILES="src/error.c src/exceptions.c src/frame.c src/io.c src/ini.c src/extension.c src/sapi.c"

    PHP_TEA_INSTALL_PROLOGUE="                                             \
      \$(mkinstalldirs) \$(INSTALL_ROOT)\$(prefix)/lib/php;                \
      \$(mkinstalldirs) \$(INSTALL_ROOT)\$(prefix)/include/php/tea;        \
      \$(mkinstalldirs) \$(INSTALL_ROOT)\$(prefix)/include/php/tea/testing;"

    case "${PHP_TEA_TYPE}" in
      shared)
        PHP_TEA_LIBRARY="libtea.${SHLIB_DL_SUFFIX_NAME}"
        PHP_TEA_INSTALL_COMMAND="                \
          \$(INSTALL_DATA)                       \
              ${SAPI_SHARED}                     \
              \$(INSTALL_ROOT)\$(prefix)/lib/php/${PHP_TEA_LIBRARY};"
      ;;
      static)
        PHP_TEA_LIBRARY="libtea.${SHLIB_SUFFIX_NAME}"
        PHP_TEA_INSTALL_COMMAND="               \
          \$(INSTALL_DATA)                      \
              ${SAPI_STATIC}                    \
             \$(INSTALL_ROOT)\$(prefix)/lib/php/${PHP_TEA_LIBRARY};"
      ;;
    esac

    for PHP_TEA_HEADER in $PHP_TEA_HEADERS
    do
      PHP_TEA_INSTALL_EPILOGUE="                                      \
          ${PHP_TEA_INSTALL_EPILOGUE}                                 \
          \$(INSTALL_DATA)                                            \
              ${PHP_TEA_INCLUDE}/${PHP_TEA_HEADER}                    \
              \$(INSTALL_ROOT)\$(phpincludedir)/tea/${PHP_TEA_HEADER};"
    done

    PHP_SELECT_SAPI(tea, $PHP_TEA_TYPE, $PHP_TEA_FILES, $PHP_TEA_CFLAGS, [$(SAPI_TEA_PATH)])

    INSTALL_IT="                 \
      ${INSTALL_IT}              \
      ${PHP_TEA_INSTALL_PROLOGUE}\
      ${PHP_TEA_INSTALL_COMMAND} \
      ${PHP_TEA_INSTALL_EPILOGUE}"

    AC_MSG_RESULT([${PHP_TEA_TYPE} as ${PHP_TEA_LIBRARY}])
  else
    AC_MSG_RESULT([disabled by invalid configuration])
  fi
fi
