/* dd_library_loader extension for PHP */

#ifndef PHP_DD_LIBRARY_LOADER_H
#define PHP_DD_LIBRARY_LOADER_H

#ifndef PHP_DD_LIBRARY_LOADER_VERSION
#define PHP_DD_LIBRARY_LOADER_VERSION "0.1.0"
#endif

#define UNUSED(x) (void)(x)

typedef enum {
    INFO,
    WARN,
    ERROR,
} log_level;

#define LOG(config, level, format, ...) ddloader_logf(config, level, format, ##__VA_ARGS__);

typedef enum {
    REASON_START,
    REASON_ERROR,
    REASON_EOL_RUNTIME,
    REASON_INCOMPATIBLE_RUNTIME,
    REASON_ALREADY_LOADED,
    REASON_COMPLETE,
} telemetry_reason;

typedef enum {
    EXT_DDTRACE,
    EXT_DATADOG_PROFILING,
    EXT_DDAPPSEC,

    EXT_COUNT,
} dd_injected_ext;

#define TELEMETRY(reason, config, error, format, ...) ddloader_telemetryf(reason, config, error, format, ##__VA_ARGS__);

#define DECLARE_INJECTED_EXT(name, dir, min_version, _pre_load_hook, _pre_minit_hook, deps)                      \
    {                                                                                               \
        .ext_name = name, .ext_dir = dir, .ext_min_version = min_version, .tmp_name = name "_injected", .tmp_deps = deps,           \
        .pre_load_hook = _pre_load_hook, .pre_minit_hook = _pre_minit_hook,                         \
        .orig_module_startup_func = NULL, .orig_module_deps = NULL, .orig_module_functions = NULL,  \
        .module_number = -1, .version = NULL,                                                       \
        .injection_success = false, .injection_error = NULL, .extra_config = {0}, .logs = {0}       \
    }

#define MAX_EXTRA_CONFIG_SIZE 1024
#define MAX_LOGS_SIZE 2048

typedef struct _injected_ext {
    const char *ext_name;
    const char *ext_dir;
    unsigned int ext_min_version;

    const char *tmp_name;
    const zend_module_dep *tmp_deps;
    char *(*pre_load_hook)(struct _injected_ext *config);
    void (*pre_minit_hook)(struct _injected_ext *config, zend_module_entry *module);

    zend_result (*orig_module_startup_func)(INIT_FUNC_ARGS);
    const zend_module_dep *orig_module_deps;
    const zend_function_entry *orig_module_functions;
    int module_number;
    char *version;

    // phpinfo data
    bool injection_success;
    const char *injection_error;
    char extra_config[MAX_EXTRA_CONFIG_SIZE];
    char logs[MAX_LOGS_SIZE];
} injected_ext;

void ddloader_logv(injected_ext *config, log_level level, const char *format, va_list va);
void ddloader_logf(injected_ext *config, log_level level, const char *format, ...);

#endif /* PHP_DD_LIBRARY_LOADER_H */
