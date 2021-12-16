#ifndef DD_TRACE_STARTUP_LOGGING_H
#define DD_TRACE_STARTUP_LOGGING_H

#include <php.h>
#include <stdbool.h>

#include <ext/standard/php_smart_str.h>

/* Number of config & diagnostic values */
#define DDTRACE_STARTUP_STAT_COUNT 43

/* These are the Agent connectivity timeouts for the diagnostic check on the first RINIT
 * so they should be quick to not block script execution too long.
 */
#define DDTRACE_AGENT_QUICK_TIMEOUT 500L
#define DDTRACE_AGENT_QUICK_CONNECT_TIMEOUT 100L

void ddtrace_startup_logging_first_rinit(TSRMLS_D);
void ddtrace_startup_diagnostics(HashTable *ht, bool quick);

/* Returns a json-encoded string of config/diagnostic info; caller must free.
 *     smart_str buf = {0};
 *     ddtrace_startup_logging_json(&buf);
 *     // don't forget!
 *     smart_str_free(&buf);
 */
void ddtrace_startup_logging_json(smart_str *buf);

#endif  // DD_TRACE_STARTUP_LOGGING_H
