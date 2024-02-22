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
#include <components/log/log.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#if PHP_VERSION_ID < 80000
zif_handler dd_spl_autoload_fn_handler;
zif_handler dd_spl_autoload_call_fn_handler;
zif_handler dd_spl_autoload_unregister_fn_handler;
zend_function *dd_spl_autoload_fn;
#else
static zend_class_entry *(*dd_prev_autoloader)(zend_string *name, zend_string *lc_name);
#endif
#if PHP_VERSION_ID >= 70400
static zend_bool dd_api_is_preloaded = false;
static zend_bool dd_otel_is_preloaded = false;
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
    size_t filename_len = strlen(filename);
    if (filename_len == 0) {
        return FAILURE;
    }
    zval dummy;
    zend_file_handle file_handle;
    zend_op_array *new_op_array;
    ZVAL_UNDEF(result);
    int ret, rv = false;

    ddtrace_error_handling eh_stream;
    // Using an EH_THROW here causes a non-recoverable zend_bailout()
    ddtrace_backup_error_handling(&eh_stream, EH_NORMAL);
    zend_bool _original_cg_multibyte = CG(multibyte);
    CG(multibyte) = false;

#if PHP_VERSION_ID < 80100
    ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE);
#else
    zend_string *fn = zend_string_init(filename, filename_len, 0);
    zend_stream_init_filename_ex(&file_handle, fn);
    ret = php_stream_open_for_zend_ex(&file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE);
    zend_string_release(fn);
#endif

    LOGEV(Warn, {
        if (PG(last_error_message) && eh_stream.message != PG(last_error_message)) {
            log("Error raised while opening autoloaded file stream for %s: %s in %s on line %d", filename, LAST_ERROR_STRING, LAST_ERROR_FILE, PG(last_error_lineno));
        }
    })

    ddtrace_restore_error_handling(&eh_stream);

    if (!EG(exception) && ret == SUCCESS) {
        zend_string *opened_path;
        if (!file_handle.opened_path) {
            file_handle.opened_path = zend_string_init(filename, filename_len, 0);
        }
        opened_path = zend_string_copy(file_handle.opened_path);
        ZVAL_NULL(&dummy);

        if (zend_hash_add(&EG(included_files), opened_path, &dummy)) {
            new_op_array = zend_compile_file(&file_handle, ZEND_REQUIRE);
            zend_destroy_file_handle(&file_handle);
        } else {
            new_op_array = NULL;
#if PHP_VERSION_ID < 80100
            zend_file_handle_dtor(&file_handle);
#else
            zend_destroy_file_handle(&file_handle);
#endif
        }

        zend_string_release(opened_path);
        if (new_op_array) {

            ddtrace_error_handling eh;
            ddtrace_backup_error_handling(&eh, EH_THROW);

            zend_execute(new_op_array, result);

            LOGEV(Warn, {
                if (PG(last_error_message) && eh.message != PG(last_error_message)) {
                    log("Error raised in autoloaded file %s: %s in %s on line %d", filename, LAST_ERROR_STRING, LAST_ERROR_FILE, PG(last_error_lineno));
                }
            })

            ddtrace_restore_error_handling(&eh);

            destroy_op_array(new_op_array);
            efree(new_op_array);
            if (EG(exception)) {
                LOGEV(Warn, {
                    zend_object *ex = EG(exception);

                    const char *type = ex->ce->name->val;
                    const char *msg = instanceof_function(ex->ce, zend_ce_throwable) ? ZSTR_VAL(zai_exception_message(ex)): "<exit>";
                    log("%s thrown in autoloaded file %s: %s", filename, type, msg);
                })
            }
            ddtrace_maybe_clear_exception();
            rv = true;
        }
    } else {
        ddtrace_maybe_clear_exception();
        if (!try) {
            LOG(Warn, "Error opening autoloaded file %s", filename);
        } else {
            LOG(Trace, "Tried opening autloaded file path %s, but not readable or not found", filename);
        }
#if PHP_VERSION_ID >= 80100
        zend_destroy_file_handle(&file_handle);
#endif
    }
    CG(multibyte) = _original_cg_multibyte;

    return rv;
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
    if (!dd_execute_php_file(path, &result, true) && path_len > class_start + 7) {
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
    if (dd_execute_php_file(path, &result, false) && Z_TYPE(result) == IS_ARRAY) {
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
            if (DDTRACE_G(api_is_loaded)) {
                DDTRACE_G(api_is_loaded) = 1;
                dd_load_files("api");
                if ((ce = zend_hash_find_ptr(EG(class_table), lc_name))) {
                    return ce;
                }
            }
            if (!zend_string_starts_with_literal(lc_name, "ddtrace\\integration\\")) {
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
static inline bool dd_legacy_autoload_wrapper(INTERNAL_FUNCTION_PARAMETERS) {
    UNUSED(return_value);
    zend_string *class_name;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 1, 1)
        Z_PARAM_STR(class_name)
    ZEND_PARSE_PARAMETERS_END_EX(return false;);

    zend_string *lower = zend_string_tolower(class_name);
    bool found = dd_perform_autoload(class_name, lower) != NULL;
    zend_string_release(lower);

    return found;
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
        dd_spl_autoload_call_fn_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    }
}

void ddtrace_autoload_rinit(void) {
    if (!EG(autoload_func)) {
        EG(autoload_func) = dd_spl_autoload_fn;
    }
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
    zend_function *spl_autoload_call_fn = zend_hash_str_find_ptr(CG(function_table), "spl_autoload_call", sizeof("spl_autoload_call") - 1);
    dd_spl_autoload_call_fn_handler = spl_autoload_call_fn->internal_function.handler;
    spl_autoload_call_fn->internal_function.handler = dd_perform_autoload_call_fn;
    zend_function *spl_autoload_unregister_fn = zend_hash_str_find_ptr(CG(function_table), "spl_autoload_unregister", sizeof("spl_autoload_unregister") - 1);
    dd_spl_autoload_unregister_fn_handler = spl_autoload_unregister_fn->internal_function.handler;
    spl_autoload_unregister_fn->internal_function.handler = dd_wrap_autoload_unregister_fn;
#endif
}

void ddtrace_autoload_rshutdown(void) {
#if PHP_VERSION_ID >= 70400
    if (CG(compiler_options) & ZEND_COMPILE_PRELOAD) {
        dd_api_is_preloaded = DDTRACE_G(api_is_loaded);
        dd_otel_is_preloaded = DDTRACE_G(otel_is_loaded);
    } else {
        DDTRACE_G(api_is_loaded) = dd_api_is_preloaded;
        DDTRACE_G(otel_is_loaded) = dd_otel_is_preloaded;
    }
#else
    DDTRACE_G(api_is_loaded) = 0;
    DDTRACE_G(otel_is_loaded) = 0;
#endif
}
