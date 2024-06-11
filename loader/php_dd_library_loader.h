/* dd_library_loader extension for PHP */

#ifndef PHP_DD_LIBRARY_LOADER_H
#define PHP_DD_LIBRARY_LOADER_H

#define PHP_DD_LIBRARY_LOADER_VERSION "0.1.0"

typedef enum {
    INFO,
    WARN,
    ERROR,
} log_level;

#define LOG(level, format, ...) ddloader_logf(level, format, ##__VA_ARGS__);

typedef enum {
    REASON_ERROR,
    REASON_EOL_RUNTIME,
    REASON_INCOMPATIBLE_RUNTIME,
    REASON_COMPLETE,
} telemetry_reason;

#define TELEMETRY(reason, format, ...) ddloader_telemetryf(reason, format, ##__VA_ARGS__);


#define DECLARE_INJECTED_EXT(name, dir, deps) {.ext_name = name, .ext_dir = dir, .tmp_name = name "_injected", .tmp_deps = deps}

typedef struct _injected_ext {
    const char *ext_name;
    const char *ext_dir;

    const char *tmp_name;
    const zend_module_dep *tmp_deps;

    zend_result (*orig_module_startup_func)(INIT_FUNC_ARGS);
    const zend_module_dep *orig_module_deps;
    const zend_function_entry *orig_module_functions;
    int module_number;
    char *version;
} injected_ext;

#endif /* PHP_DD_LIBRARY_LOADER_H */
