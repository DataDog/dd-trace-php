#ifndef ZAI_ENV_H
#define ZAI_ENV_H

#include <zai_string/string.h>

#include <stdlib.h>
#include <string.h>

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
    char name##_storage[size] = {0};    \
    zai_env_buffer name = {size, name##_storage}

/**
 * Borrows the environment variable from the SAPI--it will not check the
 * system environment variables.
 *
 * This mostly only makes sense to use during a request.
 */
zai_option_str zai_sapi_getenv(zai_str name);

/**
 * Borrows the environment variable from the system--it will not check the
 * SAPI environment variables.
 */
static inline zai_option_str zai_sys_getenv(zai_str name) {
    char *value = getenv(name.ptr);
    if (value) {
        return zai_option_str_from_raw_parts(value, strlen(value));
    }
    return ZAI_OPTION_STR_NONE;
}

#endif  // ZAI_ENV_H
