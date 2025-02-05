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

#define LOG(level, format, ...) ddloader_logf(level, format, ##__VA_ARGS__);

void ddloader_logv(log_level level, const char *format, va_list va);
void ddloader_logf(log_level level, const char *format, ...);

typedef enum {
    REASON_START,
    REASON_ERROR,
    REASON_EOL_RUNTIME,
    REASON_INCOMPATIBLE_RUNTIME,
    REASON_ALREADY_LOADED,
    REASON_COMPLETE,
} telemetry_reason;

#define TELEMETRY(reason, config, error, format, ...) ddloader_telemetryf(reason, config, error, format, ##__VA_ARGS__);

#define DECLARE_INJECTED_EXT(name, dir, _pre_load_hook, _pre_minit_hook, deps)                      \
    {                                                                                               \
        .ext_name = name, .ext_dir = dir, .tmp_name = name "_injected", .tmp_deps = deps,           \
        .pre_load_hook = _pre_load_hook, .pre_minit_hook = _pre_minit_hook,                         \
        .orig_module_startup_func = NULL, .orig_module_deps = NULL, .orig_module_functions = NULL,  \
        .module_number = -1, .version = NULL                                                        \
    }

typedef struct _injected_ext {
    const char *ext_name;
    const char *ext_dir;

    const char *tmp_name;
    const zend_module_dep *tmp_deps;
    char *(*pre_load_hook)(void);
    void (*pre_minit_hook)(void);

    zend_result (*orig_module_startup_func)(INIT_FUNC_ARGS);
    const zend_module_dep *orig_module_deps;
    const zend_function_entry *orig_module_functions;
    int module_number;
    char *version;
} injected_ext;

#endif /* PHP_DD_LIBRARY_LOADER_H */
