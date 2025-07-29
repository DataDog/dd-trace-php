/* dd_library_loader extension for PHP */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <Zend/zend_extensions.h>
#include <php.h>
#include <php_ini.h>
#include <stdbool.h>
#include <errno.h>
#include <main/SAPI.h>
#include <ext/standard/basic_functions.h>

#include "compat_php.h"
#include "php_dd_library_loader.h"

#define MIN_API_VERSION 320151012
#define MAX_API_VERSION 420240924
#define MAX_INI_API_VERSION MAX_API_VERSION + 1

#define PHP_70_VERSION 20151012
#define PHP_71_VERSION 20160303
#define PHP_72_VERSION 20170718
#define PHP_80_VERSION 20200930

#define MIN_PHP_VERSION "7.0"
#define MAX_PHP_VERSION "8.4"

extern zend_module_entry dd_library_loader_mod;

static bool debug_logs = false;
static bool force_load = false;
static char *telemetry_forwarder_path = NULL;
static char *package_path = NULL;

static unsigned int php_api_no = 0;
static const char *runtime_version = "unknown";
static bool injection_forced = false;

static bool already_done = false;

#if defined(__MUSL__)
# define OS_PATH "linux-musl/"
#else
# define OS_PATH "linux-gnu/"
#endif

static ZEND_INI_MH(ddloader_OnUpdateForceInject) {
    (void)entry;
    (void)mh_arg1;
    (void)mh_arg2;
    (void)mh_arg3;
    (void)stage;

    if (!force_load) {
        force_load = ddloader_zend_ini_parse_bool(new_value);
    }
    return SUCCESS;
}

PHP_INI_BEGIN()
    ZEND_INI_ENTRY("datadog.loader.force_inject", "0", PHP_INI_SYSTEM, ddloader_OnUpdateForceInject)
PHP_INI_END()

