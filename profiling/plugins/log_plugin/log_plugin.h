#ifndef DATADOG_PHP_LOG_PLUGIN_H
#define DATADOG_PHP_LOG_PLUGIN_H

#include "datadog-profiling.h"
#include <components/log/log.h>
#include <components/string-view/string-view.h>

#if __cplusplus
#define C_STATIC(...)
#else
#define C_STATIC(...) static __VA_ARGS__
#endif

void datadog_php_log_plugin_first_activate(bool profiling_enabled);
void datadog_php_log_plugin_shutdown(zend_extension *extension);

/* It's called a "static logger" because the logger isn't passed as a parameter,
 * so somewhere there must be a static object holding onto it.
 */
typedef struct datadog_php_static_logger_s {
  void (*log)(datadog_php_log_level, datadog_php_string_view);
  int64_t (*logv)(datadog_php_log_level level, size_t n_messages,
                  datadog_php_string_view messages[C_STATIC(n_messages)]);
  void (*log_cstr)(datadog_php_log_level log_level, const char *cstr);
} datadog_php_static_logger;

extern datadog_php_static_logger prof_logger;

#undef C_STATIC

#endif // DATADOG_PHP_LOG_PLUGIN_H
