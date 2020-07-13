#ifndef DD_TRACE_STARTUP_LOGGING_H
#define DD_TRACE_STARTUP_LOGGING_H

#include <php.h>

#if PHP_VERSION_ID >= 70000
#include <Zend/zend_smart_str.h>
#else
#include <ext/standard/php_smart_str.h>
#endif

#define DDTRACE_STARTUP_STAT_COUNT 43  // Number of config & diagnostic values

void ddtrace_startup_logging_startup(void);
void ddtrace_startup_diagnostics(HashTable *ht);

/* Returns a json-encoded string of config/diagnostic info; caller must free.
 *     smart_str buf = {0};
 *     ddtrace_startup_logging_json(&buf);
 *     // don't forget!
 *     smart_str_free(&buf);
 */
void ddtrace_startup_logging_json(smart_str *buf);

#endif  // DD_TRACE_STARTUP_LOGGING_H
