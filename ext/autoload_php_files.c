#include "autoload_php_files.h"

#include <Zend/zend.h>
#include <Zend/zend_compile.h>
#include <exceptions/exceptions.h>
#include <php_main.h>
#include <string.h>

#include <ext/standard/php_filestat.h>

#include "configuration.h"
#include "ddtrace.h"
#include "engine_hooks.h"
#include "telemetry.h"
#include <components/log/log.h>
#include <sandbox/sandbox.h>
#include <symbols/symbols.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#if PHP_VERSION_ID < 80000
zif_handler dd_spl_autoload_fn_handler;
zif_handler dd_spl_autoload_call_fn_handler;
zif_handler dd_spl_autoload_register_fn_handler;
zif_handler dd_spl_autoload_unregister_fn_handler;
zif_handler dd_spl_autoload_functions_fn_handler;
zend_function *dd_spl_autoload_fn;
zend_function *dd_spl_autoload_call_fn;
#else
static zend_class_entry *(*dd_prev_autoloader)(zend_string *name, zend_string *lc_name);
#endif
#if PHP_VERSION_ID >= 70400
static zend_bool dd_api_is_preloaded = false;
static zend_bool dd_otel_is_preloaded = false;
static zend_bool dd_legacy_tracer_is_preloaded = false;
#endif

#if PHP_VERSION_ID < 80000
#define LAST_ERROR_STRING PG(last_error_message)
#else
#define LAST_ERROR_STRING ZSTR_VAL(PG(last_error_message))
#endif
#if PHP_VERSION_ID < 80100
#define LAST_ERROR_FILE PG(last_error_file)
#else
#define LAST_ERROR_FILE ZSTR_VAL(PG(last_error_file))
#endif

int dd_execute_php_file(const char *filename, zval *result, bool try) {
    ZVAL_UNDEF(result);

    size_t filename_len = strlen(filename);
    if (filename_len == 0) {
        return FAILURE;
    }

    volatile int success = FAILURE;

    zend_string *file_str = zend_string_init(filename, filename_len, 0);

#if PHP_VERSION_ID < 80100
    zval file_zv, *file_value = &file_zv;
    ZVAL_STR(file_value, file_str);
#else
    zend_string *file_value = file_str;
#endif

    zend_bool _original_cg_multibyte = CG(multibyte);
    CG(multibyte) = false;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

#if PHP_VERSION_ID >= 80200
    zend_execute_data *prev_observed = zai_set_observed_frame(NULL);
#endif

    zend_try {
        zend_op_array *new_op_array = compile_filename(ZEND_INCLUDE, file_value);

        if (new_op_array) {
            zend_execute(new_op_array, result);

            destroy_op_array(new_op_array);
            efree(new_op_array);
            success = SUCCESS;
        }
    } zend_catch {
        zai_sandbox_bailout(&sandbox);
#if PHP_VERSION_ID >= 80000
        zai_reset_observed_frame_post_bailout();
#endif
    } zend_end_try();

#if PHP_VERSION_ID >= 80200
    zai_set_observed_frame(prev_observed);
#endif

    if (success == FAILURE && try && VCWD_ACCESS(filename, R_OK) != 0) {
        success = 2;
    } else {
        LOGEV(WARN, {
            if (PG(last_error_message)) {
                log("Error raised in autoloaded file %s: %s in %s on line %d", filename, LAST_ERROR_STRING, LAST_ERROR_FILE, PG(last_error_lineno));
                if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_TELEMETRY_LOG_COLLECTION_ENABLED()) {
                    INTEGRATION_ERROR_TELEMETRY(ERROR, "Error raised in autoloaded file %s: %s in %s on line %d",
                        ddtrace_telemetry_redact_file(filename), LAST_ERROR_STRING, ddtrace_telemetry_redact_file(LAST_ERROR_FILE), PG(last_error_lineno));
                }
            }
            zend_object *ex = EG(exception);
            if (ex) {
                const char *type = ex->ce->name->val;
                const char *msg = instanceof_function(ex->ce, zend_ce_throwable) ? ZSTR_VAL(zai_exception_message(ex)) : "<exit>";
                log("%s thrown in autoloaded file %s: %s", type, filename, msg);
                if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_TELEMETRY_LOG_COLLECTION_ENABLED()) {
                    INTEGRATION_ERROR_TELEMETRY(ERROR, "%s thrown in autoloaded file %s: %s", type, ddtrace_telemetry_redact_file(filename), msg);
                }
            }
        })
    }

    zai_sandbox_close(&sandbox);

    zend_string_release(file_str);
    CG(multibyte) = _original_cg_multibyte;

    return success;
}

