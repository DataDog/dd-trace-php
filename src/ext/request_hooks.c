#include "request_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_compile.h>
#include <php_main.h>
#include <string.h>

#include "ddtrace.h"
#include "ddtrace_string.h"
#include "engine_hooks.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

int dd_no_blacklisted_modules(TSRMLS_D) {
    zend_module_entry *module;
    int no_blacklisted_modules = 1;

    ddtrace_string blacklist = ddtrace_string_cstring_ctor(DDTRACE_G(internal_blacklisted_modules_list));
    if (!blacklist.len) {
        return no_blacklisted_modules;
    }

#if PHP_VERSION_ID < 70000
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&module_registry, &pos);

    while (zend_hash_get_current_data_ex(&module_registry, (void *)&module, &pos) != FAILURE) {
        if (!module || !module->name) {
            continue;
        }
        ddtrace_string module_name = ddtrace_string_cstring_ctor((char *)module->name);
        if (module_name.len && ddtrace_string_contains_in_csv(blacklist, module_name)) {
            ddtrace_log_debugf("Found blacklisted module: %s, disabling conflicting functionality", module->name);
            no_blacklisted_modules = 0;
            break;
        }
        zend_hash_move_forward_ex(&module_registry, &pos);
    }
#else
    ZEND_HASH_FOREACH_PTR(&module_registry, module) {
        if (!module) {
            continue;
        }
        ddtrace_string module_name = ddtrace_string_cstring_ctor((char *)module->name);
        if (module_name.len && ddtrace_string_contains_in_csv(blacklist, module_name)) {
            ddtrace_log_debugf("Found blacklisted module: %s, disabling conflicting functionality", module->name);
            no_blacklisted_modules = 0;
            break;
        }
    }
    ZEND_HASH_FOREACH_END();
#endif
    return no_blacklisted_modules;
}

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

    ddtrace_error_handling eh_stream;
    ddtrace_backup_error_handling(&eh_stream, EH_SUPPRESS TSRMLS_CC);

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
            }
            ddtrace_maybe_clear_exception(TSRMLS_C);
            rv = TRUE;
        }
    } else {
        ddtrace_log_debugf("Error opening request init hook: %s", filename);
    }

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

    ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE);

    if (get_dd_trace_debug() && PG(last_error_message) && eh_stream.message != PG(last_error_message)) {
        ddtrace_log_errf("Error raised while opening request-init-hook stream: %s in %s on line %d",
                         PG(last_error_message), PG(last_error_file), PG(last_error_lineno));
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
                ddtrace_log_errf("Error raised in request init hook: %s in %s on line %d", PG(last_error_message),
                                 PG(last_error_file), PG(last_error_lineno));
            }

            ddtrace_restore_error_handling(&eh);

            destroy_op_array(new_op_array);
            efree(new_op_array);
            if (!EG(exception)) {
                zval_ptr_dtor(&result);
            }
            ddtrace_maybe_clear_exception();
            rv = TRUE;
        }
    } else {
        ddtrace_maybe_clear_exception();
        ddtrace_log_debugf("Error opening request init hook: %s", filename);
    }

    return rv;
}
#endif
