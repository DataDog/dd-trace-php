#include "datadog-profiling.h"
#include "php_datadog-profiling.h"

#include <Zend/zend.h>
#include <Zend/zend_portability.h>
#include <main/SAPI.h>
#include <php.h>
#include <stdbool.h>
#include <uv.h>

// must come after php.h
#include <ext/standard/info.h>

#include "components/log/log.h"
#include "components/sapi/sapi.h"
#include "components/string_view/string_view.h"
#include "plugins/log_plugin/log_plugin.h"
#include "plugins/recorder_plugin/recorder_plugin.h"
#include "plugins/stack_collector_plugin/stack_collector_plugin.h"

// These type names are long, let's shorten them up
typedef datadog_php_log_level log_level_t;
typedef datadog_php_sapi sapi_t;
typedef datadog_php_string_view string_view_t;

static uv_once_t first_activate_once = UV_ONCE_INIT;

/**
 * Diagnose issues such as being unable to reach the agent.
 */
DDTRACE_COLD void datadog_profiling_diagnostics(void) {
  php_info_print_table_start();
  datadog_php_recorder_plugin_diagnose();
  php_info_print_table_end();
}

ZEND_TLS bool datadog_profiling_enabled;

/**
 * Detect whether the profiler should be enabled.
 * The profiler should be enabled for the CLI SAPI if `value` is a string value
 * for true.
 * The profiler should be enabled for other SAPIs unless `value` is a non-empty
 * string which isn't true.
 * See datadog_php_string_view_is_boolean_true to know which strings are
 * considered to be true.
 * @param value A boolean string value. May be NULL.
 * @param sapi
 * @return
 */
DDTRACE_COLD
static bool detect_profiling_enabled(const char *value, sapi_t sapi) {
  string_view_t enabled = datadog_php_string_view_from_cstr(value);
  if (datadog_php_string_view_is_boolean_true(enabled))
    return true;
  return sapi == DATADOG_PHP_SAPI_CLI ? false : enabled.len == 0;
}

DDTRACE_COLD
static void diagnose_profiling_enabled(bool enabled) {
  const char *string = NULL;

  if (enabled) {
    string = "[Datadog Profiling] Profiling is enabled.";
  } else {
    string = "[Datadog Profiling] Profiling is disabled.";
  }

  string_view_t message = {strlen(string), string};
  prof_logger.log(DATADOG_PHP_LOG_INFO, message);
}

static void sapi_diagnose(sapi_t sapi, datadog_php_string_view pretty_name) {

  switch (sapi) {
  case DATADOG_PHP_SAPI_APACHE2HANDLER:
  case DATADOG_PHP_SAPI_CLI:
  case DATADOG_PHP_SAPI_CLI_SERVER:
  case DATADOG_PHP_SAPI_CGI_FCGI:
  case DATADOG_PHP_SAPI_FPM_FCGI: {
    const char *msg = "[Datadog Profiling] Detected SAPI: ";
    string_view_t messages[3] = {
        {strlen(msg), msg},
        pretty_name,
        {1, "."},
    };
    log_level_t log_level = DATADOG_PHP_LOG_DEBUG;
    prof_logger.logv(log_level, 3, messages);
    break;
  }

  case DATADOG_PHP_SAPI_UNKNOWN:
  default: {
    const char *msg = "[Datadog Profiling] SAPI not detected: ";
    log_level_t log_level = DATADOG_PHP_LOG_WARN;
    string_view_t messages[3] = {
        {strlen(msg), msg},
        pretty_name,
        {1, "."},
    };
    prof_logger.logv(log_level, 3, messages);
  }
  }
}

DDTRACE_COLD int datadog_profiling_startup(zend_extension *extension) {
  datadog_php_stack_collector_startup(extension);

  return SUCCESS;
}

// todo: extract this common code out of log_plugin, recorder, etc
#if PHP_VERSION_ID >= 80000
#define sapi_getenv_compat(name, name_len) sapi_getenv((name), name_len)
#elif PHP_VERSION_ID >= 70000
#define sapi_getenv_compat(name, name_len) sapi_getenv((char *)(name), name_len)
#endif

static void datadog_profiling_first_activate(void) {
  sapi_t sapi = datadog_php_sapi_detect(
      datadog_php_string_view_from_cstr(sapi_module.name));

  /* sapi_getenv may or may not include process environment variables.
   * It will return NULL when it is not found in the possibly synthetic SAPI
   * environment. Hence, we need to do a getenv() in any case.
   */
  bool use_sapi_env = false;

  datadog_php_string_view env_var =
      datadog_php_string_view_from_cstr("DD_PROFILING_ENABLED");
  char *value = sapi_getenv_compat(env_var.ptr, env_var.len);
  if (value) {
    use_sapi_env = true;
  } else {
    value = getenv(env_var.ptr);
  }

  datadog_profiling_enabled = detect_profiling_enabled(value, sapi);

  if (use_sapi_env) {
    efree(value);
  }

  datadog_php_log_plugin_first_activate(datadog_profiling_enabled);

  // Logging plugin must be initialized before diagnosing things
  diagnose_profiling_enabled(datadog_profiling_enabled);
  sapi_diagnose(sapi,
                datadog_php_string_view_from_cstr(sapi_module.pretty_name));

  datadog_php_recorder_plugin_first_activate(datadog_profiling_enabled);
  datadog_php_stack_collector_first_activate(datadog_profiling_enabled);
}

void datadog_profiling_activate(void) {
  uv_once(&first_activate_once, datadog_profiling_first_activate);

  datadog_php_stack_collector_activate();
}

void datadog_profiling_deactivate(void) {
  datadog_php_stack_collector_deactivate();
}

DDTRACE_COLD void datadog_profiling_shutdown(zend_extension *extension) {
  datadog_php_recorder_plugin_shutdown(extension);
  datadog_php_log_plugin_shutdown(extension);
}

static void datadog_info_print(const char *str) {
  php_output_write(str, strlen(str));
}

DDTRACE_COLD void datadog_profiling_info_diagnostics_row(const char *col_a,
                                                         const char *col_b) {

  if (sapi_module.phpinfo_as_text) {
    php_info_print_table_row(2, col_a, col_b);
    return;
  }
  datadog_info_print("<tr><td class='e'>");
  datadog_info_print(col_a);
  datadog_info_print("</td><td class='v'>");
  datadog_info_print(col_b);
  datadog_info_print("</td></tr>\n");
}

bool datadog_php_string_view_is_boolean_true(string_view_t str) {
  size_t len = str.len;
  if (len > 0 && len < 5) {
    const char *truthy[] = {"1", "on", "yes", "true"};
    // Conveniently, by pure luck len - 1 is the index for that string.
    return memcmp(str.ptr, truthy[len - 1], len) == 0;
  } else {
    return false;
  }
}
