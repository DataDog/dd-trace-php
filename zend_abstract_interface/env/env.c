#include <main/SAPI.h>

#include "env.h"

#if PHP_VERSION_ID >= 80000
#define sapi_getenv_compat(name, name_len) sapi_getenv(name, name_len)
#else
#define sapi_getenv_compat(name, name_len) sapi_getenv((char *)name, name_len)
#endif

zai_option_str zai_sapi_getenv(zai_str name) {
    char *value = sapi_getenv_compat(name.ptr, name.len);
    if (value) {
        return zai_option_str_from_raw_parts(value, strlen(value));
    }
    return ZAI_OPTION_STR_NONE;
}