static void dd_load_file(const char *file) {
    char path[MAXPATHLEN];
    zend_string *sources_path = get_global_DD_TRACE_SOURCES_PATH();
    unsigned int class_start = ZSTR_LEN(sources_path) + 1;
    size_t path_len = snprintf(path, sizeof(path), "%s/%s.php", ZSTR_VAL(sources_path), file);
    for (unsigned int i = class_start; i < path_len; ++i) {
        if (path[i] == '\\') {
            path[i] = '/';
        }
    }

    zval result;
    if (dd_execute_php_file(path, &result, true) == 2 && path_len > class_start + 7) {
        // replace DDTrace/ by api/
        char *dir = path + class_start;
        *(dir++) = 'a';
        *(dir++) = 'p';
        *(dir++) = 'i';
        int move_off = class_start + strlen("ddtrace");
        memmove(dir, path + move_off, path_len - move_off + 1 /* final null */);

        dd_execute_php_file(path, &result, true);
    }
    zval_ptr_dtor(&result);
}

static void dd_load_files(const char *files_file) {
    char path[MAXPATHLEN];
    zend_string *sources_path = get_global_DD_TRACE_SOURCES_PATH();
    int class_start = ZSTR_LEN(sources_path) + 1;
    size_t path_len = snprintf(path, sizeof(path), "%s/%s.php", ZSTR_VAL(sources_path), files_file);
    for (unsigned int i = class_start; i < path_len; ++i) {
        if (path[i] == '\\') {
            path[i] = '/';
        }
    }

    zval result;
    if (dd_execute_php_file(path, &result, false) == SUCCESS && Z_TYPE(result) == IS_ARRAY) {
        zval *filezv;
        ZEND_HASH_FOREACH_VAL(Z_ARR(result), filezv) {
            if (Z_TYPE_P(filezv) == IS_STRING) {
                zval zv;
                dd_execute_php_file(Z_STRVAL_P(filezv), &zv, false);
                zval_ptr_dtor(&zv);
            }
        } ZEND_HASH_FOREACH_END();
    }
    zval_ptr_dtor(&result);
}

#define dd_load_files(file) EXPECTED(get_global_DD_AUTOLOAD_NO_COMPILE() == false) ? dd_load_file("bridge/_generated_" file) : dd_load_files("bridge/_files_" file)

// We have, at this place, the luxury of knowing that we'll always be called before composers autoloader.
// Note that this code will also be called during opcache.preload, allowing us to not consider that scenario separately.
// The first time the autoloader gets invoked for ddtrace\\, we load the API
// The first time the autoloader gets invoked for ddtrace\\integration\\ we load the integrations
// If the API autoloader did not find the class, assume that the legacy tracer API files are being loaded
// If it's still not being found, try loading it from the filesystem
// If it's an opentelemetry class, we just load all otel classes we know about and are done
static zend_class_entry *dd_perform_autoload(zend_string *class_name, zend_string *lc_name) {
    if (ZSTR_LEN(get_global_DD_TRACE_SOURCES_PATH())) {
        zend_class_entry * ce;
        if (zend_string_starts_with_literal(lc_name, "ddtrace\\")) {
            if (!DDTRACE_G(api_is_loaded)) {
                DDTRACE_G(api_is_loaded) = 1;
                dd_load_files("api");
                if ((ce = zend_hash_find_ptr(EG(class_table), lc_name))) {
                    return ce;
                }
            }
            if (!DDTRACE_G(legacy_tracer_is_loaded) && !zend_string_starts_with_literal(lc_name, "ddtrace\\integration\\")) {
                DDTRACE_G(legacy_tracer_is_loaded) = 1;
                dd_load_files("tracer");
                if ((ce = zend_hash_find_ptr(EG(class_table), lc_name))) {
                    return ce;
                }
            }

            dd_load_file(ZSTR_VAL(class_name));
            if ((ce = zend_hash_find_ptr(EG(class_table), lc_name))) {
                return ce;
            }
        }

        if (get_DD_TRACE_OTEL_ENABLED() && zend_string_starts_with_literal(lc_name, "opentelemetry\\") && !DDTRACE_G(otel_is_loaded)) {
            DDTRACE_G(otel_is_loaded) = 1;
            dd_load_files("opentelemetry");
            if ((ce = zend_hash_find_ptr(EG(class_table), lc_name))) {
                return ce;
            }
        }
    }
#if PHP_VERSION_ID >= 80000
    if (EXPECTED(dd_prev_autoloader != NULL)) {
        return dd_prev_autoloader(class_name, lc_name);
    }
#endif
    return NULL;
}

