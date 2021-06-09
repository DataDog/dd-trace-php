#ifndef ZAI_ENV_H
#define ZAI_ENV_H

#include <stddef.h>
#include <stdint.h>

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

// TODO Move this to Zai::String
typedef struct zai_string_view_s {
    size_t len;
    const char *ptr;
} zai_string_view;

#define ZAI_STRL_VIEW(cstr) \
    { sizeof(cstr) - 1, cstr }

/* Fills 'buf.ptr' with the value of a target environment variable identified by
 * 'name'. Must be called after the SAPI envrionment variables are available
 * which is as early as module RINIT. If the active SAPI has a custom
 * environment variable handler, the SAPI handler is used to access the
 * environment variable. If there is no custom handler, the environment variable
 * is accessed from the host using getenv().
 *
 * For error conditions, a return value other than ZAI_ENV_SUCCESS is returned
 * and 'buf.ptr' is made an empty string. If the buffer size 'buf.len' is not
 * big enough to contain the value, ZAI_ENV_BUFFER_TOO_SMALL will be returned
 * and 'buf.ptr' will be an empty string; e.g. this API does not attempt to
 * truncate the value to accommodate the buffer size.
 */
zai_env_result zai_getenv(zai_string_view name, zai_env_buffer buf) __attribute__((warn_unused_result));

#define zai_getenv_literal(name, buf) zai_getenv(ZAI_STRL_VIEW(name), buf)

#endif  // ZAI_ENV_H
