#include "request_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_compile.h>
#include <php_main.h>
#include <string.h>

#include <ext/standard/php_filestat.h>

#include "ddtrace.h"
#include "engine_hooks.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#if PHP_VERSION_ID < 70000
int dd_execute_php_file(const char *filename TSRMLS_DC) {
    int filename_len = strlen(filename);
    if (filename_len == 0) {
        return FAILURE;
    }
    int dummy = 1;
    zend_file_handle file_handle;
    zend_op_array *new_op_array;
    zval *result = NULL;
    int ret;

    BOOL_T rv = FALSE;

    zval **original_return_value = EG(return_value_ptr_ptr);
    zend_op **original_opline_ptr = EG(opline_ptr);
    zend_op_array *original_active_op_array = EG(active_op_array);

    ddtrace_error_handling eh_stream;
    ddtrace_backup_error_handling(&eh_stream, EH_SUPPRESS TSRMLS_CC);
    zend_bool _original_cg_multibyte = CG(multibyte);
    CG(multibyte) = FALSE;

    ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE TSRMLS_CC);

    if (get_dd_trace_debug() && PG(last_error_message) && eh_stream.message != PG(last_error_message)) {
        ddtrace_log_errf("Error raised while opening request-init-hook stream: %s in %s on line %d",
                         PG(last_error_message), PG(last_error_file), PG(last_error_lineno));
    }

    ddtrace_restore_error_handling(&eh_stream TSRMLS_CC);

    if (ret == SUCCESS) {
        if (!file_handle.opened_path) {
            file_handle.opened_path = estrndup(filename, filename_len);
        }
        if (zend_hash_add(&EG(included_files), file_handle.opened_path, strlen(file_handle.opened_path) + 1,
                          (void *)&dummy, sizeof(int), NULL) == SUCCESS) {
            new_op_array = zend_compile_file(&file_handle, ZEND_REQUIRE TSRMLS_CC);
            zend_destroy_file_handle(&file_handle TSRMLS_CC);
        } else {
            new_op_array = NULL;
            zend_file_handle_dtor(&file_handle TSRMLS_CC);
        }
        if (new_op_array) {
            EG(return_value_ptr_ptr) = &result;
            EG(active_op_array) = new_op_array;
            if (!EG(active_symbol_table)) {
                zend_rebuild_symbol_table(TSRMLS_C);
            }

            ddtrace_error_handling eh;
            ddtrace_backup_error_handling(&eh, EH_SUPPRESS TSRMLS_CC);

            zend_try { zend_execute(new_op_array TSRMLS_CC); }
#if PHP_VERSION_ID < 50600
            // Cannot gracefully recover from fatal errors without crashing until PHP 5.6
            zend_catch {
                if (get_dd_trace_debug() && PG(last_error_message)) {
                    ddtrace_log_errf("Unrecoverable error raised in request init hook: %s in %s on line %d",
                                     PG(last_error_message), PG(last_error_file), PG(last_error_lineno));
                }
                zend_bailout();
            }
#endif
            zend_end_try();

            if (get_dd_trace_debug() && PG(last_error_message) && eh.message != PG(last_error_message)) {
                ddtrace_log_errf("Error raised in request init hook: %s in %s on line %d", PG(last_error_message),
                                 PG(last_error_file), PG(last_error_lineno));
            }

            ddtrace_restore_error_handling(&eh TSRMLS_CC);

            destroy_op_array(new_op_array TSRMLS_CC);
            efree(new_op_array);
            if (!EG(exception)) {
                if (EG(return_value_ptr_ptr) && *EG(return_value_ptr_ptr)) {
                    zval_ptr_dtor(EG(return_value_ptr_ptr));
                }
            } else {
                // Cannot use ddtrace_maybe_clear_exception() because it updates the opline to a dangling pointer
                zval_ptr_dtor(&EG(exception));
                EG(exception) = NULL;
                if (EG(prev_exception)) {
                    zval_ptr_dtor(&EG(prev_exception));
                    EG(prev_exception) = NULL;
                }
            }
            rv = TRUE;
        }
    } else {
        ddtrace_log_debugf("Error opening request init hook: %s", filename);
    }
    CG(multibyte) = _original_cg_multibyte;

    EG(return_value_ptr_ptr) = original_return_value;
    EG(opline_ptr) = original_opline_ptr;
    EG(active_op_array) = original_active_op_array;
    return rv;
}
#else

