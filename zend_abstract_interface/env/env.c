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

zai_env_result zai_getenv_ex(zai_str name, zai_env_buffer buf, bool pre_rinit) {
    if (!buf.ptr || !buf.len) return ZAI_ENV_ERROR;

    buf.ptr[0] = '\0';

    if (zai_str_is_empty(name)) return ZAI_ENV_ERROR;

    if (buf.len > ZAI_ENV_MAX_BUFSIZ) return ZAI_ENV_BUFFER_TOO_BIG;

    /* Some SAPIs do not initialize the SAPI-controlled environment variables
     * until SAPI RINIT. It is for this reason we cannot reliably access
     * environment variables until module RINIT.
     */
    if (!pre_rinit && !PG(modules_activated) && !PG(during_request_startup)) return ZAI_ENV_NOT_READY;

    /* This API intentionally only checks SAPI-managed environment values.
     * Callers that want process getenv() behavior must call getenv() directly.
     */
    char *value = sapi_getenv_compat(name.ptr, name.len);
    if (!value) return ZAI_ENV_NOT_SET;

    zai_env_result res;

    if (strlen(value) < buf.len) {
        strcpy(buf.ptr, value);
        res = ZAI_ENV_SUCCESS;
    } else {
        res = ZAI_ENV_BUFFER_TOO_SMALL;
    }

    efree(value);

    return res;
}
