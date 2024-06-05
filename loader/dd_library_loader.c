/* dd_library_loader extension for PHP */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <Zend/zend_extensions.h>
#include <php.h>
#include <stdbool.h>

#include "php_dd_library_loader.h"
#include "compat_php.h"

static int (*origin_ddtrace_module_startup_func)(INIT_FUNC_ARGS);
static const zend_module_dep ddtrace_injected_module_deps[] = {ZEND_MOD_OPTIONAL("ddtrace") ZEND_MOD_END};
static const zend_module_dep *orig_ddtrace_module_deps = NULL;
static const zend_function_entry *orig_functions;

static bool debug_logs = false;
static bool force_load = false;

static int php_api_no = 0;

static void ddloader_error_handler(int error_num, zend_string *error_filename, const uint32_t error_lineno, zend_string *message) {}

#define LOG(str) ddloader_log(str);

static inline void ddloader_log(const char *log) {
    if (!debug_logs) {
        return;
    }
    php_printf("[dd_library_loader][error] %s\n", log);
}

static char *ddloader_find_ext_path(const char *ext_dir, const char *ext_name, int module_api, bool is_zts, bool is_debug) {
    char *pkg_path = getenv("DD_LOADER_PACKAGE_PATH");
    if (!pkg_path) {
        pkg_path = "/home/circleci/app/dd-library-php";  // FIXME
    }

    char *full_path;
    asprintf(&full_path, "%s/%s/ext/%d/%s%s%s.so", pkg_path, ext_dir, module_api, ext_name, is_zts ? "-zts" : "", is_debug ? "-debug" : "");

    if (access(full_path, F_OK)) {
        free(full_path);

        return NULL;
    }

    return full_path;
}

/**
 * Try to load a symbol from a library handle.
 * As some OS prepend _ to symbol names, we try to load with and without it.
 */
static void *ddloader_dl_fetch_symbol(void *handle, const char *symbol_name_with_underscoe) {
    void *symbol = DL_FETCH_SYMBOL(handle, symbol_name_with_underscoe + 1);
    if (!symbol) {
        symbol = DL_FETCH_SYMBOL(handle, symbol_name_with_underscoe);
    }

    return symbol;
}

static bool ddloader_check_deps(const zend_module_dep *deps) {
    if (!deps) {
        return true;
    }

	size_t name_len;
	zend_string *lcname;
    char i = 0;
    while (deps[i].name) {
        if (deps[i].type == MODULE_DEP_REQUIRED) {
            zend_module_entry *req_mod;

            name_len = strlen(deps[i].name);
            lcname = zend_string_alloc(name_len, 0);
            zend_str_tolower_copy(ZSTR_VAL(lcname), deps[i].name, name_len);

            if ((req_mod = zend_hash_find_ptr(&module_registry, lcname)) == NULL || !req_mod->module_started) {
                zend_string_efree(lcname);
                return false;
            }
            zend_string_efree(lcname);
        }
        ++i;
    }

    return true;
}

static void ddloader_unregister_module(void) {
    zend_module_entry *ddtrace_injected = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("ddtrace_injected"));
    if (!ddtrace_injected) {
        return;
    }

    // Set the MSHUTDOWN function to NULL to avoid it being called by zend_hash_str_del
    ddtrace_injected->module_shutdown_func = NULL;
    zend_hash_str_del(&module_registry, ZEND_STRL("ddtrace_injected"));
}

static PHP_MINIT_FUNCTION(ddtrace_injected) {
    zend_module_entry *ddtrace = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("ddtrace"));
    if (ddtrace) {
        LOG("ddtrace is already loaded, unregister ddtrace_injected");
        ddloader_unregister_module();

        return SUCCESS;
    }

    LOG("ddtrace is not loaded, check the dependencies");

    if (!ddloader_check_deps(orig_ddtrace_module_deps)) {
        LOG("ddtrace dependencies are not met, unregister ddtrace_injected");
        ddloader_unregister_module();

        return SUCCESS;
    }

    LOG("Rename ddtrace_injected to ddtrace");

    /**
     * Rename the "key" of the module_registry to access ddtrace.
     * Must be done at the bucket level to not change the order of the HashTable.
     */
    zend_string *old_name = zend_string_init(ZEND_STRL("ddtrace_injected"), 0); // non-persistent to avoid a crash with PHP 7.0/7.1/7.2 at release because IS_STR_PERSISTENT has a different value
    Bucket *bucket = (Bucket*)zend_hash_find(&module_registry, old_name);
    zend_string_release(old_name);

    zend_string *new_name = zend_string_init(ZEND_STRL("ddtrace"), 0); // non-persistent to avoid a crash with PHP 7.0/7.1/7.2 at release because IS_STR_PERSISTENT has a different value
    ddloader_zend_hash_set_bucket_key(php_api_no, &module_registry, bucket, new_name);
    zend_string_release(new_name);

    ddtrace = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("ddtrace"));
    if (!ddtrace) {
        LOG("ddtrace not found. Something wrong happened");
        return SUCCESS;
    }

    /* Restore the original MINIT, dependencies and functions of the module */
    ddtrace->module_startup_func = origin_ddtrace_module_startup_func;
    ddtrace->deps = orig_ddtrace_module_deps;
    ddtrace->functions = orig_functions;
	if (ddtrace->functions && zend_register_functions(NULL, ddtrace->functions, NULL, ddtrace->type) == FAILURE) {
		LOG("Unable to register ddtrace's functions");
        return SUCCESS;
	}

    return origin_ddtrace_module_startup_func(INIT_FUNC_ARGS_PASSTHRU);
}

