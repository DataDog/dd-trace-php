/* dd_library_loader extension for PHP */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <Zend/zend_extensions.h>
#include <php.h>
#include <php_ini.h>
#include <stdbool.h>
#include <ext/standard/basic_functions.h>

#include "compat_php.h"
#include "php_dd_library_loader.h"

static bool debug_logs = false;
static bool force_load = false;
static char *telemetry_forwarder_path = NULL;
static char *package_path = NULL;

static unsigned int php_api_no = 0;
static const char *runtime_version = "unknown";
static bool injection_forced = false;

#if defined(__MUSL__)
# define OS_PATH "linux-musl/"
#else
# define OS_PATH "linux-gnu/"
#endif

static char *ddtrace_pre_load_hook(void) {
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
            LOG(INFO, "libddtrace_php.so not found during 'ddtrace' pre-load hook.")
            return NULL;
        }
    }

    LOG(INFO, "Found %s during 'ddtrace' pre-load hook. Load it.", libddtrace_php)
    void *handle = DL_LOAD(libddtrace_php);
    free(libddtrace_php);
    if (!handle) {
        return dlerror();
    }

    return NULL;
}

static void ddtrace_pre_minit_hook(void) {
    HashTable *configuration_hash = php_ini_get_configuration_hash();
    if (configuration_hash) {
        char *sources_path;
        if (asprintf(&sources_path, "%s/trace/src", package_path) == -1) {
            return;
        }

        // Set 'datadog.trace.sources_path' setting
        zend_string *name = ddloader_zend_string_init(php_api_no, ZEND_STRL("datadog.trace.sources_path"), 1);
        zend_string *value = ddloader_zend_string_init(php_api_no, sources_path, strlen(sources_path), 1);
        free(sources_path);

        zval tmp;
        ZVAL_STR(&tmp, value);
        ddloader_zend_hash_update(configuration_hash, name, &tmp);
    }
}

// Declare the extension we want to load
static injected_ext injected_ext_config[] = {
    // Tracer must be the first
    DECLARE_INJECTED_EXT("ddtrace", "trace", ddtrace_pre_load_hook, ddtrace_pre_minit_hook,
                         ((zend_module_dep[]){ZEND_MOD_OPTIONAL("json") ZEND_MOD_OPTIONAL("standard") ZEND_MOD_OPTIONAL("ddtrace") ZEND_MOD_END})),
    // DECLARE_INJECTED_EXT("datadog-profiling", "profiling", NULL, NULL, ((zend_module_dep[]){ZEND_MOD_END})),
    // DECLARE_INJECTED_EXT("ddappsec", "appsec", NULL, NULL, ((zend_module_dep[]){ZEND_MOD_END})),
};

void ddloader_logv(log_level level, const char *format, va_list va) {
    if (!debug_logs) {
        return;
    }

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

    char full[512];
    snprintf(full, sizeof(full), "[dd_library_loader][%s] %s", level_str, msg);
    _php_error_log(0, full, NULL, NULL);
}

void ddloader_logf(log_level level, const char *format, ...) {
    va_list va;
    va_start(va, format);
    ddloader_logv(level, format, va);
    va_end(va);
}

