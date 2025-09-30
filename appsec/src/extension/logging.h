// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "configuration.h"
#include "php_helpers.h"
#include <stdbool.h>
#include "attributes.h"

/* log levels - the first argument to the mlog helper
 * The lower the number, the higher the priority */
typedef enum {
    dd_log_off,
    dd_log_fatal,
    dd_log_error,
    dd_log_warning,
    dd_log_info,
    dd_log_debug,
    dd_log_trace,
} dd_log_level_t;

// these are only in the header to support the macros/inline functions
extern const int _dd_size_source_prefix;
#ifdef ZTS
#    define STRERROR_R_BUF_SIZE 1024
extern __thread char _dd_strerror_buf[STRERROR_R_BUF_SIZE];
#endif

static inline dd_log_level_t dd_log_level(void)
{
    return (dd_log_level_t)(runtime_config_first_init ? get_DD_APPSEC_LOG_LEVEL()
                                                      : get_global_DD_APPSEC_LOG_LEVEL());
}

void dd_log_startup(void);
void dd_log_shutdown(void);
const char *nonnull _strerror_r(int err, char *nonnull buf, size_t buflen);

bool dd_parse_log_level(
    zai_str value, zval *nonnull decoded_value, bool persistent);

void _mlog_relay(dd_log_level_t level, const char *nonnull format,
    const char *nonnull file, const char *nonnull function, int line, ...)
    ATTR_FORMAT(2, 6);
#define mlog(level, format, ...)                                               \
    _mlog_relay((level), (format),                                             \
        (const char *)__FILE__ + _dd_size_source_prefix, __func__, __LINE__,   \
        ##__VA_ARGS__)

#define mlog_err(level, format, ...)                                           \
    do {                                                                       \
        int _orig_errno = errno;                                               \
        const char *_err_str = _strerror(errno);                               \
        _mlog_relay(level, format ": %s",                                      \
            (const char *)__FILE__ + _dd_size_source_prefix, __func__,         \
            __LINE__, ##__VA_ARGS__, _err_str);                                \
        errno = _orig_errno;                                                   \
    } while (0)

// guarded version, for performance
#define mlog_g(level, format, ...)                                             \
    do {                                                                       \
        if (dd_log_level() >= level) {                                         \
            mlog(level, format, ##__VA_ARGS__);                                \
        }                                                                      \
    } while (0)

static ATTR_ALWAYS_INLINE bool mlog_should_log(dd_log_level_t lvl)
{
    return dd_log_level() >= lvl;
}

// Do not call directly; only for support to mlog_err
static ATTR_ALWAYS_INLINE const char *nonnull _strerror(int errnum)
{
#ifdef ZTS
    return _strerror_r(errnum, _dd_strerror_buf, sizeof(_dd_strerror_buf));
#else
    return strerror(errnum);
#endif
}
