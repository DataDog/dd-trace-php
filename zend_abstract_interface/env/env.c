#include "../tsrmls_cache.h"
#include <main/SAPI.h>
#include <main/php.h>
#include <stdlib.h>

#include "env.h"

#if PHP_VERSION_ID >= 80000
#define sapi_getenv_compat(name, name_len) sapi_getenv(name, name_len)
#else
#define sapi_getenv_compat(name, name_len) sapi_getenv((char *)name, name_len)
#endif

zai_env_result zai_getenv_ex(zai_str name, zai_env_buffer *buf, bool pre_rinit, bool use_process_env) {
    if (!buf || !buf->ptr || !buf->len) return ZAI_ENV_ERROR;

    char *scratch = buf->ptr;
    size_t scratch_len = buf->len;

    if (zai_str_is_empty(name)) return ZAI_ENV_ERROR;

    if (scratch_len > ZAI_ENV_MAX_BUFSIZ) return ZAI_ENV_BUFFER_TOO_BIG;

    /* Some SAPIs do not initialize the SAPI-controlled environment variables
     * until SAPI RINIT. It is for this reason we cannot reliably access
     * environment variables until module RINIT.
     */
    if (!pre_rinit && !PG(modules_activated) && !PG(during_request_startup)) return ZAI_ENV_NOT_READY;

    /* sapi_getenv may or may not include process environment variables.
     * It will return NULL when it is not found in the possibly synthetic SAPI environment.
     */
    char *sapi_value = sapi_getenv_compat(name.ptr, name.len);
    if (sapi_value) {
        size_t sapi_value_len = strlen(sapi_value);
        zai_env_result res;
        if (sapi_value_len < scratch_len) {
            memcpy(scratch, sapi_value, sapi_value_len + 1);
            buf->ptr = scratch;
            buf->len = sapi_value_len;
            res = ZAI_ENV_SUCCESS;
        } else {
            res = ZAI_ENV_BUFFER_TOO_SMALL;
        }
        efree(sapi_value);
        return res;
    }

    if (!use_process_env) return ZAI_ENV_NOT_SET;

    char *process_value = getenv(name.ptr);
    if (!process_value) return ZAI_ENV_NOT_SET;

    size_t process_value_len = strlen(process_value);
    if (process_value_len < scratch_len) {
        memcpy(scratch, process_value, process_value_len + 1);
        buf->ptr = scratch;
        buf->len = process_value_len;
        return ZAI_ENV_SUCCESS;
    }
    return ZAI_ENV_BUFFER_TOO_SMALL;
}