int dd_execute_php_file(const char *filename TSRMLS_DC) {
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

    ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE);

    if (get_dd_trace_debug() && PG(last_error_message) && eh_stream.message != PG(last_error_message)) {
        char *error;
#if PHP_VERSION_ID < 80000
        error = PG(last_error_message);
#else
        error = ZSTR_VAL(PG(last_error_message));
#endif
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
            zend_file_handle_dtor(&file_handle);
        }

        zend_string_release(opened_path);
        if (new_op_array) {
            ZVAL_UNDEF(&result);

            ddtrace_error_handling eh;
            ddtrace_backup_error_handling(&eh, EH_THROW);

            zend_execute(new_op_array, &result);

            if (get_dd_trace_debug() && PG(last_error_message) && eh.message != PG(last_error_message)) {
                char *error;
#if PHP_VERSION_ID < 80000
                error = PG(last_error_message);
#else
                error = ZSTR_VAL(PG(last_error_message));
#endif
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
                zval rv, obj;
                ZVAL_OBJ(&obj, ex);
                zval *message = GET_PROPERTY(&obj, ZEND_STR_MESSAGE);
                const char *msg = Z_TYPE_P(message) == IS_STRING ? Z_STR_P(message)->val
                                                                 : "(internal error reading exception message)";
                ddtrace_log_errf("%s thrown in request init hook: %s", type, msg);
                if (message == &rv) {
                    zval_dtor(message);
                }
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
#endif

int dd_execute_auto_prepend_file(char *auto_prepend_file TSRMLS_DC) {
    zend_file_handle prepend_file;
    // We could technically do this to synthetically adjust the stack
    // zend_execute_data *ex = EG(current_execute_data);
    // EG(current_execute_data) = ex->prev_execute_data;
    memset(&prepend_file, 0, sizeof(zend_file_handle));
    prepend_file.type = ZEND_HANDLE_FILENAME;
    prepend_file.filename = auto_prepend_file;
    int ret = zend_execute_scripts(ZEND_REQUIRE TSRMLS_CC, NULL, 1, &prepend_file) == SUCCESS;
    // EG(current_execute_data) = ex;
    return ret;
}

void dd_request_init_hook_rinit(TSRMLS_D) {
    DDTRACE_G(auto_prepend_file) = PG(auto_prepend_file);
    if (php_check_open_basedir_ex(DDTRACE_G(request_init_hook), 0 TSRMLS_CC) == -1) {
        ddtrace_log_debugf("open_basedir restriction in effect; cannot open request init hook: '%s'",
                           DDTRACE_G(request_init_hook));
        return;
    }

    zval exists_flag;
    php_stat(DDTRACE_G(request_init_hook), strlen(DDTRACE_G(request_init_hook)), FS_EXISTS, &exists_flag TSRMLS_CC);
#if PHP_VERSION_ID < 70000
    if (!Z_BVAL(exists_flag)) {
#else
    if (Z_TYPE(exists_flag) == IS_FALSE) {
#endif
        ddtrace_log_debugf("Cannot open request init hook; file does not exist: '%s'", DDTRACE_G(request_init_hook));
        return;
    }

    PG(auto_prepend_file) = DDTRACE_G(request_init_hook);
    if (DDTRACE_G(auto_prepend_file) && DDTRACE_G(auto_prepend_file)[0]) {
        ddtrace_log_debugf("Backing up auto_prepend_file '%s'", DDTRACE_G(auto_prepend_file));
    }
}

void dd_request_init_hook_rshutdown(TSRMLS_D) { PG(auto_prepend_file) = DDTRACE_G(auto_prepend_file); }
