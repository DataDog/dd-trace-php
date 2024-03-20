#ifndef COMPONENT_LOG_H
#define COMPONENT_LOG_H

#include "../../components-rs/ddtrace.h"

#ifndef _WIN32
extern __thread ddog_Log _ddog_log_source_value;
#else
extern __declspec(thread) ddog_Log _ddog_log_source_value;
#endif
void ddog_logf(ddog_Log source, bool once, const char *format, ...);
void _ddog_log_source(const char *format, ...);

#define LOG(source, format, ...) do { if (ddog_shall_log(DDOG_LOG_##source)) { ddog_logf(DDOG_LOG_##source, (DDOG_LOG_##source & ddog_LOG_ONCE) != 0, format, ##__VA_ARGS__); } } while (0)
#define LOG_ONCE(source, format, ...) do { if (ddog_shall_log(DDOG_LOG_##source)) { ddog_logf(DDOG_LOG_##source, true, format, ##__VA_ARGS__); } } while (0)
#define LOGEV(source, body) { \
    if (ddog_shall_log(DDOG_LOG_##source)) { \
        _ddog_log_source_value = DDOG_LOG_##source; \
        void (*log)(const char *format, ...) = _ddog_log_source; \
        body \
    } \
}

#define LOG_UNREACHABLE(message) \
    do {                                  \
        const char *message_ = message;   \
        ZEND_ASSERT(0 && message_);       \
        LOG(ERROR, message_);      \
    } while (0)

#define LOG_LINE(source, format, ...) \
    do { \
        if (ddog_shall_log(DDOG_LOG_##source)) { \
            ddog_logf(DDOG_LOG_##source, (DDOG_LOG_##source & ddog_LOG_ONCE) != 0, format " in %s on line %d", ##__VA_ARGS__, zend_get_executed_filename(), (int)zend_get_executed_lineno()); \
        } \
    } while (0)
#define LOG_LINE_ONCE(source, format, ...) \
    do { \
        if (ddog_shall_log(DDOG_LOG_##source)) { \
            ddog_logf(DDOG_LOG_##source, true, format " in %s on line %d", ##__VA_ARGS__, zend_get_executed_filename(), (int)zend_get_executed_lineno()); \
        } \
    } while (0)

#endif // COMPONENT_LOG_H