static void ddloader_telemetryf(telemetry_reason reason, const char *format, ...) {
    switch (reason) {
        case REASON_ERROR:
            LOG(ERROR, "Error during instrumentation of application. Aborting.");
            break;
        case REASON_EOL_RUNTIME:
            LOG(ERROR, "Aborting application instrumentation due to an incompatible runtime (end-of-life)");
            break;
        case REASON_INCOMPATIBLE_RUNTIME:
            LOG(ERROR, "Aborting application instrumentation due to an incompatible runtime");
            break;
        default:
            break;
    }

    va_list va;
    va_start(va, format);
    ddloader_logv(reason == REASON_COMPLETE ? INFO : ERROR, format, va);
    va_end(va);

    if (!telemetry_forwarder_path) {
        LOG(INFO, "Telemetry disabled: environment variable 'DD_TELEMETRY_FORWARDER_PATH' is not set")
        return;
    }
    if (access(telemetry_forwarder_path, X_OK)) {
        LOG(ERROR, "Telemetry error: forwarder not found or not executable at '%s'", telemetry_forwarder_path)
        return;
    }

    pid_t loader_pid = getpid();
    pid_t pid = fork();
    if (pid < 0) {
        LOG(ERROR, "Telemetry error: cannot fork")
        return;
    }
    if (pid > 0) {
        return;  // parent
    }

    char *points = "";
    switch (reason) {
        case REASON_ERROR:
            points =
                "\
                {\"name\": \"library_entrypoint.error\"}, \"tags\": [\"error_type:NA\"]}\
            ";
            break;

        case REASON_EOL_RUNTIME:
            points =
                "\
                {\"name\": \"library_entrypoint.abort\", \"tags\": [\"reason:eol_runtime\"]},\
                {\"name\": \"library_entrypoint.abort.runtime\"}\
            ";
            break;

        case REASON_INCOMPATIBLE_RUNTIME:
            points =
                "\
                {\"name\": \"library_entrypoint.abort\", \"tags\": [\"reason:incompatible_runtime\"]},\
                {\"name\": \"library_entrypoint.abort.runtime\"}\
            ";
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
        \"pid\": %d\
    },\
    \"points\": [%s]\
}\
";
    char *tracer_version = injected_ext_config[0].version ?: "unknown";

    char payload[1024];
    snprintf(payload, sizeof(payload), template, runtime_version, runtime_version, tracer_version, loader_pid, points);

    char *argv[] = {telemetry_forwarder_path, "library_entrypoint", payload, NULL};
    if (execv(telemetry_forwarder_path, argv)) {
        LOG(ERROR, "Telemetry: cannot execv")
    }
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
    zend_module_entry *injected = zend_hash_str_find_ptr(&module_registry, name, strlen(name));
    if (!injected) {
        return;
    }

    // Set the MSHUTDOWN function to NULL to avoid it being called by zend_hash_str_del
    injected->module_shutdown_func = NULL;
    zend_hash_str_del(&module_registry, name, strlen(name));
}

static PHP_MINIT_FUNCTION(ddloader_injected_extension_minit) {
    // Find the injected extension config using the module_number set by the engine
    injected_ext *config = NULL;
    for (unsigned int i = 0; i < sizeof(injected_ext_config) / sizeof(injected_ext_config[0]); ++i) {
        if (injected_ext_config[i].module_number == module_number) {
            config = &injected_ext_config[i];
            break;
        }
    }
    if (!config) {
        TELEMETRY(REASON_ERROR, "Unable to find the configuration for the injected extension. Something went wrong");
        return SUCCESS;
    }

    zend_module_entry *module = zend_hash_str_find_ptr(&module_registry, config->ext_name, strlen(config->ext_name));
    if (module) {
        LOG(INFO, "Extension '%s' is already loaded, unregister the injected extension", config->ext_name);
        ddloader_unregister_module(config->tmp_name);

        return SUCCESS;
    }

    LOG(INFO, "Extension '%s' is not loaded, checking its dependencies", config->ext_name);

    // Normally done by zend_startup_module_ex, but we temporarily replaced these to skip potential errors. Check it ourselves here.
    if (!ddloader_check_deps(config->orig_module_deps)) {
        TELEMETRY(REASON_INCOMPATIBLE_RUNTIME, "Extension '%s' dependencies are not met, unregister the injected extension", config->ext_name);
        ddloader_unregister_module(config->tmp_name);

        return SUCCESS;
    }

    LOG(INFO, "Rename extension '%s' to '%s'", config->tmp_name, config->ext_name);

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

    module = zend_hash_str_find_ptr(&module_registry, config->ext_name, strlen(config->ext_name));
    if (!module) {
        TELEMETRY(REASON_ERROR, "Extension '%s' not found after renaming. Something wrong happened", config->ext_name);
        return SUCCESS;
    }

    /* Restore name, MINIT, dependencies and functions of the module */
    module->name = config->ext_name;
    module->module_startup_func = config->orig_module_startup_func;
    module->deps = config->orig_module_deps;
    module->functions = config->orig_module_functions;
    if (module->functions && zend_register_functions(NULL, module->functions, NULL, module->type) == FAILURE) {
        TELEMETRY(REASON_ERROR, "Unable to register extension's functions");
        return SUCCESS;
    }

    if (config->pre_minit_hook) {
        config->pre_minit_hook();
    }

    zend_result ret = module->module_startup_func(INIT_FUNC_ARGS_PASSTHRU);
    if (ret == FAILURE) {
        TELEMETRY(REASON_ERROR, "'%s' MINIT function failed", config->ext_name);
    } else {
        TELEMETRY(REASON_COMPLETE, "Application instrumentation bootstrapping complete ('%s')", config->ext_name)
    }

    return ret;
}

static int ddloader_load_extension(unsigned int php_api_no, char *module_build_id, bool is_zts, bool is_debug, injected_ext *config) {
    char *ext_path = ddloader_find_ext_path(config->ext_dir, config->ext_name, php_api_no, is_zts, is_debug);
    if (!ext_path) {
        TELEMETRY(REASON_INCOMPATIBLE_RUNTIME, "'%s' extension file not found", config->ext_name);
        return SUCCESS;
    }

    // The code below basically comes from the function "php_load_extension" in "ext/standard/dl.c",
    // but we need to rename the extension before passing it into the module_registry.

    LOG(INFO, "Found extension file: %s", ext_path);

    if (config->pre_load_hook) {
        LOG(INFO, "Running '%s' pre-load hook", config->ext_name);
        char *err = config->pre_load_hook();
        if (err) {
            TELEMETRY(REASON_ERROR, "An error occurred while running '%s' pre-load hook: %s", config->ext_name, err);
            goto abort;
        }
    }

    void *handle = DL_LOAD(ext_path);
    if (!handle) {
        TELEMETRY(REASON_ERROR, "Cannot load '%s' extension file: %s", config->ext_name, dlerror());
        goto abort;
    }

    zend_module_entry *(*get_module)(void) = (zend_module_entry * (*)(void)) ddloader_dl_fetch_symbol(handle, "_get_module");
    if (!get_module) {
        TELEMETRY(REASON_ERROR, "Cannot fetch '%s' module entry", config->ext_name);
        goto abort_and_unload;
    }

    zend_module_entry *module_entry = get_module();

    if (module_entry->zend_api != php_api_no) {
        TELEMETRY(REASON_ERROR, "'%s' API number mismatch between module (%d) and runtime (%d)", config->ext_name, module_entry->zend_api,
                  php_api_no);
        goto abort_and_unload;
    }
    if (strcmp(module_entry->build_id, module_build_id)) {
        TELEMETRY(REASON_ERROR, "'%s' Build ID mismatch between module (%s) and runtime (%s)", config->ext_name, module_entry->build_id,
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
        TELEMETRY(REASON_ERROR, "Cannot register '%s' module", config->ext_name);
        goto abort_and_unload;
    }

    config->module_number = module_entry->module_number;
    config->version = (char *)module_entry->version;

    return SUCCESS;

abort_and_unload:
    LOG(INFO, "Unloading the library");
    DL_UNLOAD(handle);
abort:
    LOG(INFO, "Abort the loader");
    free(ext_path);

    return SUCCESS;
}

static void ddloader_strtolower(char *dest, char *src) {
    while (*src) {
        *dest = (char)tolower((int)*src);
        ++dest;
        ++src;
    }
}

static bool ddloader_is_truthy(char *str) {
    if (!str) {
        return false;
    }

    size_t len = strlen(str);
    if (len < 1 || len > 4) {
        return false;
    }

    char lower[5] = {0};
    ddloader_strtolower(lower, str);

    return (strcmp(lower, "1") == 0 || strcmp(lower, "true") == 0 || strcmp(lower, "yes") == 0 || strcmp(lower, "on") == 0);
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

    ddloader_configure();

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
        TELEMETRY(REASON_ERROR, "DD_LOADER_PACKAGE_PATH environment variable is not set");
        return SUCCESS;
    }

    switch (api_no) {
        case 320151012:  // 7.0
        case 320160303:  // 7.1
        case 320170718:  // 7.2
        case 320180731:  // 7.3
        case 320190902:  // 7.4
        case 420200930:  // 8.0
        case 420210902:  // 8.1
        case 420220829:  // 8.2
        case 420230831:  // 8.3
            break;

        default:
            if (!force_load || api_no < 320151012) {
                telemetry_reason reason = api_no < 320151012 ? REASON_EOL_RUNTIME : REASON_INCOMPATIBLE_RUNTIME;
                TELEMETRY(reason, "Found incompatible runtime (api no: %d). Supported runtimes: PHP 7.0 to 8.3", api_no);

                // If we return FAILURE, this Zend extension would be unload, BUT it would produce an error
                // similar to "The Zend Engine API version 220100525 which is installed, is newer."
                return SUCCESS;
            }
            LOG(WARN, "DD_INJECT_FORCE enabled, allowing unsupported runtimes and continuing (api no: %d).", api_no);
            injection_forced = true;
            break;
    }

    // api_no is the Zend extension API number, similar to "420220829"
    // It is an int, but represented as a string, we must remove the first char to get the PHP module API number
    php_api_no = api_no % 100000000;

    return SUCCESS;
}

static int ddloader_build_id_check(const char *build_id) {
    // Guardrail
    if (!ddloader_libc_check() || !php_api_no) {
        return SUCCESS;
    }

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
        TELEMETRY(REASON_ERROR, "Invalid build id");
        return SUCCESS;
    }

    // Load the extensions declared in injected_ext_config
    for (unsigned int i = 0; i < sizeof(injected_ext_config) / sizeof(injected_ext_config[0]); ++i) {
        ddloader_load_extension(php_api_no, module_build_id, is_zts, is_debug, &injected_ext_config[i]);
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