static int ddloader_load_ddtrace(int php_api_no, char *module_build_id, bool is_zts, bool is_debug) {
    char *ext_path = ddloader_find_ext_path("trace", "ddtrace", php_api_no, is_zts, is_debug);
    if (!ext_path) {
        LOG("Extension file not found");
        return SUCCESS;
    }

    // The code below basically comes from the function "php_load_extension" in "ext/standard/dl.c",
    // which does not allow loading an extension using a full path.

    zend_module_entry *module_entry;
    zend_module_entry *(*get_module)(void);

    void *handle = DL_LOAD(ext_path);
    if (!handle) {
        LOG("Cannot load the extension");
        goto abort;
    }

    get_module = (zend_module_entry * (*)(void)) ddloader_dl_fetch_symbol(handle, "_get_module");
    if (!get_module) {
        LOG("Cannot fetch the module entry");
        goto abort_and_unload;
    }

    module_entry = get_module();

    if (module_entry->zend_api != php_api_no) {
        LOG("Wrong API number");
        goto abort_and_unload;
    }
    if (strcmp(module_entry->build_id, module_build_id)) {
        LOG("Wrong Build ID");
        goto abort_and_unload;
    }

    /**
     * At that point, we don't know if ddtrace will be registered or not by the PHP configuration.
     * So we register it under the name "ddtrace_injected", add set an optional dependency to
     * "ddtrace" to be sure that our injected extension will be started up after the regular "ddtrace"
     * (if it's loaded!), and finally we wrap the MINIT function to perform our checks there.
     */
    module_entry->name = "ddtrace_injected";
    origin_ddtrace_module_startup_func = module_entry->module_startup_func;
    module_entry->module_startup_func = ZEND_MODULE_STARTUP_N(ddtrace_injected);
    // Set our optional dependency to the real "ddtrace" module
    orig_ddtrace_module_deps = module_entry->deps;
    module_entry->deps = ddtrace_injected_module_deps;
    // Backup the function list and set it to NULL to make sure we don't register the functions twice
    // They'll be restored if ddtrace is not already registered.
    orig_functions = module_entry->functions;
    module_entry->functions = NULL;

    // Register the module, catching all errors that can happen (already loaded, unsatisied dep, ...)
    void (*old_error_handler)(int, zend_string *, const uint32_t, zend_string *);
    old_error_handler = zend_error_cb;
    zend_error_cb = ddloader_error_handler;
    module_entry = zend_register_internal_module(module_entry);
    zend_error_cb = old_error_handler;

    if (module_entry == NULL) {
        LOG("Cannot register the module");
        goto abort_and_unload;
    }

    return SUCCESS;

abort_and_unload:
    LOG("Unloading the library");
    DL_UNLOAD(handle);
abort:
    LOG("Abort the loader");
    free(ext_path);

    return SUCCESS;
}

static inline void ddloader_configure() {
    if (getenv("DD_LOADER_ENABLE_LOGS")) {
        debug_logs = true;
    }
    if (getenv("DD_LOADER_FORCE")) {
        force_load = true;
    }
}

static int ddloader_api_no_check(int api_no) {
    ddloader_configure();

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
            LOG("Unknown api no");
            if (!force_load) {
                // If we return FAILURE, this Zend extension would be unload, BUT it would produce an error
                // similar to "The Zend Engine API version 220100525 which is installed, is newer."
                return SUCCESS;
            }
            LOG("Continue to load the extension even if the api no is not supported");
            break;
    }

    // api_no is the Zend extension API number, similar to "420220829"
    // It is an int, but represented as a string, we must remove the first char to get the PHP module API number
    php_api_no = api_no % 100000000;

    return SUCCESS;
}

static int ddloader_build_id_check(const char *build_id) {
    // Guardrail
    if (!php_api_no) {
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
        return SUCCESS;
    }

    ddloader_load_ddtrace(php_api_no, module_build_id, is_zts, is_debug);

    return SUCCESS;
}

// Required. Otherwise the zend_extension is not loaded
static int ddloader_zend_extension_startup(zend_extension *ext) { return SUCCESS; }

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

// FIXME: Is this required?

// #ifdef COMPILE_DL_DD_LIBRARY_LOADER
// # ifdef ZTS
// ZEND_TSRMLS_CACHE_DEFINE()
// # endif
// ZEND_GET_MODULE(dd_library_loader)
// #endif
