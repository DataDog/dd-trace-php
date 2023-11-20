#pragma once

#include <php.h>

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80101 && defined(ZTS)

#  if defined(__has_attribute) && __has_attribute(tls_model)
#    define ATTR_TLS_LOCAL_DYNAMIC __attribute__((tls_model("local-dynamic")))
#  else
#    define ATTR_TLS_LOCAL_DYNAMIC
#  endif

extern __thread void *ATTR_TLS_LOCAL_DYNAMIC TSRMLS_CACHE;
#endif
