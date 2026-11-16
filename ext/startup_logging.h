#ifndef DATADOG_STARTUP_LOGGING_H
#define DATADOG_STARTUP_LOGGING_H

#include <Zend/zend_smart_str.h>
#include <php.h>
#include <stdbool.h>

/* Number of config & diagnostic values */
#define DATADOG_STARTUP_STAT_COUNT 64

/* These are the Agent connectivity timeouts for the diagnostic check on the first RINIT
 * so they should be quick to not block script execution too long.
 */
#define DATADOG_AGENT_QUICK_TIMEOUT 500L
#define DATADOG_AGENT_QUICK_CONNECT_TIMEOUT 100L

void datadog_startup_logging_first_rinit(void);
void datadog_startup_diagnostics(HashTable *ht, bool quick);

/* Returns a json-encoded string of config/diagnostic info; caller must free.
 *     smart_str buf = {0};
 *     datadog_startup_logging_json(&buf);
 *     // don't forget!
 *     smart_str_free(&buf);
 */
void datadog_startup_logging_json(smart_str *buf, int options);

#endif  // DATADOG_STARTUP_LOGGING_H
