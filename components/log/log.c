#include "log.h"

#include <errno.h>
#include <string.h>
#include <unistd.h>

// The names are long, so let's add a convenience helpers
typedef datadog_php_logger logger_t;
typedef datadog_php_log_level log_level_t;
typedef datadog_php_string_view string_view_t;

/**
 * Ensures the logger looks like it should be logging anything at all.
 *
 * # Safety
 * Mutex must exist and be locked prior to calling this function if used in a
 * multi-threaded context.
 * `logger` must not be null.
 */
__attribute__((nonnull)) static bool logger_valid(datadog_php_logger *logger) {
    return logger->descriptor >= 0 && logger->log_level >= DATADOG_PHP_LOG_OFF &&
           logger->log_level <= DATADOG_PHP_LOG_DEBUG;
}

__attribute__((nonnull)) bool datadog_php_logger_ctor(logger_t *logger, int descriptor, log_level_t log_level,
                                                      pthread_mutex_t *mutex) {
    *logger = (logger_t){
        .descriptor = descriptor < 0 ? -1 : descriptor,  // normalize invalid
        .log_level = log_level,
        .mutex = mutex,
    };
    return logger_valid(logger);
}

void datadog_php_logger_dtor(logger_t *logger) {
    logger->descriptor = -1;
    logger->log_level = DATADOG_PHP_LOG_UNKNOWN;
    logger->mutex = NULL;
}

static int64_t durable_write(const logger_t *logger, string_view_t message) {
    /* We attempt to handle some of the error conditions possible:
     *  1. Partial writes
     *  2. EAGAIN and EWOULDBLOCK
     *  3. EINTR
     * We limit the number of times we'll retry an operation for the above.
     * For other errors we stop attempting to log and move on.
     * todo: should we set the fd to -1 for some errors like EIO/EPIPE?
     */
    size_t written = 0;
    unsigned tries = 0, max_tries = 3;  // arbitrarily chosen
    while (written < message.len && tries++ < max_tries) {
        ssize_t n = write(logger->descriptor, message.ptr + written, message.len - written);
        if (n < 0) {
            /* POSIX.1-2001 allows either error to be returned for this case, and does
             * not require these constants to have the same value, so a portable
             * application should check for both possibilities.
             */
            if (errno == EAGAIN || errno == EWOULDBLOCK || errno == EINTR) {
                continue;
            } else {
                break;
            }
        }
        written += n;
    }
    return written == message.len ? (int64_t)written : -1;
}

static int64_t log_writev(logger_t *logger, log_level_t level, size_t n_messages,
                          string_view_t messages[static n_messages]) {
    if (logger->log_level < level) {
        return 0;
    }

    int64_t written = 0;
    for (size_t i = 0; i != n_messages; ++i) {
        int64_t result = durable_write(logger, messages[i]);
        if (result < 0) break;
        written += result;
    }

    // try to append a newline
    ssize_t result = write(logger->descriptor, "\n", 1);
    if (result > 0) written += result;
    return written;
}

void datadog_php_log(logger_t *logger, log_level_t level, string_view_t message) {
    (void)datadog_php_logv(logger, level, 1, &message);
}

int64_t datadog_php_logv(datadog_php_logger *logger, datadog_php_log_level level, size_t n_messages,
                         datadog_php_string_view messages[static n_messages]) {
    if (logger->mutex && pthread_mutex_lock(logger->mutex) == 0) {
        int64_t result = logger_valid(logger) ? log_writev(logger, level, n_messages, messages) : -1;
        (void)pthread_mutex_unlock(logger->mutex);
        return result;
    }
    return -1;
}

static char datadog_tolower_ascii(char c) { return (char)(c >= 'A' && c <= 'Z' ? (c - ('A' - 'a')) : c); }

static void datadog_copy_tolower(char *restrict dst, const char *restrict src, size_t len) {
    for (size_t i = 0; i != len; ++i) {
        *(dst++) = datadog_tolower_ascii(src[i]);
    }
}

log_level_t datadog_php_log_level_detect(string_view_t val) {
    // Treat nullptr and a string of length 0 the same: default to off.
    if (!val.ptr || val.len == 0) return DATADOG_PHP_LOG_OFF;

    // If the level string's length is greater than 5, it's definitely unknown.
    if (val.len > 5) return DATADOG_PHP_LOG_UNKNOWN;

    // 8 chars is more than enough to hold the remaining strings.
    _Alignas(8) char buffer[8] = {0, 0, 0, 0, 0, 0, 0, 0};

    // lowercase to make this a case-insensitive operation
    datadog_copy_tolower(buffer, val.ptr, val.len);
    string_view_t level_string = {.len = val.len, .ptr = buffer};

    struct {
        string_view_t str;
        log_level_t level;
    } options[] = {
        {{3, "off"}, DATADOG_PHP_LOG_OFF},
        {{5, "error"}, DATADOG_PHP_LOG_ERROR},
        {{4, "warn"}, DATADOG_PHP_LOG_WARN},
        {{4, "info"}, DATADOG_PHP_LOG_INFO},
        {{5, "debug"}, DATADOG_PHP_LOG_DEBUG},

        /* This case goes last. We've already ruled out this case above; this is
         * for unifying known and unknown code paths below.
         */
        {{0, ""}, DATADOG_PHP_LOG_UNKNOWN},
    };

    // the last option is only used for its enum, so take off 1
    unsigned i, n_options = (sizeof options / sizeof *options) - 1;
    for (i = 0; i != n_options; ++i) {
        if (datadog_php_string_view_equal(options[i].str, level_string)) {
            break;
        }
    }
    return options[i].level;
}

/**
 * Sets the current log level of the application.
 */
void datadog_php_log_level_set(logger_t *logger, log_level_t level) {
    if (logger->mutex && pthread_mutex_lock(logger->mutex) == 0) {
        logger->log_level = level;
        pthread_mutex_unlock(logger->mutex);
    }
}
