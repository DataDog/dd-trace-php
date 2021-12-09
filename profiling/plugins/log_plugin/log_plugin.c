#include "log_plugin.h"

#include <SAPI.h>
#include <Zend/zend.h>
#include <ddtrace_attributes.h>
#include <pthread.h>
#include <stdlib.h>
#include <unistd.h>

typedef datadog_php_logger logger_t;
typedef datadog_php_log_level log_level_t;
typedef datadog_php_string_view string_view_t;

static logger_t profiler_logger = DATADOG_PHP_LOGGER_INIT;
static pthread_mutex_t logger_mutex;

// Use noop log pattern to avoid initialization issues {{{
static void noop_log(datadog_php_log_level ll, datadog_php_string_view sv) {
  (void)ll, (void)sv;
}

static int64_t noop_logv(datadog_php_log_level level, size_t n_messages,
                         datadog_php_string_view messages[static n_messages]) {
  (void)level, (void)n_messages, (void)messages;
  return -1;
}

void noop_log_cstr(datadog_php_log_level log_level, const char *cstr) {
  (void)log_level, (void)cstr;
}

datadog_php_static_logger prof_logger = {
    .log = noop_log,
    .logv = noop_logv,
    .log_cstr = noop_log_cstr,
}; // }}}

static void datadog_php_log_plugin_log(log_level_t log_level,
                                       string_view_t message) {
  datadog_php_log(&profiler_logger, log_level, message);
}

/**
 * @param log_level
 * @param cstr Must not be null.
 */
static void datadog_php_log_plugin_log_cstr(datadog_php_log_level log_level,
                                            const char *cstr) {
  string_view_t message = {strlen(cstr), cstr};
  datadog_php_log(&profiler_logger, log_level, message);
}

static int64_t datadog_php_log_plugin_logv(
    datadog_php_log_level level, size_t n_messages,
    datadog_php_string_view messages[static n_messages]) {
  return datadog_php_logv(&profiler_logger, level, n_messages, messages);
}

// todo: extract this to a plugin?
#if PHP_VERSION_ID >= 80000
#define sapi_getenv_compat(name, name_len) sapi_getenv((name), name_len)
#elif PHP_VERSION_ID >= 70000
#define sapi_getenv_compat(name, name_len) sapi_getenv((char *)(name), name_len)
#else
#define sapi_getenv_compat(name, name_len)                                     \
  sapi_getenv((char *)(name), name_len TSRMLS_CC)
#endif

static DDTRACE_COLD void datadog_php_log_plugin_init(void) {
  int descriptor = dup(STDERR_FILENO);
  if (descriptor < 1) {
    return;
  }

  pthread_mutexattr_t mutex_attr;
  if (pthread_mutexattr_init(&mutex_attr) != 0) {
    goto startup_descriptor_cleanup;
  }
  if (pthread_mutexattr_settype(&mutex_attr, PTHREAD_MUTEX_ERRORCHECK) != 0) {
    goto startup_descriptor_cleanup;
  }
  if (pthread_mutex_init(&logger_mutex, &mutex_attr) != 0) {
    goto startup_descriptor_cleanup;
  }

  string_view_t env_var =
      datadog_php_string_view_from_cstr("DD_PROFILING_LOG_LEVEL");

  char *env = sapi_getenv_compat(env_var.ptr, env_var.len);
  bool uses_sapi_getenv = true;
  if (!env) {
    uses_sapi_getenv = false;
    env = getenv("DD_PROFILING_LOG_LEVEL");
  }

  string_view_t env_val = datadog_php_string_view_from_cstr(env);
  log_level_t log_level = datadog_php_log_level_detect(env_val);

  log_level_t corrected_log_level =
      log_level != DATADOG_PHP_LOG_UNKNOWN ? log_level : DATADOG_PHP_LOG_OFF;

  if (uses_sapi_getenv) {
    efree(env);
  }

  if (!datadog_php_logger_ctor(&profiler_logger, descriptor,
                               corrected_log_level, &logger_mutex)) {
    datadog_php_logger_dtor(&profiler_logger);
    goto cleanup_mutex;
  }

  prof_logger = (datadog_php_static_logger){
      .log = datadog_php_log_plugin_log,
      .logv = datadog_php_log_plugin_logv,
      .log_cstr = datadog_php_log_plugin_log_cstr,
  };
  return;

cleanup_mutex:
  pthread_mutex_destroy(&logger_mutex);

startup_descriptor_cleanup:
  close(descriptor);
}

void datadog_php_log_plugin_first_activate(bool profiling_enabled) {
  if (!profiling_enabled)
    return;

  datadog_php_log_plugin_init();
}

DDTRACE_COLD void datadog_php_log_plugin_shutdown(zend_extension *extension) {
  (void)extension;

  if (profiler_logger.descriptor >= 0)
    close(profiler_logger.descriptor);

  if (profiler_logger.mutex)
    pthread_mutex_destroy(profiler_logger.mutex);

  datadog_php_logger_dtor(&profiler_logger);
}
