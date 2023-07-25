#ifndef COMPONENT_LOG_H
#define COMPONENT_LOG_H

#include "../../components-rs/ddtrace.h"

extern __thread ddog_Log _ddog_log_source_value;
void ddog_logf(ddog_Log source, const char *format, ...);
void _ddog_log_source(const char *format, ...);

#define LOG(source, format, ...) do { if (ddog_shall_log(ddog_Log_##source)) { ddog_logf(ddog_Log_##source, format, ##__VA_ARGS__); } } while (0)
#define LOG_ONCE(source, format, ...) do { if (ddog_shall_log(ddog_Log_##source)) { ddog_logf((ddog_Log){ .bits = (ddog_Log_##source).bits | (ddog_Log_Once).bits }, format, ##__VA_ARGS__); } } while (0)
#define LOGEV(source, body) { \
    if (ddog_shall_log(ddog_Log_##source)) { \
        _ddog_log_source_value = ddog_Log_##source; \
        void (*log)(const char *format, ...) = _ddog_log_source; \
        body \
    } \
}

#define LOG_UNREACHABLE(message) \
    do {                                  \
        const char *message_ = message;   \
        ZEND_ASSERT(0 && message_);       \
        LOG(Error, message_);      \
    } while (0)

#define LOG_LINE(source, format, ...) \
    do { \
        if (ddog_shall_log(ddog_Log_##source)) { \
            ddog_logf(ddog_Log_##source, format " in %s on line %d", ##__VA_ARGS__, zend_get_executed_filename(), (int)zend_get_executed_lineno()); \
        } \
    } while (0)
#define LOG_LINE_ONCE(source, format, ...) \
    do { \
        if (ddog_shall_log(ddog_Log_##source)) { \
            ddog_logf((ddog_Log){ .bits = (ddog_Log_##source).bits | (ddog_Log_Once).bits }, format " in %s on line %d", ##__VA_ARGS__, zend_get_executed_filename(), (int)zend_get_executed_lineno()); \
        } \
    } while (0)

#endif // COMPONENT_LOG_H
