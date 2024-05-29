/* dd_library_loader extension for PHP */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <Zend/zend_extensions.h>
#include <php.h>

#include "php_dd_library_loader.h"

static bool debug_logs = false;

#define LOG(str) ddloader_log(str);

static inline void ddloader_log(const char *log) {
    if (!debug_logs) {
        return;
    }
    php_printf("[dd_library_loader][error] %s\n", log);
}

static int ddloader_api_no_check(int api_no) {
    // Enable logs as soon as possible
    if (getenv("DD_LOADER_ENABLE_LOGS")) {
        debug_logs = true;
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
            LOG("Unknown Zend API");
            if (!getenv("DD_LOADER_FORCE")) {
                return FAILURE;
            }
            LOG("Force load anyway");
            break;
    }

    return SUCCESS;
}

static int ddloader_build_id_check(const char *build_id) { return SUCCESS; }

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

// Try to load the ddtrace extension
// We always exit with "SUCCESS" to avoid logs
static int ddloader_zend_extension_startup(zend_extension *ext) {
    // The "json" extension is required by ddtrace
    // We check the extension is loaded, and use it as reference for zend_api and build_id
    zend_module_entry *json_ext = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("json"));
    if (!json_ext) {
        LOG("Cannot find the 'json' extension");
        return SUCCESS;
    }

    bool is_zts = (strstr(json_ext->build_id, "NTS") == NULL);
    bool is_debug = (strstr(json_ext->build_id, "debug") != NULL);

    char *ext_path = ddloader_find_ext_path("trace", "ddtrace", json_ext->zend_api, is_zts, is_debug);
    // Extension not found
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

    get_module = (zend_module_entry * (*)(void)) DL_FETCH_SYMBOL(handle, "get_module");

    /* Some OS prepend _ to symbol names while their dynamic linker
     * does not do that automatically. Thus we check manually for
     * _get_module. */

    if (!get_module) {
        get_module = (zend_module_entry * (*)(void)) DL_FETCH_SYMBOL(handle, "_get_module");
    }

    if (!get_module) {
        LOG("Cannot fetch the module entry");
        goto abort_and_unload;
    }

    module_entry = get_module();

    if (zend_hash_str_exists(&module_registry, module_entry->name, strlen(module_entry->name))) {
        LOG("The extension is already loaded");
        goto abort_and_unload;
    }
    if (module_entry->zend_api != json_ext->zend_api) {
        LOG("Wrong API number");
        goto abort_and_unload;
    }
    if (strcmp(module_entry->build_id, json_ext->build_id)) {
        LOG("Wrong Build ID");
        goto abort_and_unload;
    }
    if ((module_entry = zend_register_internal_module(module_entry)) == NULL) {
        LOG("Cannot register the module");
        goto abort_and_unload;
    }

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
        zend_append_version_info(ddtrace);
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
