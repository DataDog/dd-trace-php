#include "request_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_compile.h>
#include <exceptions/exceptions.h>
#include <php_main.h>
#include <string.h>

#include <ext/standard/php_filestat.h>

#include "ddtrace.h"
#include "engine_hooks.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

int dd_execute_php_file(const char *filename) {
    int filename_len = strlen(filename);
    if (filename_len == 0) {
        return FAILURE;
    }
    zval dummy;
    zend_file_handle file_handle;
    zend_op_array *new_op_array;
    zval result;
    int ret, rv = FALSE;

    ddtrace_error_handling eh_stream;
    // Using an EH_THROW here causes a non-recoverable zend_bailout()
    ddtrace_backup_error_handling(&eh_stream, EH_NORMAL);
    zend_bool _original_cg_multibyte = CG(multibyte);
    CG(multibyte) = FALSE;

#if PHP_VERSION_ID < 80100
    ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE);
#else
    {
    zend_string *fn = zend_string_init(filename, filename_len, 0);
    zend_stream_init_filename_ex(&file_handle, fn);
    ret = php_stream_open_for_zend_ex( &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE);
    zend_string_release(fn);
    }
#endif

    if (get_dd_trace_debug() && PG(last_error_message) && eh_stream.message != PG(last_error_message)) {
        char *error;
        error = ZSTR_VAL(PG(last_error_message));
        ddtrace_log_errf("Error raised while opening request-init-hook stream: %s in %s on line %d", error,
                         PG(last_error_file), PG(last_error_lineno));
    }

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
            ZVAL_UNDEF(&result);

            ddtrace_error_handling eh;
            ddtrace_backup_error_handling(&eh, EH_THROW);

            zend_execute(new_op_array, &result);

            if (get_dd_trace_debug() && PG(last_error_message) && eh.message != PG(last_error_message)) {
                char *error;
                error = ZSTR_VAL(PG(last_error_message));
                ddtrace_log_errf("Error raised in request init hook: %s in %s on line %d", error, PG(last_error_file),
                                 PG(last_error_lineno));
            }

            ddtrace_restore_error_handling(&eh);

            destroy_op_array(new_op_array);
            efree(new_op_array);
            if (!EG(exception)) {
                zval_ptr_dtor(&result);
            } else if (get_dd_trace_debug()) {
                zend_object *ex = EG(exception);

                const char *type = ex->ce->name->val;
                zend_string *msg = zai_exception_message(ex);
                ddtrace_log_errf("%s thrown in request init hook: %s", type, ZSTR_VAL(msg));
            }
            ddtrace_maybe_clear_exception();
            rv = TRUE;
        }
    } else {
        ddtrace_maybe_clear_exception();
        ddtrace_log_debugf("Error opening request init hook: %s", filename);
    }
    CG(multibyte) = _original_cg_multibyte;

    return rv;
}

int dd_execute_auto_prepend_file(char *auto_prepend_file) {
    zend_file_handle prepend_file;
    // We could technically do this to synthetically adjust the stack
    // zend_execute_data *ex = EG(current_execute_data);
    // EG(current_execute_data) = ex->prev_execute_data;
    memset(&prepend_file, 0, sizeof(zend_file_handle));
    prepend_file.type = ZEND_HANDLE_FILENAME;
#if PHP_VERSION_ID < 80100
    prepend_file.filename = auto_prepend_file;
    int ret = zend_execute_scripts(ZEND_REQUIRE, NULL, 1, &prepend_file) == SUCCESS;
#else
    prepend_file.filename = zend_string_init(auto_prepend_file, strlen(auto_prepend_file), 0);
    int ret = zend_execute_scripts(ZEND_REQUIRE, NULL, 1, &prepend_file) == SUCCESS;
    zend_string_release(prepend_file.filename);
#endif
    // Exit no longer calls zend_bailout in PHP 8, so we need to "rethrow" the exit
    if (ret == 0) {
        zend_throw_unwind_exit();
    }
    // EG(current_execute_data) = ex;
    return ret;
}

void dd_request_init_hook_rinit(void) {
    DDTRACE_G(auto_prepend_file) = PG(auto_prepend_file);
    if (php_check_open_basedir_ex(DDTRACE_G(request_init_hook), 0) == -1) {
        ddtrace_log_debugf("open_basedir restriction in effect; cannot open request init hook: '%s'",
                           DDTRACE_G(request_init_hook));
        return;
    }

    zval exists_flag;
#if PHP_VERSION_ID < 8010
    php_stat(DDTRACE_G(request_init_hook), strlen(DDTRACE_G(request_init_hook)), FS_EXISTS, &exists_flag);
#else
    {
    zend_string *hook = zend_string_init(DDTRACE_G(request_init_hook), strlen(DDTRACE_G(request_init_hook)), 0);
    php_stat(hook, FS_EXISTS, &exists_flag);
    zend_string_release(hook);
    }
#endif
    if (Z_TYPE(exists_flag) == IS_FALSE) {
        ddtrace_log_debugf("Cannot open request init hook; file does not exist: '%s'", DDTRACE_G(request_init_hook));
        return;
    }

    PG(auto_prepend_file) = DDTRACE_G(request_init_hook);
    if (DDTRACE_G(auto_prepend_file) && DDTRACE_G(auto_prepend_file)[0]) {
        ddtrace_log_debugf("Backing up auto_prepend_file '%s'", DDTRACE_G(auto_prepend_file));
    }
}

void dd_request_init_hook_rshutdown(void) { PG(auto_prepend_file) = DDTRACE_G(auto_prepend_file); }