#if PHP_VERSION_ID < 80000
ZEND_TLS bool dd_in_autoload = false;
ZEND_TLS bool dd_has_registered_spl_autoloader = false;

static inline bool dd_legacy_autoload_wrapper(INTERNAL_FUNCTION_PARAMETERS) {
    UNUSED(return_value);
    zend_string *class_name;

    if (dd_in_autoload) {
        return false;
    }

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 1, 1)
        Z_PARAM_STR(class_name)
    ZEND_PARSE_PARAMETERS_END_EX(return false;);

    zend_string *lower = zend_string_tolower(class_name);
    bool found = dd_perform_autoload(class_name, lower) != NULL;

    if (found) {
        zend_string_release(lower);
        return true;
    }

    bool autoloading = EG(in_autoload) && zend_hash_exists(EG(in_autoload), lower);
    zend_string_release(lower);

    // check whether we're actually autoloading
    if (autoloading) {
        if (dd_has_registered_spl_autoloader) {
            return false;
        }

        zend_function *func =
#if PHP_VERSION_ID >= 70300
            zend_fetch_function(ZSTR_KNOWN(ZEND_STR_MAGIC_AUTOLOAD))
#else
            zend_hash_str_find_ptr(EG(function_table), ZEND_AUTOLOAD_FUNC_NAME, sizeof(ZEND_AUTOLOAD_FUNC_NAME) - 1)
#endif
        ;
        if (func) {
            zval ret;
            zend_fcall_info fcall_info;
            zend_fcall_info_cache fcall_cache;

            fcall_info.size = sizeof(fcall_info);
            ZVAL_STR(&fcall_info.function_name, func->common.function_name);
            fcall_info.retval = &ret;
            fcall_info.param_count = 1;
            fcall_info.params = EX_VAR_NUM(0);
            fcall_info.object = NULL;
            fcall_info.no_separation = 1;
#if PHP_VERSION_ID < 70100
            fcall_info.symbol_table = NULL;
#endif

#if PHP_VERSION_ID < 70300
            fcall_cache.initialized = 1;
#endif
            fcall_cache.function_handler = func;
            fcall_cache.calling_scope = NULL;
            fcall_cache.called_scope = NULL;
            fcall_cache.object = NULL;

            zend_call_function(&fcall_info, &fcall_cache);
            zval_ptr_dtor(&ret);
        }

        // skip original implementation if there's no spl autoloader registered
        return true;
    }

    return false;
}

static ZEND_NAMED_FUNCTION(dd_wrap_autoload_register_fn) {
    dd_spl_autoload_register_fn_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    if (!EG(exception)) {
        dd_has_registered_spl_autoloader = true;
    }
}

static ZEND_NAMED_FUNCTION(dd_wrap_autoload_unregister_fn) {
    dd_spl_autoload_unregister_fn_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    ddtrace_autoload_rinit();
}

static ZEND_NAMED_FUNCTION(dd_perform_autoload_fn) {
    if (!dd_legacy_autoload_wrapper(INTERNAL_FUNCTION_PARAM_PASSTHRU)) {
        dd_spl_autoload_fn_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    }
}

