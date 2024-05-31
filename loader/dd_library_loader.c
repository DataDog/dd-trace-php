/* dd_library_loader extension for PHP */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <Zend/zend_extensions.h>
#include <php.h>

#include "php_dd_library_loader.h"

static bool debug_logs = false;
static bool force_load = false;

static int php_api_no = 0;
static int zend_module_api_no = 0;
char module_build_id[32] = {0};
bool is_zts = false;
bool is_debug = false;

// Public only starting from PHP 5.6
ZEND_API void zend_append_version_info(const zend_extension *extension) __attribute__((weak));

static void ddloader_error_handler_php5(int error_num, const char *error_filename, const uint error_lineno, const char *format, va_list args) {}

static void ddloader_error_handler(int error_num, zend_string *error_filename, const uint32_t error_lineno, zend_string *message) { }

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

static int ddloader_load_ddtrace() {
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

    if (module_entry->zend_api != zend_module_api_no) {
        LOG("Wrong API number");
        goto abort_and_unload;
    }
    if (strcmp(module_entry->build_id, module_build_id)) {
        LOG("Wrong Build ID");
        goto abort_and_unload;
    }

    // Register the module, catching all errors that can happen (already loaded, unsatisied dep, ...)
    if (php_api_no < 20151012) { // PHP 5
#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Wincompatible-pointer-types"
        void (*old_error_handler)(int, const char *, const uint, const char*, va_list);
        old_error_handler = zend_error_cb;
        zend_error_cb = ddloader_error_handler_php5;
        module_entry = zend_register_internal_module(module_entry);
        zend_error_cb = old_error_handler;
#pragma GCC diagnostic pop
    } else { // PHP 7+
        void (*old_error_handler)(int, zend_string *, const uint32_t, zend_string *);
        old_error_handler = zend_error_cb;
        zend_error_cb = ddloader_error_handler;
        module_entry = zend_register_internal_module(module_entry);
        zend_error_cb = old_error_handler;
    }

    if (module_entry == NULL) {
        LOG("Cannot register the module");
        goto abort_and_unload;
    }

    // As the Zend extensions are started up after the regular extension,
    // we need to start it up manually.

    module_entry->handle = handle;
    if (zend_startup_module_ex(module_entry) == FAILURE) {
        LOG("Cannot start the module");
        goto abort;
    }

    // The ddtrace Zend Extension should have been loaded by the ddtrace module.
    zend_extension *ddtrace = zend_get_extension("ddtrace");
    if (!ddtrace) {
        LOG("The ddtrace Zend extension cannot be found");
        goto abort;
    }

    // Copy of private function zend_extension_startup();
    if (ddtrace->startup) {
        if (ddtrace->startup(ddtrace) != SUCCESS) {
            LOG("An error occurred during the startup of ddtrace Zend extension");
            goto abort;
        }
        if (zend_append_version_info) {
            zend_append_version_info(ddtrace);
        }
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
        case 220100525: // 5.4
            zend_module_api_no = api_no % 100000000;
            api_no = 220100412;
            break;
        case 220121212: // 5.5
            zend_module_api_no = api_no % 100000000;
            api_no = 220121113;
            break;
        case 220131226: // 5.6
            zend_module_api_no = api_no % 100000000;
            api_no = 220131106;
            break;
        case 320151012: // 7.0
        case 320160303: // 7.1
        case 320170718: // 7.2
        case 320180731: // 7.3
        case 320190902: // 7.4
        case 420200930: // 8.0
        case 420210902: // 8.1
        case 420220829: // 8.2
        case 420230831: // 8.3
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

    if (!zend_module_api_no) {
        zend_module_api_no = php_api_no;
    }

    return SUCCESS;
}

static int ddloader_build_id_check(const char *build_id) {
    // Guardrail
    if (!php_api_no) {
        return SUCCESS;
    }

    is_zts = (strstr(build_id, "NTS") == NULL);
    is_debug = (strstr(build_id, "debug") != NULL);

    // build_id is the Zend extension build ID, similar to "API420220829,TS"
    // We must remove the 4th char to get the PHP module build ID
    size_t build_id_len = strlen(build_id);
    if (build_id_len >= 12 && build_id_len <= 32) {
        memcpy(module_build_id, build_id, sizeof(char) * 3);
        memcpy(module_build_id + 3, build_id + 4, sizeof(char) * (build_id_len - 4 + 1));
    }

    return SUCCESS;
}

// Required. Otherwise the zend_extension is not loaded
static int ddloader_zend_extension_startup(zend_extension *ext) {
    // Guardrail
    if (*module_build_id == '\0') {
        return SUCCESS;
    }

    ddloader_load_ddtrace();

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

// FIXME: Is this required?

// #ifdef COMPILE_DL_DD_LIBRARY_LOADER
// # ifdef ZTS
// ZEND_TSRMLS_CACHE_DEFINE()
// # endif
// ZEND_GET_MODULE(dd_library_loader)
// #endif
