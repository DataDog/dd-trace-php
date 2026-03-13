#ifndef ZAI_ENV_H
#define ZAI_ENV_H

#include <zai_string/string.h>

#include <stddef.h>

/* The upper-bounds limit on the buffer size to hold the value of an arbitrary
 * environment variable.
 *
 * "The theoretical maximum length of an environment variable is around 32,760
 *  characters. However, you are unlikely to attain that theoretical maximum in
 *  practice. All environment variables must live together in a single
 *  environment block, which itself has a limit of 32767 characters."
 *
 * https://devblogs.microsoft.com/oldnewthing/20100203-00/?p=15083
 */
#define ZAI_ENV_MAX_BUFSIZ (32 * 1024)

typedef enum {
    /* The buffer 'buf' was successfully written to with the value of the target
     * environment variable 'name'.
     */
    ZAI_ENV_SUCCESS,
    /* The function is being called before the SAPI environment variables are
     * available.
     */
    ZAI_ENV_NOT_READY,
    /* The environment variable is not set. */
    ZAI_ENV_NOT_SET,
    /* The buffer is not large enough to accommodate the length of the value. */
    ZAI_ENV_BUFFER_TOO_SMALL,
    /* The buffer is bigger than the upper-bounds limit defined by
     * ZAI_ENV_MAX_BUFSIZ.
     */
    ZAI_ENV_BUFFER_TOO_BIG,
    /* API usage error. */
    ZAI_ENV_ERROR,
} zai_env_result;

typedef struct zai_env_buffer_s {
    size_t len;
    char *ptr;
} zai_env_buffer;

#define ZAI_ENV_BUFFER_INIT(name, size) \
    char name##_storage[size];          \
    zai_env_buffer name = {size, name##_storage}

/* SAPI-only. Copies sapi_module.getenv() result into buf->ptr (stack storage).
 * Handles the efree of the emalloc'd SAPI result internally — caller never frees.
 * buf must be non-NULL. buf->ptr must point to caller-owned writable storage of buf->len bytes.
 * Returns ZAI_ENV_SUCCESS, ZAI_ENV_NOT_SET, ZAI_ENV_NOT_READY, etc.
 * Requires RINIT context (modules must be activated or request startup must be in progress).
 */
zai_env_result zai_sapi_getenv(zai_str name, zai_env_buffer *buf);

/* System-only. Copies getenv() result into buf->ptr (stack storage).
 * buf must be non-NULL. buf->ptr must point to caller-owned writable storage of buf->len bytes.
 * May be called pre-RINIT (process env is always available).
 */
zai_env_result zai_sys_getenv(zai_str name, zai_env_buffer *buf);

#endif  // ZAI_ENV_H