static ZEND_NAMED_FUNCTION(dd_perform_autoload_call_fn) {
    if (!dd_legacy_autoload_wrapper(INTERNAL_FUNCTION_PARAM_PASSTHRU)) {
        dd_in_autoload = true; // spl_autoload_call may call spl_autoload
        dd_spl_autoload_call_fn_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        dd_in_autoload = false;
    }
}

static ZEND_NAMED_FUNCTION(dd_wrap_autoload_functions_fn) {
    if (!dd_has_registered_spl_autoloader) {
        EG(autoload_func) = dd_spl_autoload_fn;
    }

    dd_spl_autoload_functions_fn_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    EG(autoload_func) = dd_spl_autoload_call_fn;
}

void ddtrace_autoload_rinit(void) {
    if (!EG(autoload_func)) {
        dd_has_registered_spl_autoloader = false;
        EG(autoload_func) = dd_spl_autoload_call_fn;
    }
    dd_in_autoload = false;
}
#endif

void ddtrace_autoload_minit(void) {
#if PHP_VERSION_ID >= 80000
    dd_prev_autoloader = zend_autoload;
    zend_autoload = dd_perform_autoload;
#else
    dd_spl_autoload_fn = zend_hash_str_find_ptr(CG(function_table), "spl_autoload", sizeof("spl_autoload") - 1);
    dd_spl_autoload_fn_handler = dd_spl_autoload_fn->internal_function.handler;
    dd_spl_autoload_fn->internal_function.handler = dd_perform_autoload_fn;
    dd_spl_autoload_call_fn = zend_hash_str_find_ptr(CG(function_table), "spl_autoload_call", sizeof("spl_autoload_call") - 1);
    dd_spl_autoload_call_fn_handler = dd_spl_autoload_call_fn->internal_function.handler;
    dd_spl_autoload_call_fn->internal_function.handler = dd_perform_autoload_call_fn;
    zend_function *spl_autoload_unregister_fn = zend_hash_str_find_ptr(CG(function_table), "spl_autoload_unregister", sizeof("spl_autoload_unregister") - 1);
    dd_spl_autoload_unregister_fn_handler = spl_autoload_unregister_fn->internal_function.handler;
    spl_autoload_unregister_fn->internal_function.handler = dd_wrap_autoload_unregister_fn;
    zend_function *spl_autoload_register_fn = zend_hash_str_find_ptr(CG(function_table), "spl_autoload_register", sizeof("spl_autoload_register") - 1);
    dd_spl_autoload_register_fn_handler = spl_autoload_register_fn->internal_function.handler;
    spl_autoload_register_fn->internal_function.handler = dd_wrap_autoload_register_fn;
    zend_function *spl_autoload_functions_fn = zend_hash_str_find_ptr(CG(function_table), "spl_autoload_functions", sizeof("spl_autoload_functions") - 1);
    dd_spl_autoload_functions_fn_handler = spl_autoload_functions_fn->internal_function.handler;
    spl_autoload_functions_fn->internal_function.handler = dd_wrap_autoload_functions_fn;
#endif
}

void ddtrace_autoload_rshutdown(void) {
#if PHP_VERSION_ID >= 70400
    if (CG(compiler_options) & ZEND_COMPILE_PRELOAD) {
        dd_api_is_preloaded = DDTRACE_G(api_is_loaded);
        dd_otel_is_preloaded = DDTRACE_G(otel_is_loaded);
        dd_legacy_tracer_is_preloaded = DDTRACE_G(legacy_tracer_is_loaded);
    } else {
        DDTRACE_G(api_is_loaded) = dd_api_is_preloaded;
        DDTRACE_G(otel_is_loaded) = dd_otel_is_preloaded;
        DDTRACE_G(legacy_tracer_is_loaded) = dd_legacy_tracer_is_preloaded;
    }
#else
    DDTRACE_G(api_is_loaded) = 0;
    DDTRACE_G(otel_is_loaded) = 0;
#endif
}