static const php7_0_to_2_zend_ini_entry_def ini_entries_7_0_to_2[] = {
    ZEND_INI_ENTRY("datadog.loader.force_inject", "0", PHP_INI_SYSTEM, ddloader_OnUpdateForceInject)
PHP_INI_END()


static void ddloader_telemetryf(telemetry_reason reason, injected_ext *config, const char *error, const char *format, ...);

static char *ddtrace_pre_load_hook(injected_ext *config) {
    char *libddtrace_php;
    int res = asprintf(&libddtrace_php, "%s/%sloader/libddtrace_php.so", package_path, OS_PATH);
    if (res == -1) {
        return "asprintf error";
    }
    if (access(libddtrace_php, F_OK)) {
        free(libddtrace_php);

        // Test without the OS_PATH (e.g. linux-gnu/)
        res = asprintf(&libddtrace_php, "%s/loader/libddtrace_php.so", package_path);
        if (res == -1) {
            return "asprintf error";
        }

        if (access(libddtrace_php, F_OK)) {
            free(libddtrace_php);
            LOG(config, INFO, "libddtrace_php.so not found during 'ddtrace' pre-load hook.")
            return NULL;
        }
    }

    LOG(config, INFO, "Found %s during 'ddtrace' pre-load hook. Load it.", libddtrace_php)
    void *handle = DL_LOAD(libddtrace_php);
    free(libddtrace_php);
    if (!handle) {
        return dlerror();
    }

    return NULL;
}

static bool ddloader_is_ext_loaded(const char *name) {
    return ddloader_zend_hash_str_find_ptr(php_api_no, &module_registry, name, strlen(name))
        || zend_get_extension(name)
    ;
}

static zval *ddloader_ini_get_configuration(const char *name, size_t name_len) {
    HashTable *configuration_hash = php_ini_get_configuration_hash();
    if (!configuration_hash) {
        return NULL;
    }
    zend_string *ini = ddloader_zend_string_init(php_api_no, name, name_len, 1);
    zval *val = zend_hash_find(configuration_hash, ini);
    ddloader_zend_string_release(php_api_no, ini);

    return val;
}

size_t safe_str_append(char *dst, size_t dst_size, const char *src) {
    size_t len = strlen(dst);
    if (len >= dst_size - 1) {
        return len;
    }
    size_t remaining = dst_size - len;
    int written = snprintf(dst + len, remaining, "%s", src);
    if (written < 0) return len;
    return len + (size_t)written;
}

static void ddloader_ini_set_configuration(injected_ext *config, const char *name, size_t name_len, const char *value, size_t value_len) {
    HashTable *configuration_hash = php_ini_get_configuration_hash();
    if (!configuration_hash) {
        return;
    }

    if (config) {
        safe_str_append(config->extra_config, sizeof(config->extra_config), name);
        safe_str_append(config->extra_config, sizeof(config->extra_config), "=");
        safe_str_append(config->extra_config, sizeof(config->extra_config), value);
        safe_str_append(config->extra_config, sizeof(config->extra_config), "\n");
    }

    zend_string *zstr_name = ddloader_zend_string_init(php_api_no, name, name_len, 1);
    zend_string *zstr_value = ddloader_zend_string_init(php_api_no, value, value_len, 1);

    zval tmp;
    ZVAL_STR(&tmp, zstr_value);
    ddloader_zend_hash_update(configuration_hash, zstr_name, &tmp);
    ddloader_zend_string_release(php_api_no, zstr_name);
}

static bool ddloader_is_opcache_jit_enabled() {
    // JIT is only PHP 8.0+
    if (php_api_no < PHP_80_VERSION || !ddloader_is_ext_loaded("Zend OPcache")) {
        return false;
    }

    // opcache.enable = false (default: true)
    zval *opcache_enable = ddloader_ini_get_configuration(ZEND_STRL("opcache.enable"));
    if (opcache_enable && Z_TYPE_P(opcache_enable) == IS_STRING && !ddloader_zend_ini_parse_bool(Z_STR_P(opcache_enable))) {
        return false;
    }
    if (strcmp("cli", sapi_module.name) == 0) {
        // opcache.enable_cli = false (default: false)
        zval *opcache_enable_cli = ddloader_ini_get_configuration(ZEND_STRL("opcache.enable_cli"));
        if (!opcache_enable_cli || Z_TYPE_P(opcache_enable_cli) != IS_STRING || !ddloader_zend_ini_parse_bool(Z_STR_P(opcache_enable_cli))) {
            return false;
        }
    }
    if (php_api_no > 20230831) { // PHP > 8.3 (https://wiki.php.net/rfc/jit_config_defaults)
        // opcache.jit == disable (default: disable)
        zval *opcache_jit = ddloader_ini_get_configuration(ZEND_STRL("opcache.jit"));
        if (!opcache_jit || Z_TYPE_P(opcache_jit) != IS_STRING || Z_STRLEN_P(opcache_jit) == 0 || strcmp(Z_STRVAL_P(opcache_jit), "disable") == 0 || strcmp(Z_STRVAL_P(opcache_jit), "off") == 0 || strcmp(Z_STRVAL_P(opcache_jit), "0") == 0) {
            return false;
        }
    } else {
        // opcache.jit_buffer_size = 0 (default: 0)
        zval *opcache_jit_buffer_size = ddloader_ini_get_configuration(ZEND_STRL("opcache.jit_buffer_size"));
        if (!opcache_jit_buffer_size || Z_TYPE_P(opcache_jit_buffer_size) != IS_STRING || Z_STRLEN_P(opcache_jit_buffer_size) == 0 || strcmp(Z_STRVAL_P(opcache_jit_buffer_size), "0") == 0) {
            return false;
        }
    }

    return true;
}

static void ddtrace_pre_minit_hook(injected_ext *config, zend_module_entry *module) {
    HashTable *configuration_hash = php_ini_get_configuration_hash();
    if (configuration_hash) {
        char *sources_path;
        if (asprintf(&sources_path, "%s/trace/src", package_path) == -1) {
            return;
        }

        // Set 'datadog.trace.sources_path' setting
        ddloader_ini_set_configuration(config, ZEND_STRL("datadog.trace.sources_path"), sources_path, strlen(sources_path));
        free(sources_path);
    }

    // Load, but disable the tracer if runtime configuration is not safe for auto-injection
    bool disable_tracer = false;

    char *incompatible_exts[] = {"Xdebug", "the ionCube PHP Loader", "ionCube Loader", "the ionCube PHP Loader + ionCube24", "newrelic", "blackfire", "pcov"};
    for (size_t i = 0; i < sizeof(incompatible_exts) / sizeof(incompatible_exts[0]); ++i) {
        if (ddloader_is_ext_loaded(incompatible_exts[i])) {
            if (force_load) {
                LOG(config, WARN, "Potentially incompatible extension detected: %s. Ignoring as DD_INJECT_FORCE is enabled", incompatible_exts[i]);
            } else {
                LOG(config, WARN, "Potentially incompatible extension detected: %s. ddtrace will be disabled unless the environment DD_INJECT_FORCE is set to '1', 'true', 'yes' or 'on'", incompatible_exts[i]);
                disable_tracer = true;
            }
        }
    }

    if (ddloader_is_opcache_jit_enabled()) {
        if (force_load) {
            LOG(config, WARN, "OPcache JIT is enabled and may cause instability. Ignoring as DD_INJECT_FORCE is enabled");
        } else {
            LOG(config, WARN, "OPcache JIT is enabled and may cause instability. ddtrace will be disabled unless the environment DD_INJECT_FORCE is set to '1', 'true', 'yes' or 'on'");
            disable_tracer = true;
        }
    }

    if (disable_tracer) {
        ddloader_ini_set_configuration(config, ZEND_STRL("ddtrace.disable"), ZEND_STRL("1"));
    }

    // Let ddtrace knows that it was loaded by the loader
    bool *ddtrace_loaded_by_ssi = (bool *)DL_FETCH_SYMBOL(module->handle, "ddtrace_loaded_by_ssi");
    if (ddtrace_loaded_by_ssi) {
        *ddtrace_loaded_by_ssi = true;
    }
    bool *ddtrace_ssi_forced_injection_enabled = (bool *)DL_FETCH_SYMBOL(module->handle, "ddtrace_ssi_forced_injection_enabled");
    if (ddtrace_ssi_forced_injection_enabled) {
        *ddtrace_ssi_forced_injection_enabled = force_load;
    }
}

static void appsec_pre_minit_hook(injected_ext *config, zend_module_entry *module) {
    UNUSED(module);

    HashTable *configuration_hash = php_ini_get_configuration_hash();
    if (configuration_hash) {
        char *helper_path;
        if (asprintf(&helper_path, "%s/appsec/lib/libddappsec-helper.so", package_path) == -1) {
            return;
        }
        ddloader_ini_set_configuration(config, ZEND_STRL("datadog.appsec.helper_path"), helper_path, strlen(helper_path));
        free(helper_path);
    }
}

static void profiling_pre_minit_hook(injected_ext *config, zend_module_entry *module) {
    UNUSED(module);

    if (!ddloader_ini_get_configuration(ZEND_STRL("datadog.profiling.enabled"))) {
        ddloader_ini_set_configuration(config, ZEND_STRL("datadog.profiling.enabled"), ZEND_STRL("0"));
    }
}

// Declare the extension we want to load
injected_ext ddloader_injected_ext_config[EXT_COUNT] = {
    // Tracer must be the first
    [EXT_DDTRACE] = DECLARE_INJECTED_EXT("ddtrace", "trace", PHP_70_VERSION, ddtrace_pre_load_hook, ddtrace_pre_minit_hook,
                         ((zend_module_dep[]){ZEND_MOD_OPTIONAL("json") ZEND_MOD_OPTIONAL("standard") ZEND_MOD_OPTIONAL("ddtrace") ZEND_MOD_END})),
    [EXT_DATADOG_PROFILING] = DECLARE_INJECTED_EXT("datadog-profiling", "profiling", PHP_71_VERSION, NULL, profiling_pre_minit_hook,
                        ((zend_module_dep[]){ZEND_MOD_OPTIONAL("json") ZEND_MOD_OPTIONAL("standard") ZEND_MOD_OPTIONAL("ddtrace") ZEND_MOD_OPTIONAL("ddtrace_injected") ZEND_MOD_OPTIONAL("datadog-profiling") ZEND_MOD_OPTIONAL("ev") ZEND_MOD_OPTIONAL("event") ZEND_MOD_OPTIONAL("libevent") ZEND_MOD_OPTIONAL("uv") ZEND_MOD_END})),
    [EXT_DDAPPSEC] = DECLARE_INJECTED_EXT("ddappsec", "appsec", PHP_70_VERSION, NULL, appsec_pre_minit_hook,
                        ((zend_module_dep[]){ZEND_MOD_OPTIONAL("ddtrace") ZEND_MOD_OPTIONAL("ddtrace_injected") ZEND_MOD_OPTIONAL("ddappsec") ZEND_MOD_END})),
};

void ddloader_logv(injected_ext *config, log_level level, const char *format, va_list va) {
    char msg[384];
    vsnprintf(msg, sizeof(msg), format, va);

    char *level_str = "unknown";
    switch (level) {
        case INFO:
            level_str = "info";
            break;
        case WARN:
            level_str = "warn";
            break;
        case ERROR:
            level_str = "error";
            break;
    }

    if (config) {
        safe_str_append(config->logs, sizeof(config->logs), msg);
        safe_str_append(config->logs, sizeof(config->logs), "\n");
    }

    if (!debug_logs) {
        return;
    }

    char full[512];
    snprintf(full, sizeof(full), "[dd_library_loader][%s] %s", level_str, msg);
    _php_error_log(0, full, NULL, NULL);
}

void ddloader_logf(injected_ext *config, log_level level, const char *format, ...) {
    va_list va;
    va_start(va, format);
    ddloader_logv(config, level, format, va);
    va_end(va);
}

/**
 * @param error The c-string this is pointing to must not exceed 150 bytes
 */
static void ddloader_telemetryf(telemetry_reason reason, injected_ext *config, const char *error, const char *format, ...) {
    log_level level = ERROR;
    static char buf[256]; 
    va_list va;
    va_start(va, format);
    vsnprintf(buf, sizeof(buf), format, va);
    va_end(va);

    switch (reason) {
        case REASON_ERROR:
            if (config) {
                config->result = "abort";
                config->result_class = "internal_error";
                config->result_reason = buf;
                config->injection_error = error;
                config->injection_success = false;
            }
            LOG(config, ERROR, "Error during instrumentation of application. Aborting.");
            break;
        case REASON_EOL_RUNTIME:
            if (config) {
                config->result = "abort";
                config->result_class = "incompatible_runtime";
                config->result_reason = buf;
                config->injection_error = "Incompatible runtime (end-of-life)";
                config->injection_success = false;
            }
            LOG(config, ERROR, "Aborting application instrumentation due to an incompatible runtime (end-of-life)");
            break;
        case REASON_INCOMPATIBLE_RUNTIME:
            if (config) {
                config->result = "abort";
                config->result_class = "incompatible_runtime";
                config->result_reason = buf;
                config->injection_error = "Incompatible runtime";
                config->injection_success = false;
            }
            LOG(config, ERROR, "Aborting application instrumentation due to an incompatible runtime");
            break;
        case REASON_ALREADY_LOADED:
            if (config) {
                config->result = "abort";
                config->result_class = "already_instrumented";
                config->result_reason = buf;
                config->injection_error = "Already loaded";
                config->injection_success = false;
            }
            level = INFO;
            break;
        case REASON_COMPLETE:
            if (config) {
                config->result = "success";
                config->result_class = injection_forced ? "success_forced" : "success";
                config->result_reason = buf;
                config->injection_success = true;
            }
            level = INFO;
            break;
        case REASON_START:
            level = INFO;
            break;
        default:
            break;
    }

    va_list va2;
    va_start(va2, format);
    ddloader_logv(config,level, format, va2);
    va_end(va2);

    // Skip COMPLETE telemetry except for ddtrace
    if (reason == REASON_COMPLETE && config && strcmp(config->ext_name, "ddtrace") != 0) {
        return;
    }

    if (!telemetry_forwarder_path) {
        LOG(config, INFO, "Telemetry disabled: environment variable 'DD_TELEMETRY_FORWARDER_PATH' is not set")
        return;
    }
    if (access(telemetry_forwarder_path, X_OK)) {
        LOG(config, ERROR, "Telemetry error: forwarder not found or not executable at '%s'", telemetry_forwarder_path)
        return;
    }

    pid_t loader_pid = getpid();
    pid_t pid = fork();
    if (pid < 0) {
        LOG(config, ERROR, "Telemetry error: cannot fork")
        return;
    }
    if (pid > 0) {
        return;  // parent
    }

    char points_buf[256] = {0};
    char *points = points_buf;
    switch (reason) {
        case REASON_START:
            points =
                "\
                {\"name\": \"library_entrypoint.start\", \"tags\": []}\
            ";
            break;

        case REASON_ERROR:
            snprintf(points_buf, sizeof(points_buf), "\
                    {\"name\": \"library_entrypoint.error\", \"tags\": [\"error_type:%s\", \"product:%s\"]}\
                ",
                error ? error : "NA",
                config ? config->ext_name : "NA"
            );
            break;

        case REASON_EOL_RUNTIME:
            snprintf(points_buf, sizeof(points_buf), "\
                    {\"name\": \"library_entrypoint.abort\", \"tags\": [\"reason:eol_runtime\", \"product:%s\"]},\
                    {\"name\": \"library_entrypoint.abort.runtime\"}\
                ",
                config ? config->ext_name : "NA"
            );
            break;

        case REASON_INCOMPATIBLE_RUNTIME:
            snprintf(points_buf, sizeof(points_buf), "\
                    {\"name\": \"library_entrypoint.abort\", \"tags\": [\"reason:incompatible_runtime\", \"product:%s\"]},\
                    {\"name\": \"library_entrypoint.abort.runtime\"}\
                ",
                config ? config->ext_name : "NA"
            );
            break;

        case REASON_ALREADY_LOADED:
            snprintf(points_buf, sizeof(points_buf), "\
                    {\"name\": \"library_entrypoint.abort\", \"tags\": [\"reason:already_loaded\", \"product:%s\"]}\
                ",
                config ? config->ext_name : "NA"
            );
            break;

        case REASON_COMPLETE:
            if (injection_forced) {
                points =
                    "\
                    {\"name\": \"library_entrypoint.complete\", \"tags\": [\"injection_forced:true\"]}\
                ";
            } else {
                points =
                    "\
                    {\"name\": \"library_entrypoint.complete\", \"tags\": [\"injection_forced:false\"]}\
                ";
            }
            break;
    }

    char *template =
        "\
{\
    \"metadata\": {\
        \"runtime_name\": \"php\",\
        \"runtime_version\": \"%s\",\
        \"language_name\": \"php\",\
        \"language_version\": \"%s\",\
        \"tracer_version\": \"%s\",\
        \"pid\": %d,\
        \"result_class\": \"%s\",\
        \"result_reason\": \"%s\",\
        \"result\": \"%s\"\
    },\
    \"points\": [%s]\
}\
";
    char *tracer_version = ddloader_injected_ext_config[0].version ?: "unknown";
    const char *result_class = (config && config->result_class) ? config->result_class : "unknown";
    const char *result_reason = (config && config->result_reason) ? config->result_reason : "unknown";
    const char *result = (config && config->result) ? config->result : "unknown";

    char payload[1024];
    snprintf(payload, sizeof(payload), template, runtime_version, runtime_version, tracer_version, loader_pid, result_class, result_reason, result, points);

    char *argv[] = {telemetry_forwarder_path, "library_entrypoint", payload, NULL};

    execv(telemetry_forwarder_path, argv);
    LOG(config, ERROR, "Telemetry: cannot execv: %s", strerror(errno))

    // If execv failed, exit immediately
    // Return 127 for the most likely case of a missing file
    exit(127);
}

static char *ddloader_find_ext_path(const char *ext_dir, const char *ext_name, int module_api, bool is_zts, bool is_debug) {
    char *full_path;
    int res = asprintf(&full_path, "%s/%s%s/ext/%d/%s%s%s.so", package_path, OS_PATH, ext_dir, module_api, ext_name, is_zts ? "-zts" : "", is_debug ? "-debug" : "");
    if (res == -1) {
        return NULL;
    }

    if (access(full_path, F_OK)) {
        free(full_path);

        // Test without the OS_PATH (e.g. linux-gnu/)
        res = asprintf(&full_path, "%s/%s/ext/%d/%s%s%s.so", package_path, ext_dir, module_api, ext_name, is_zts ? "-zts" : "", is_debug ? "-debug" : "");
        if (res == -1) {
            return NULL;
        }
        if (access(full_path, F_OK)) {
            free(full_path);
            return NULL;
        }
    }

    return full_path;
}

/**
 * Try to load a symbol from a library handle.
 * As some OS prepend _ to symbol names, we try to load with and without it.
 */
static void *ddloader_dl_fetch_symbol(void *handle, const char *symbol_name_with_underscore) {
    void *symbol = DL_FETCH_SYMBOL(handle, symbol_name_with_underscore + 1);
    if (!symbol) {
        symbol = DL_FETCH_SYMBOL(handle, symbol_name_with_underscore);
    }

    return symbol;
}

static bool ddloader_check_deps(const zend_module_dep *deps) {
    if (!deps) {
        return true;
    }

    size_t name_len;
    zend_string *lcname;
    int i = 0;
    while (deps[i].name) {
        if (deps[i].type == MODULE_DEP_REQUIRED) {
            zend_module_entry *req_mod = NULL;

            name_len = strlen(deps[i].name);
            lcname = ddloader_zend_string_alloc(php_api_no, name_len, 0);
            zend_str_tolower_copy(ZSTR_VAL(lcname), deps[i].name, name_len);

            zval *zv = zend_hash_find(&module_registry, lcname);
            if (zv) {
                req_mod = Z_PTR_P(zv);
            }

            if (req_mod == NULL || !req_mod->module_started) {
                efree(lcname);
                return false;
            }
            efree(lcname);
        }
        ++i;
    }

    return true;
}

static void ddloader_unregister_module(const char *name) {
    zend_module_entry *injected = ddloader_zend_hash_str_find_ptr(php_api_no, &module_registry, name, strlen(name));
    if (!injected) {
        return;
    }

    // Set the MSHUTDOWN function to NULL to avoid it being called by zend_hash_str_del
    injected->module_shutdown_func = NULL;
    ddloader_zend_hash_str_del(php_api_no, &module_registry, name, strlen(name));
}

static PHP_MINIT_FUNCTION(ddloader_injected_extension_minit) {
    // Find the injected extension config using the module_number set by the engine
    injected_ext *config = NULL;
    for (unsigned int i = 0; i < sizeof(ddloader_injected_ext_config) / sizeof(ddloader_injected_ext_config[0]); ++i) {
        if (ddloader_injected_ext_config[i].module_number == module_number) {
            config = &ddloader_injected_ext_config[i];
            break;
        }
    }
    if (!config) {
        TELEMETRY(REASON_ERROR, config, "ext_not_found", "Unable to find the configuration for the injected extension. Something went wrong");
        return SUCCESS;
    }

    zend_module_entry *module = ddloader_zend_hash_str_find_ptr(php_api_no, &module_registry, config->ext_name, strlen(config->ext_name));
    if (module) {
        TELEMETRY(REASON_ALREADY_LOADED, config, NULL, "Extension '%s' is already loaded, unregister the injected extension", config->ext_name);
        ddloader_unregister_module(config->tmp_name);

        return SUCCESS;
    }

    LOG(config, INFO, "Extension '%s' is not loaded, checking its dependencies", config->ext_name);

    // Normally done by zend_startup_module_ex, but we temporarily replaced these to skip potential errors. Check it ourselves here.
    if (!ddloader_check_deps(config->orig_module_deps)) {
        TELEMETRY(REASON_INCOMPATIBLE_RUNTIME, config, NULL, "Extension '%s' dependencies are not met, unregister the injected extension", config->ext_name);
        ddloader_unregister_module(config->tmp_name);

        return SUCCESS;
    }

    LOG(config, INFO, "Rename extension '%s' to '%s'", config->tmp_name, config->ext_name);

    /**
     * Rename the "key" of the module_registry to access the module.
     * Must be done at the bucket level to not change the order of the HashTable.
     */
    zend_string *old_name = ddloader_zend_string_init(php_api_no, config->tmp_name, strlen(config->tmp_name), 1);
    Bucket *bucket = (Bucket *)zend_hash_find(&module_registry, old_name);
    ddloader_zend_string_release(php_api_no, old_name);

    zend_string *new_name = ddloader_zend_string_init(php_api_no, config->ext_name, strlen(config->ext_name), 1);
    ddloader_zend_hash_set_bucket_key(php_api_no, &module_registry, bucket, new_name);
    ddloader_zend_string_release(php_api_no, new_name);

    module = ddloader_zend_hash_str_find_ptr(php_api_no, &module_registry, config->ext_name, strlen(config->ext_name));
    if (!module) {
        TELEMETRY(REASON_ERROR, config, "renamed_ext_not_found", "Extension '%s' not found after renaming. Something wrong happened", config->ext_name);
        return SUCCESS;
    }

    /* Restore name, MINIT, dependencies and functions of the module */
    module->name = config->ext_name;
    module->module_startup_func = config->orig_module_startup_func;
    module->deps = config->orig_module_deps;
    module->functions = config->orig_module_functions;
    if (module->functions && zend_register_functions(NULL, module->functions, NULL, module->type) == FAILURE) {
        TELEMETRY(REASON_ERROR, config, "cannot_register_functions", "Unable to register extension's functions");
        return SUCCESS;
    }

    if (config->pre_minit_hook) {
        config->pre_minit_hook(config, module);
    }

    zend_result ret = module->module_startup_func(INIT_FUNC_ARGS_PASSTHRU);
    if (ret == FAILURE) {
        TELEMETRY(REASON_ERROR, config, "error_minit", "'%s' MINIT function failed", config->ext_name);
    } else {
        TELEMETRY(REASON_COMPLETE, config, NULL, "Application instrumentation bootstrapping complete ('%s')", config->ext_name)
    }

    return ret;
}

static int ddloader_load_extension(unsigned int php_api_no, char *module_build_id, bool is_zts, bool is_debug, injected_ext *config) {
    if (php_api_no < config->ext_min_version) {
        TELEMETRY(REASON_INCOMPATIBLE_RUNTIME, config, NULL, "'%s' extension is not supported on this PHP version", config->ext_name, php_api_no);
        return SUCCESS;
    }

    char *ext_path = ddloader_find_ext_path(config->ext_dir, config->ext_name, php_api_no, is_zts, is_debug);
    if (!ext_path) {
        if (is_debug) {
            TELEMETRY(REASON_INCOMPATIBLE_RUNTIME, config, NULL, "'%s' extension file not found (debug build)", config->ext_name);
        } else {
            TELEMETRY(REASON_ERROR, config, "so_not_found", "'%s' extension file not found", config->ext_name);
        }

        return SUCCESS;
    }

    // The code below basically comes from the function "php_load_extension" in "ext/standard/dl.c",
    // but we need to rename the extension before passing it into the module_registry.

    LOG(config, INFO, "Found extension file: %s", ext_path);

    if (config->pre_load_hook) {
        LOG(config, INFO, "Running '%s' pre-load hook", config->ext_name);
        char *err = config->pre_load_hook(config);
        if (err) {
            TELEMETRY(REASON_ERROR, config, "error_ext_pre_load", "An error occurred while running '%s' pre-load hook: %s", config->ext_name, err);
            goto abort;
        }
    }

    void *handle = DL_LOAD(ext_path);
    if (!handle) {
        TELEMETRY(REASON_ERROR, config, "cannot_load_file", "Cannot load '%s' extension file: %s", config->ext_name, dlerror());
        goto abort;
    }

    zend_module_entry *(*get_module)(void) = (zend_module_entry * (*)(void)) ddloader_dl_fetch_symbol(handle, "_get_module");
    if (!get_module) {
        TELEMETRY(REASON_ERROR, config, "cannot_fetch_mod_entry", "Cannot fetch '%s' module entry", config->ext_name);
        goto abort_and_unload;
    }

    zend_module_entry *module_entry = get_module();

    if (module_entry->zend_api != php_api_no) {
        TELEMETRY(REASON_ERROR, config, "api_mismatch", "'%s' API number mismatch between module (%d) and runtime (%d)", config->ext_name, module_entry->zend_api,
                  php_api_no);
        goto abort_and_unload;
    }
    if (strcmp(module_entry->build_id, module_build_id)) {
        TELEMETRY(REASON_ERROR, config, "build_id_mismatch", "'%s' Build ID mismatch between module (%s) and runtime (%s)", config->ext_name, module_entry->build_id,
                  module_build_id);
        goto abort_and_unload;
    }

    /**
     * At that point, we don't know if the module will be registered or not by the PHP configuration.
     * So we register it under the a temporary name, add set an optional dependencies to be sure that
     * our injected extension will be started up after the real one (if it's loaded!), and finally we
     * wrap the MINIT function to perform our checks there.
     */
    module_entry->name = config->tmp_name;

    config->orig_module_startup_func = module_entry->module_startup_func;
    module_entry->module_startup_func = ZEND_MODULE_STARTUP_N(ddloader_injected_extension_minit);

    config->orig_module_deps = module_entry->deps;
    module_entry->deps = config->tmp_deps;

    // Backup the function list and set it to NULL to make sure we don't register the functions twice
    // They'll be restored if ddtrace is not already registered.
    config->orig_module_functions = module_entry->functions;
    module_entry->functions = NULL;

    // Register the module, catching all errors that can happen (already loaded, unsatisied dep, ...)
    ddloader_replace_zend_error_cb(php_api_no);
    module_entry = zend_register_internal_module(module_entry);
    ddloader_restore_zend_error_cb();

    if (module_entry == NULL) {
        TELEMETRY(REASON_ERROR, config, "cannot_register_ext", "Cannot register '%s' module", config->ext_name);
        goto abort_and_unload;
    }

    config->module_number = module_entry->module_number;
    config->version = (char *)module_entry->version;

    LOG(config, INFO, "Extension '%s' loaded", config->ext_name);
    goto ok;

abort_and_unload:
    LOG(config, INFO, "Unloading the library");
    DL_UNLOAD(handle);
abort:
    LOG(config, INFO, "Abort the loader");
ok:
    free(ext_path);

    return SUCCESS;
}

static bool ddloader_is_truthy(char *str) {
    if (!str) {
        return false;
    }

    size_t len = strlen(str);
    if (len < 1 || len > 4) {
        return false;
    }

    return (strcasecmp(str, "1") == 0 || strcasecmp(str, "true") == 0 || strcasecmp(str, "yes") == 0 || strcasecmp(str, "on") == 0);
}

static inline void ddloader_configure() {
    debug_logs = ddloader_is_truthy(getenv("DD_TRACE_DEBUG"));
    force_load = ddloader_is_truthy(getenv("DD_INJECT_FORCE"));
    telemetry_forwarder_path = getenv("DD_TELEMETRY_FORWARDER_PATH");
    package_path = getenv("DD_LOADER_PACKAGE_PATH");
}

static bool ddloader_libc_check() {
    bool is_musl;
    const char *error = dlerror();
    // gnu_get_libc_version is available since glibc 2.1
    char *(*get_libc_version)(void) = dlsym(RTLD_DEFAULT, "gnu_get_libc_version");
    error = dlerror();
    if (error == NULL && get_libc_version != NULL) {
        is_musl = false;
    } else {
        is_musl = true;
    }

#if defined(__MUSL__)
    if (!is_musl) {
        return false;
    }
#else
    if (is_musl) {
        return false;
    }
#endif

    return true;
}

static int ddloader_api_no_check(int api_no) {
    if (!ddloader_libc_check()) {
        return SUCCESS;
    }

    if (already_done) {
        LOG(NULL, WARN, "dd_library_loader has been loaded multiple times, aborting");
        return SUCCESS;
    }

    // api_no is the Zend extension API number, similar to "420220829"
    // It is an int, but represented as a string, we must remove the first char to get the PHP module API number
    unsigned int module_api_no = api_no % 100000000;
    ddloader_configure();

    TELEMETRY(REASON_START, NULL, NULL, "Starting injection");

    switch (api_no) {
        case 220040412:
            runtime_version = "5.0";
            break;
        case 220051025:
            runtime_version = "5.1";
            break;
        case 220060519:
            runtime_version = "5.2";
            break;
        case 220090626:
            runtime_version = "5.3";
            break;
        case 220100525:
            runtime_version = "5.4";
            break;
        case 220121212:
            runtime_version = "5.5";
            break;
        case 220131226:
            runtime_version = "5.6";
            break;
        default:
            runtime_version = zend_get_module_version("Reflection");
            break;
    }

    if (!package_path) {
        TELEMETRY(REASON_ERROR, NULL, "path_env_var_not_set", "DD_LOADER_PACKAGE_PATH environment variable is not set");
        return SUCCESS;
    }

    if (api_no < MIN_API_VERSION) {
        TELEMETRY(REASON_EOL_RUNTIME, NULL, NULL, "Found end-of-life runtime (api no: %d). Supported runtimes: PHP " MIN_PHP_VERSION " to " MAX_PHP_VERSION, api_no);
        return SUCCESS;
    }

    if (force_load || api_no <= MAX_INI_API_VERSION) {
        zend_module_entry *mod = zend_register_internal_module(&dd_library_loader_mod);
        zend_register_ini_entries(module_api_no <= PHP_72_VERSION ? (zend_ini_entry_def *) ini_entries_7_0_to_2 : ini_entries, mod->module_number);
    }

    if (api_no > MAX_API_VERSION) {
        if (!force_load) {
            TELEMETRY(REASON_INCOMPATIBLE_RUNTIME, NULL, NULL, "Found incompatible runtime (api no: %d). Supported runtimes: PHP " MIN_PHP_VERSION " to " MAX_PHP_VERSION, api_no);
            return SUCCESS;
        }
        injection_forced = true;
        LOG(NULL, WARN, "DD_INJECT_FORCE enabled, allowing unsupported runtimes and continuing (api no: %d).", api_no);
    }

    php_api_no = module_api_no;

    return SUCCESS;
}

static int ddloader_build_id_check(const char *build_id) {
    // Guardrail
    if (!ddloader_libc_check() || !php_api_no || already_done) {
        return SUCCESS;
    }

    already_done = true;

    bool is_zts = (strstr(build_id, "NTS") == NULL);
    bool is_debug = (strstr(build_id, "debug") != NULL);

    // build_id is the Zend extension build ID, similar to "API420220829,TS"
    // We must remove the 4th char to get the PHP module build ID
    char module_build_id[32] = {0};
    size_t build_id_len = strlen(build_id);
    if (build_id_len >= 12 && build_id_len <= 32) {
        memcpy(module_build_id, build_id, sizeof(char) * 3);
        memcpy(module_build_id + 3, build_id + 4, sizeof(char) * (build_id_len - 4 + 1));
    }

    // Guardrail
    if (*module_build_id == '\0') {
        TELEMETRY(REASON_ERROR, NULL, "invalid_build_id", "Invalid build id");
        return SUCCESS;
    }

    // Load the extensions declared in ddloader_injected_ext_config
    for (unsigned int i = 0; i < sizeof(ddloader_injected_ext_config) / sizeof(ddloader_injected_ext_config[0]); ++i) {
        ddloader_load_extension(php_api_no, module_build_id, is_zts, is_debug, &ddloader_injected_ext_config[i]);
    }

    return SUCCESS;
}

// Required. Otherwise the zend_extension is not loaded
static int ddloader_zend_extension_startup(zend_extension *ext) {
    UNUSED(ext);
    return SUCCESS;
}

// Define fake version information to force the engine to always call ddloader_api_no_check / ddloader_build_id_check
ZEND_DLEXPORT zend_extension_version_info extension_version_info = {
    0,
    "0",
};

ZEND_DLEXPORT zend_extension zend_extension_entry = {
    "dd_library_loader",
    PHP_DD_LIBRARY_LOADER_VERSION,
    "Datadog",
    "https://github.com/DataDog/dd-trace-php",
    "Copyright Datadog",
    ddloader_zend_extension_startup, /* startup() : module startup */
    NULL,                            /* shutdown() : module shutdown */
    NULL,                            /* activate() : request startup */
    NULL,                            /* deactivate() : request shutdown */
    NULL,                            /* message_handler() */

    NULL, /* compiler op_array_handler() */
    NULL, /* VM statement_handler() */
    NULL, /* VM fcall_begin_handler() */
    NULL, /* VM fcall_end_handler() */
    NULL, /* compiler op_array_ctor() */
    NULL, /* compiler op_array_dtor() */

    ddloader_api_no_check,   /* api_no_check */
    ddloader_build_id_check, /* build_id_check */

    BUILD_COMPAT_ZEND_EXTENSION_PROPERTIES /* Structure-ending macro */
};
