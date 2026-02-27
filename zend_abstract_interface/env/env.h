#ifndef ZAI_ENV_H
#define ZAI_ENV_H

#include <zai_string/string.h>

#include <stdbool.h>
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

/* Resolves a target environment variable identified by 'name'. Must be called
 * after the SAPI envrionment variables are available
 * which is as early as module RINIT. If the active SAPI has a custom
 * environment variable handler, the SAPI handler is used to access the
 * environment variable. If there is no custom handler, the environment variable
 * is accessed from the host using getenv(), unless use_process_env is false.
 *
 * For SAPI values, this writes into the caller scratch buffer (`buf->ptr`).
 * For process getenv() values, this may repoint `buf->ptr` to borrowed process
 * env storage to avoid a temporary copy.
 *
 * For error conditions, a return value other than ZAI_ENV_SUCCESS is returned.
 * No output-buffer contents are guaranteed on failure. If callers want an
 * empty C-string on failure, they should initialize `buf->ptr[0] = '\\0'`
 * before calling this API (when `buf->len > 0`).
 */
zai_env_result zai_getenv_ex(zai_str name, zai_env_buffer *buf, bool pre_rinit, bool use_process_env);
static inline zai_env_result zai_getenv(zai_str name, zai_env_buffer *buf) {
    return zai_getenv_ex(name, buf, false, true);
}

#define zai_getenv_literal(name, buf) zai_getenv(ZAI_STRL(name), &(buf))

#endif  // ZAI_ENV_H
