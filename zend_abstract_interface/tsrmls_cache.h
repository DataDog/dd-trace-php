#pragma once

#include <php.h>

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100 && defined(ZTS)

#  if defined(__has_attribute) && __has_attribute(tls_model)
#    define ATTR_TLS_GLOBAL_DYNAMIC __attribute__((tls_model("global-dynamic")))
#  else
#    define ATTR_TLS_GLOBAL_DYNAMIC
#  endif

extern TSRM_TLS void *ATTR_TLS_GLOBAL_DYNAMIC TSRMLS_CACHE;
#endif
