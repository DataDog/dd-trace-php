#ifndef DATADOG_PHP_PROFILER_LOG_H
#define DATADOG_PHP_PROFILER_LOG_H

#include <components/string_view/string_view.h>
#include <pthread.h>
#include <stdint.h>

#if __cplusplus
#define C_STATIC(...)
#else
#define C_STATIC(...) static __VA_ARGS__
#endif

typedef enum {
    DATADOG_PHP_LOG_UNKNOWN = -1,
    DATADOG_PHP_LOG_OFF = 0,
    DATADOG_PHP_LOG_ERROR,
    DATADOG_PHP_LOG_WARN,
    DATADOG_PHP_LOG_INFO,
    DATADOG_PHP_LOG_DEBUG,
} datadog_php_log_level;

// This is for the compiler to use; please do not use it directly!
typedef struct datadog_php_logger_s {
    int descriptor;  // borrowed, not owned
    int log_level;
    pthread_mutex_t *mutex;  // borrowed, not owned
} datadog_php_logger;

#define DATADOG_PHP_LOGGER_INIT \
    { -1, DATADOG_PHP_LOG_UNKNOWN, NULL }

/**
 * Creates a logger instance in `logger`. The result may be valid or invalid;
 * check the return value: `true` for valid.
 *
 * For now, only file descriptors are expected to work, but other types are
 * expected to work in the future.
 */
__attribute__((nonnull)) bool datadog_php_logger_ctor(datadog_php_logger *logger, int descriptor,
                                                      datadog_php_log_level log_level, pthread_mutex_t *mutex);

/**
 * Sets the .descriptor to -1, .log_level to `DATADOG_PROFILER_LOG_UNKNOWN`,
 * and the .mutex to NULL.
 *
 * This is not threadsafe! It also doesn't close the descriptor nor destroy the
 * mutex, as they are borrowed, not owned. Only do this when the program is
 * back in single-threaded mode, or you risk a race condition.
 *
 * @param logger
 */
void datadog_php_logger_dtor(datadog_php_logger *logger);

/**
 * If the `logger` is set to at least `level`, then the message will be logged.
 * Otherwise it will be ignored.
 * @param logger
 * @param level
 * @param message
 */
void datadog_php_log(datadog_php_logger *logger, datadog_php_log_level level, datadog_php_string_view message);

/**
 * If the `logger` is set to at least `level`, then the messages will be logged.
 * Otherwise they will be ignored.
 * @param logger
 * @param level
 * @param n_messages
 * @param messages
 * @return Bytes written
 */
int64_t datadog_php_logv(datadog_php_logger *logger, datadog_php_log_level level, size_t n_messages,
                         datadog_php_string_view messages[C_STATIC(n_messages)]);

/**
 * Sets the current `.log_level` of the logger.
 * Inputs of UNKNOWN are ignored, and the logger retain its current values.
 *
 * Generally the `.log_level` should be set at construction and remain
 * unchanged. However, there may be reasons to change it. Here are a few:
 *   - A logger may need to be be constructed so it can be shared by multiple
 *     objects prior to knowing the runtime setting of the log level.
 *   - At a certain point during datadog_php_log_plugin_shutdown, logging may
 * need to be set to off but since the logger is still being borrowed by other
 * objects it cannot be destructed.
 */
void datadog_php_log_level_set(datadog_php_logger *, datadog_php_log_level);

/**
 * Converts `val` to a recognized log level.
 * Returns `DATADOG_PROFILER_LOG_UNKNOWN` if it does not recognize a level.
 * Accepted strings are the following, comparison is case insensitive:
 *   "" (defaults to off), "off", "error", "warn", "info", "debug"
 * @param val
 * @return The detected log_level (may be DATADOG_PROFILER_LOG_UNKNOWN).
 */
datadog_php_log_level datadog_php_log_level_detect(datadog_php_string_view val);

#undef C_STATIC

#endif  // DATADOG_PHP_PROFILER_LOG_H
