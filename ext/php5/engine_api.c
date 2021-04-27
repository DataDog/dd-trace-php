#include "engine_api.h"

#include "engine_hooks.h"  // for ddtrace_backup_error_handling

int ddtrace_call_sandboxed_function(const char *name, size_t name_len, zval **retval, int argc,
                                    zval **argv[] TSRMLS_DC) {
    zval *fname;
    MAKE_STD_ZVAL(fname);
    ZVAL_STRINGL(fname, name, name_len, 1);
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    // Play it safe if an exception has not been handled yet
    if (EG(exception)) {
        return FAILURE;
    }

    ddtrace_error_handling eh;
    ddtrace_backup_error_handling(&eh, EH_SUPPRESS TSRMLS_CC);

    int result = zend_fcall_info_init(fname, IS_CALLABLE_CHECK_SILENT, &fci, &fcc, NULL, NULL TSRMLS_CC);
    if (result == SUCCESS) {
        fci.retval_ptr_ptr = retval;
        fci.params = argv;
        fci.no_separation = 0;  // allow for by-ref args
        fci.param_count = argc;
        result = zend_call_function(&fci, &fcc TSRMLS_CC);
    }

    ddtrace_restore_error_handling(&eh TSRMLS_CC);

    if (EG(exception)) {
        zend_clear_exception(TSRMLS_C);
    }

    zval_dtor(fname);
    efree(fname);
    return result;
}
