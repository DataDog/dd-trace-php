#include "env.h"

#include <main/SAPI.h>
#include <main/php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#include <zai_compat.h>

#if PHP_VERSION_ID >= 80000
static inline char *sapi_getenv_compat(const char *name, size_t len) { return sapi_getenv(name, len); }
#elif PHP_VERSION_ID >= 70000
static inline char *sapi_getenv_compat(const char *name, size_t len) { return sapi_getenv((char *)name, len); }
#else
static inline char *sapi_getenv_compat(const char *name, size_t len) {
#if PHP_VERSION_ID < 70000
    TSRMLS_FETCH();
#endif

    return sapi_getenv((char *)name, len ZAI_TSRMLS_CC);
}
#endif

static inline char *nosapi_getenv_compat(const char *name, size_t len) {
    (void)len;

    return getenv(name);
}

zai_env_result zai_getenv_ex(zai_string_view name, zai_env_buffer buf, bool pre_rinit) {
    if (!buf.ptr || !buf.len) {
        return ZAI_ENV_ERROR;
    }

    buf.ptr[0] = '\0';

    if (!zai_string_stuffed(name)) {
        return ZAI_ENV_ERROR;
    }

    if (buf.len > ZAI_ENV_MAX_BUFSIZ) {
        return ZAI_ENV_BUFFER_TOO_BIG;
    }

    char *(*readenv)(const char *, size_t) = sapi_module.getenv ? sapi_getenv_compat : nosapi_getenv_compat;

    char *value = readenv(name.ptr, name.len);

    if (!value) {
        /* in FCGI mode at request time, the FCGI SAPI will (by design)
            only check the FCGI request for the requested variable.
           we require the value from the actual environment if it is present
           in FCGI mode, this prohibits our ability to determine if a variable
           has been unset by the request
           so that FCGI/Apache behave consistently with regard to unset, we do
           not check for FCGI specifically, but apply this behaviour to any SAPI
           while takes control of environ via sapi_module.getenv */
        if (sapi_module.getenv) {
            value = getenv(name.ptr);

            if (value) {
                value = estrdup(value);

                goto zai_getenv_result;
            }
        }
        return ZAI_ENV_NOT_SET;
    }

    zai_getenv_result: {
        zai_env_result res;

        if (strlen(value) < buf.len) {
            strcpy(buf.ptr, value);
            res = ZAI_ENV_SUCCESS;
        } else {
            res = ZAI_ENV_BUFFER_TOO_SMALL;
        }

        if (sapi_module.getenv) {
            efree(value);
        }
        return res;
    }
}
