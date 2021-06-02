#ifndef ZAI_ENV_H
#define ZAI_ENV_H

#include <stddef.h>

typedef enum {
    /* The buffer 'buf' was successfully written to with the value of the target
     * environment variable 'name'.
     */
    ZAI_ENV_SUCCESS,
    /* The function is being called outside of a request context. */
    ZAI_ENV_NOT_READY,
    /* The environment variable is not set. */
    ZAI_ENV_NOT_SET,
    /* The buffer is not large enough to accommodate the length of the value. */
    ZAI_ENV_BUFFER_TOO_SMALL,
    /* API usage error. */
    ZAI_ENV_ERROR,
} zai_env_result;

/* Fills 'buf' with the value of a target environment variable identified by
 * 'name'. Must be called within a request context. If the active SAPI has a
 * custom environment variable handler, the SAPI handler is used to access the
 * environment variable. If there is no custom handler, the environment variable
 * is accessed from the host using getenv().
 *
 * For error conditions, a return value other than ZAI_ENV_SUCCESS is returned
 * and 'buf' is made an empty string. If the buffer size 'buf_size' is not big
 * enough to contain the value, ZAI_ENV_BUFFER_TOO_SMALL will be returned and
 * 'buf' will be an empty string; e.g. this API does not attempt to truncate
 * the value to accommodate the buffer size.
 */
zai_env_result zai_getenv(const char *name, size_t name_len, char *buf, size_t buf_size);

#endif  // ZAI_ENV_H
