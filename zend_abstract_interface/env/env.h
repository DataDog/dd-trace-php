#ifndef ZAI_ENV_H
#define ZAI_ENV_H

#include <zai_string/string.h>

#include <stddef.h>
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

/**
 * SAPI-only. Copies sapi_module.getenv() result into buf->ptr. Handles the
 * efree of the emalloc'd SAPI result internally — caller never frees.
 * buf must be non-NULL. buf->ptr must point to caller-owned writable storage
 * of buf->len bytes.
 * Returns ZAI_ENV_SUCCESS, ZAI_ENV_NOT_SET, etc.
 * Precondition: must be called during or after RINIT — either
 * PG(modules_activated) or PG(during_request_startup) must be non-zero.
 * Violating this (calling before any request has started) is a programming
 * error and will trigger ZAI_ASSERT.
 * On failure, there's no guarantee about the contents of buf->ptr.
 */
zai_env_result zai_sapi_getenv(zai_str name, zai_env_buffer *buf);

/**
 * System-only. Returns a zai_option_str pointing directly into process memory.
 * The pointer must not be freed or written to, and should be copied if held.
 * Should be called pre-RINIT, but it can also be called at request time if the
 * cache needs to be bypassed e.g. for OTEL env vars.
 */
static inline zai_option_str zai_sys_getenv(zai_str name) {
    ZAI_ASSERT(name.ptr && "zai_sys_getenv: detected null zai_str.ptr");
    char *value = getenv(name.ptr);
    return value ? zai_option_str_from_raw_parts(value, strlen(value)) : ZAI_OPTION_STR_NONE;
}

#endif  // ZAI_ENV_H
