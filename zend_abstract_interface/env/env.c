#include "env.h"

#include <main/SAPI.h>
#include <main/php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#if PHP_VERSION_ID >= 80000
#define sapi_getenv_compat(name, name_len) sapi_getenv(name, name_len)
#elif PHP_VERSION_ID >= 70000
#define sapi_getenv_compat(name, name_len) sapi_getenv((char *)name, name_len)
#else
#define sapi_getenv_compat(name, name_len) sapi_getenv((char *)name, name_len TSRMLS_CC)
#endif

zai_env_result zai_getenv(const char *name, size_t name_len, char *buf, size_t buf_size) {
#if PHP_VERSION_ID < 70000
    TSRMLS_FETCH();
#endif
    if (!buf || !buf_size) return ZAI_ENV_ERROR;

    buf[0] = '\0';

    if (!name || !name_len) return ZAI_ENV_ERROR;

    /* Some SAPIs do not initialize the SAPI-controlled environment variables
     * until the request has started. It is for this reason we cannot reliably
     * access environment variables outside of a request context.
     */
    if (!PG(modules_activated)) return ZAI_ENV_NOT_READY;

    /* If 'sapi_module.getenv' is not set, sapi_getenv() will return NULL; but a
     * NULL return value could also mean that the target environment variable
     * does not exist. To distinguish these two paths we need to check
     * 'sapi_module.getenv' here and fall back to getenv() only if the SAPI does
     * not have special environment variable handling.
     */
    bool use_sapi_env = (bool)sapi_module.getenv;

    char *value = use_sapi_env ? sapi_getenv_compat(name, name_len) : getenv(name);
    if (!value) return ZAI_ENV_NOT_SET;

    zai_env_result res;

    if (strlen(value) < buf_size) {
        strcpy(buf, value);
        res = ZAI_ENV_SUCCESS;
    } else {
        res = ZAI_ENV_BUFFER_TOO_SMALL;
    }

    if (use_sapi_env) efree(value);

    return res;
}
