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

static zai_env_result zai_env_validate_buf(zai_env_buffer *buf) {
    if (UNEXPECTED(!buf || !buf->ptr || !buf->len)) return ZAI_ENV_ERROR;
    buf->ptr[0] = '\0';
    return (buf->len <= ZAI_ENV_MAX_BUFSIZ)
        ? ZAI_ENV_SUCCESS
        : ZAI_ENV_BUFFER_TOO_BIG;
}

zai_env_result zai_sapi_getenv(zai_str name, zai_env_buffer *buf) {
    zai_env_result res = zai_env_validate_buf(buf);
    if (UNEXPECTED(res != ZAI_ENV_SUCCESS)) return res;

    if (UNEXPECTED(zai_str_is_empty(name))) return ZAI_ENV_ERROR;

    /* Some SAPIs do not initialize the SAPI-controlled environment variables
     * until SAPI RINIT. It is for this reason we cannot reliably access
     * SAPI environment variables until module RINIT.
     */
    if (!PG(modules_activated) && !PG(during_request_startup)) return ZAI_ENV_NOT_READY;

    char *value = sapi_getenv_compat(name.ptr, name.len);
    if (!value) return ZAI_ENV_NOT_SET;

    if (strlen(value) < buf->len) {
        strcpy(buf->ptr, value);
        res = ZAI_ENV_SUCCESS;
    } else {
        res = ZAI_ENV_BUFFER_TOO_SMALL;
    }

    efree(value);
    return res;
}

zai_env_result zai_sys_getenv(zai_str name, zai_env_buffer *buf) {
    zai_env_result res = zai_env_validate_buf(buf);
    if (UNEXPECTED(res != ZAI_ENV_SUCCESS)) return res;

    if (UNEXPECTED(zai_str_is_empty(name))) return ZAI_ENV_ERROR;

    char *value = getenv(name.ptr);
    if (!value) return ZAI_ENV_NOT_SET;

    if (strlen(value) < buf->len) {
        strcpy(buf->ptr, value);
        return ZAI_ENV_SUCCESS;
    } else {
        return ZAI_ENV_BUFFER_TOO_SMALL;
    }
}
