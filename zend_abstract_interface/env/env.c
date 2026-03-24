#include "../tsrmls_cache.h"
#include <main/SAPI.h>
#include <main/php.h>

#include <stdlib.h>
#include <string.h>

#include "env.h"

#if PHP_VERSION_ID >= 80000
#define sapi_getenv_compat(name, name_len) sapi_getenv(name, name_len)
#else
#define sapi_getenv_compat(name, name_len) sapi_getenv((char *)name, name_len)
#endif

zai_env_result zai_sapi_getenv(zai_str name, zai_env_buffer *buf) {
    ZAI_ASSERT(buf && "zai_sapi_getenv: buf must be non-NULL");
    ZAI_ASSERT((PG(modules_activated) | PG(during_request_startup)) && "zai_sapi_getenv: must be called during or after RINIT");

    // Optimize for the happy-path where the caller has valid inputs. On every
    // request, every config is likely to check for a sapi-provided env var,
    // so this is reasonably hot.
    // Use bitwise-or and bitwise-and as appropriate to avoid excess branches
    // that aren't going to happen in production.

    if (UNEXPECTED(zai_str_is_empty(name) | !buf->ptr | !buf->len)) return ZAI_ENV_ERROR;
    if (UNEXPECTED(buf->len > ZAI_ENV_MAX_BUFSIZ)) return ZAI_ENV_BUFFER_TOO_BIG;

    char *value = sapi_getenv_compat(name.ptr, name.len);
    if (!value) return ZAI_ENV_NOT_SET;

    zai_env_result res = ZAI_ENV_SUCCESS;
    if (EXPECTED(strlen(value) < buf->len)) {
        strcpy(buf->ptr, value);
    } else {
        res = ZAI_ENV_BUFFER_TOO_SMALL;
    }

    efree(value);
    return res;
}
